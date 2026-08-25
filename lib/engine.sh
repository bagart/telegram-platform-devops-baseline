# Control execution engine (02-developer-tooling.md §15-§16).
#
# Controls are registered with declared dependencies and executed in
# topological waves. Independent controls within a wave run in parallel,
# bounded by BASELINE_MAX_JOBS (02 §35 resource limits); results are
# aggregated in registration order so output is deterministic regardless
# of completion order. A failed control skips its dependents.
#
# Additional capabilities (02 §34, §38, §45):
#   BASELINE_CACHE=1        opt-in result caching for passing controls;
#                           cache key includes HEAD + working-tree state
#   RESUME=1 (--resume)     reuse passed controls recorded in the last-run
#                           journal instead of re-executing them
#   BASELINE_CONTROL_BUDGET / BASELINE_BUDGET_<ID>
#                           wall-clock budget per control in seconds; a
#                           passing control over budget fails with a
#                           "budget exceeded" verdict
#
# Requires: common.sh (exit codes), output.sh, contract.sh (FORMAT/QUIET/
# VERBOSE/RESUME, report_result, contract_exec) sourced beforehand, and
# REPO_ROOT for the cache directory.
#
# Interface:
#   engine_register <id> <deps-csv> <command...>
#       Register a control. <deps-csv> is a comma-separated list of ids that
#       must pass first ("" for none). The command may be a shell function
#       (the baseline timeout cannot wrap functions).
#   engine_execute
#       Run all registered controls; returns non-zero if any failed or was
#       skipped due to a failed dependency.

ENGINE_IDS=()
ENGINE_DEPS=()
ENGINE_CMDS=()
ENGINE_FAILED_SET=""
ENGINE_FAILED_COUNT=0

ENGINE_MAX_JOBS="${BASELINE_MAX_JOBS:-$(nproc 2>/dev/null || echo 4)}"
[[ "$ENGINE_MAX_JOBS" =~ ^[0-9]+$ ]] || ENGINE_MAX_JOBS=4
(( ENGINE_MAX_JOBS < 1 )) && ENGINE_MAX_JOBS=1
(( ENGINE_MAX_JOBS > 16 )) && ENGINE_MAX_JOBS=16

__engine_cache_dir() {
  printf '%s/.cache/baseline' "${REPO_ROOT:-$PWD}"
}

__engine_now_ms() {
  if [[ -n "${EPOCHREALTIME:-}" ]]; then
    printf '%s' "${EPOCHREALTIME/./}"
  else
    date +%s%3N 2>/dev/null || date +%s000
  fi
}

# Working-tree fingerprint for cache keys: HEAD + dirty-state digest.
__engine_state_hash() {
  local head dirty
  head="$(git rev-parse HEAD 2>/dev/null || echo no-head)"
  dirty="$(git status --porcelain=v1 2>/dev/null | git hash-object --stdin 2>/dev/null || echo no-dirty)"
  printf '%s|%s' "$head" "$dirty"
}

__engine_budget_for() {
  local id="$1" var
  var="BASELINE_BUDGET_$(printf '%s' "$id" | tr '[:lower:] -' '[:upper:]_')"
  if [[ -n "${!var:-}" ]]; then
    printf '%s' "${!var}"
  else
    printf '%s' "${BASELINE_CONTROL_BUDGET:-}"
  fi
}

engine_register() {
  local id="$1" deps="$2"
  shift 2
  local IFS=$'\x1f'
  ENGINE_IDS+=("$id")
  ENGINE_DEPS+=("$deps")
  ENGINE_CMDS+=("$*")
}

# Split a stored command back into ARGS_ARRAY.
__engine_cmd_args() {
  local raw="$1"
  ARGS_ARRAY=()
  [[ -z "$raw" ]] && return 0
  local args=()
  IFS=$'\x1f' read -r -a args <<<"$raw" || true
  ARGS_ARRAY=("${args[@]}")
}

# Compute topological waves (__WAVES as comma-joined id strings).
# Exits with usage error on unknown dependencies or cycles.
__engine_waves() {
  local -A deps_of=()
  local i id dep
  for i in "${!ENGINE_IDS[@]}"; do
    id="${ENGINE_IDS[$i]}"
    [[ -n "${deps_of[$id]+x}" ]] && fail "Duplicate control id '$id'"
    deps_of["$id"]="${ENGINE_DEPS[$i]}"
  done

  for id in "${ENGINE_IDS[@]}"; do
    IFS=',' read -r -a dep_list <<<"${deps_of[$id]}"
    for dep in "${dep_list[@]}"; do
      [[ -z "$dep" ]] && continue
      [[ -n "${deps_of[$dep]+x}" ]] || fail "Control '$id' depends on unknown control '$dep'"
    done
  done

  __WAVES=()
  local -A done_set=()
  local pending=("${ENGINE_IDS[@]}")

  while (( ${#pending[@]} > 0 )); do
    local wave=() next=()
    for id in "${pending[@]}"; do
      local ready=1
      IFS=',' read -r -a dep_list <<<"${deps_of[$id]}"
      for dep in "${dep_list[@]}"; do
        [[ -z "$dep" ]] && continue
        [[ -n "${done_set[$dep]+x}" ]] || ready=0
      done
      (( ready )) && wave+=("$id")
    done
    if (( ${#wave[@]} == 0 )); then
      fail "Dependency cycle between controls: ${pending[*]}"
    fi
    for id in "${wave[@]}"; do
      done_set["$id"]=1
    done
    for id in "${pending[@]}"; do
      [[ -n "${done_set[$id]+x}" ]] || next+=("$id")
    done
    __WAVES+=("$(printf '%s,' "${wave[@]}" | sed 's/,$//')")
    pending=("${next[@]}")
  done
}

# Load passed-control ids from the previous run journal into ENGINE_RESUME_PASS.
__engine_load_resume_pass() {
  ENGINE_RESUME_PASS=""
  local journal id status
  journal="$(__engine_cache_dir)/last-run.journal"
  [[ -f "$journal" ]] || return 0
  while IFS=$'\t' read -r id status; do
    [[ "$status" == "passed" ]] && ENGINE_RESUME_PASS+=" $id "
  done <"$journal"
}

__engine_cache_path() {
  printf '%s/%s' "$(__engine_cache_dir)" "$(printf '%s' "$1" | sha1sum | cut -d' ' -f1)"
}

__engine_journal_write() {
  mkdir -p "$(__engine_cache_dir)"
  printf '%s\t%s\n' "$1" "$2" >>"$(__engine_cache_dir)/last-run.journal"
}

# Opt-in operational telemetry (11 §64): one NDJSON event per finished
# control, consumed by controls/telemetry.php --summary.
__engine_telemetry_write() {
  [[ "${BASELINE_TELEMETRY:-0}" == "1" ]] || return 0
  local control="$1" status="$2" duration_ms="$3" ts
  ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  mkdir -p "$(__engine_cache_dir)"
  printf '{"ts":"%s","control":"%s","status":"%s","duration_ms":%s}\n' \
    "$ts" "$(printf '%s' "$control" | tr -d '"')" "$status" "${duration_ms:-0}" \
    >>"$(__engine_cache_dir)/telemetry.jsonl"
}

engine_execute() {
  local -A idx=()
  local -A START_TS=()
  local i id
  for i in "${!ENGINE_IDS[@]}"; do
    idx["${ENGINE_IDS[$i]}"]="$i"
  done

  __engine_waves

  mkdir -p "$(__engine_cache_dir)"
  local journal
  journal="$(__engine_cache_dir)/last-run.journal"
  if [[ "$RESUME" -eq 1 ]]; then
    __engine_load_resume_pass
  else
    rm -f "$journal"
    ENGINE_RESUME_PASS=""
  fi

  local state_hash=""
  [[ "${BASELINE_CACHE:-0}" == "1" ]] && state_hash="$(__engine_state_hash)"

  local tmpdir
  tmpdir="$(mktemp -d)"
  # Value is embedded: the trap fires after locals are gone.
  trap "rm -rf '${tmpdir}'" EXIT

  local waveno wave id dep blocked msg status out st duration budget cache_key cache_verdict
  for waveno in "${!__WAVES[@]}"; do
    local wave=()
    IFS=',' read -r -a wave <<<"${__WAVES[$waveno]}"

    # Announce the whole wave up-front, in registration order.
    if [[ "$FORMAT" == "text" && "$QUIET" -eq 0 ]]; then
      for id in "${wave[@]}"; do
        say "$id"
      done
    fi

    local -a running_pids=() queued=()
    for id in "${wave[@]}"; do
      blocked=""
      IFS=',' read -r -a dep_list <<<"${ENGINE_DEPS[${idx[$id]}]}"
      for dep in "${dep_list[@]}"; do
        [[ -z "$dep" ]] && continue
        [[ " $ENGINE_FAILED_SET " == *" $dep "* ]] && blocked="$dep"
      done
      if [[ -n "$blocked" ]]; then
        ENGINE_FAILED_SET+=" $id"
        ENGINE_FAILED_COUNT=$((ENGINE_FAILED_COUNT + 1))
        report_result "$id" skipped "dependency '$blocked' failed" ""
        continue
      fi
      if [[ "$RESUME" -eq 1 && " $ENGINE_RESUME_PASS " == *" $id "* ]]; then
        report_result "$id" skipped "resumed (passed in previous run)" ""
        __engine_journal_write "$id" passed
        continue
      fi

      if [[ -n "$state_hash" ]]; then
        cache_key="$(printf '%s|%s|%s' "$state_hash" "$id" "${ENGINE_CMDS[${idx[$id]}]}")"
        cache_verdict="$(cat "$(__engine_cache_path "$cache_key")" 2>/dev/null || true)"
        if [[ "$cache_verdict" == "passed" ]]; then
          report_result "$id" passed "cached result" ""
          __engine_journal_write "$id" passed
          continue
        fi
      fi

      queued+=("$id")
    done

    # Launch queue respecting BASELINE_MAX_JOBS.
    local q
    for q in "${queued[@]}"; do
      i="${idx[$q]}"
      out="$tmpdir/$i.out"
      st="$tmpdir/$i.status"
      (
        ARGS_ARRAY=()
        __engine_cmd_args "${ENGINE_CMDS[$i]}"
        if contract_exec "$q" ${ARGS_ARRAY+"${ARGS_ARRAY[@]}"} >"$out" 2>&1; then
          echo passed >"$st"
        else
          echo failed >"$st"
        fi
      ) &
      START_TS[$i]="$(__engine_now_ms)"
      running_pids+=($!)
      if (( ${#running_pids[@]} >= ENGINE_MAX_JOBS )); then
        wait "${running_pids[0]}" || true
        running_pids=("${running_pids[@]:1}")
      fi
    done
    for i in "${running_pids[@]}"; do
      wait "$i" || true
    done

    # Aggregate deterministically: registration order within this wave.
    for id in "${wave[@]}"; do
      # Skipped/cached controls were already reported during launch prep.
      [[ -n "${START_TS[${idx[$id]}]+x}" ]] || continue
      i="${idx[$id]}"
      status="$(cat "$tmpdir/$i.status" 2>/dev/null || echo failed)"
      duration=$(( $(__engine_now_ms) - ${START_TS[$i]} ))
      (( duration < 0 )) && duration=0
      budget="$(__engine_budget_for "$id")"
      msg=""
      if [[ "$status" == "passed" && -n "$budget" && "$budget" =~ ^[0-9]+$ ]] \
         && (( duration > budget * 1000 )); then
        status="failed"
        msg="budget exceeded: ${duration}ms > ${budget}s budget"
      fi
      if [[ "$status" == "passed" ]]; then
        report_result "$id" passed "" "$duration"
        __engine_journal_write "$id" passed
        __engine_telemetry_write "$id" passed "$duration"
        if [[ -n "$state_hash" ]]; then
          cache_key="$(printf '%s|%s|%s' "$state_hash" "$id" "${ENGINE_CMDS[$i]}")"
          printf 'passed' >"$(__engine_cache_path "$cache_key")"
        fi
      else
        [[ -z "$msg" && -s "$tmpdir/$i.out" ]] && msg="$(tail -n 3 "$tmpdir/$i.out" | tr '\n' ' ' | cut -c1-200)"
        report_result "$id" failed "$msg" "$duration"
        __engine_journal_write "$id" failed
        __engine_telemetry_write "$id" failed "$duration"
        if [[ "$VERBOSE" -eq 1 && -s "$tmpdir/$i.out" ]]; then
          {
            echo "--- $id output ---"
            cat "$tmpdir/$i.out"
          } >&2
        fi
        ENGINE_FAILED_SET+=" $id"
        ENGINE_FAILED_COUNT=$((ENGINE_FAILED_COUNT + 1))
      fi
      rm -f "$tmpdir/$i.out" "$tmpdir/$i.status"
    done
  done

  return "$(( ENGINE_FAILED_COUNT > 0 ? 1 : 0 ))"
}
