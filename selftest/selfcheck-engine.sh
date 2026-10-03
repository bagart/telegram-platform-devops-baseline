#!/usr/bin/env bash
# Runs baseline self-checks and writes a verdict file readable from Windows.
set -uo pipefail
BASELINE_DIR="${BASELINE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export BASELINE_DIR
# Profile evidence (composer deps + .baseline-profiles.json) lives in the
# CONSUMER repo, which is the invocation cwd — capture it before cd'ing
# into the package (the package itself only requires php and can never
# demonstrate telegram/async-runtime implication).
CONSUMER_ROOT="$PWD"
cd "$BASELINE_DIR"
OUT=".cache/baseline/selfcheck.txt"
mkdir -p .cache/baseline
: >"$OUT"

# 1) Cycle detection must terminate with usage error.
cat >.cache/baseline/cycle-case.sh <<'EOS'
set -euo pipefail
export REPO_ROOT="$BASELINE_DIR"
source lib/common.sh; source lib/output.sh; source lib/contract.sh; source lib/engine.sh
FORMAT=text QUIET=0 RESUME=0 VERBOSE=0
engine_register x y true
engine_register y x true
engine_execute >/dev/null 2>&1
EOS
chmod +x .cache/baseline/cycle-case.sh
bash .cache/baseline/cycle-case.sh
printf 'cycle-detect: %s\n' "$([[ $? -eq 2 ]] && echo OK || echo FAIL)" >>"$OUT"

# 2) Resume: journal records statuses; resume run re-uses passed controls.
cat >.cache/baseline/resume-case.sh <<'EOS'
set -euo pipefail
export REPO_ROOT="$BASELINE_DIR"
source lib/common.sh; source lib/output.sh; source lib/contract.sh; source lib/engine.sh
FORMAT=text QUIET=1 RESUME="${RESUME:-0}" VERBOSE=0
ctl_ok() { return 0; }
ctl_bad() { return 1; }
engine_register ok1 "" ctl_ok
engine_register bad1 "" ctl_bad
engine_execute >/dev/null 2>&1
EOS
chmod +x .cache/baseline/resume-case.sh
RESUME=0 bash .cache/baseline/resume-case.sh
first_rc=$?
journal="$(tr '\n' ';' <.cache/baseline/last-run.journal 2>/dev/null)"
printf 'first-run rc=%s journal=[%s]\n' "$first_rc" "$journal" >>"$OUT"
RESUME=1 bash .cache/baseline/resume-case.sh
second_journal="$(tr '\n' ';' <.cache/baseline/last-run.journal 2>/dev/null)"
printf 'resumed-run journal=[%s]\n' "$second_journal" >>"$OUT"
if grep -q 'ok1	passed' <<<"$second_journal" && grep -q 'bad1	failed' <<<"$second_journal"; then
  echo resume-journal: OK >>"$OUT"
else
  echo resume-journal: FAIL >>"$OUT"
fi

# 3) Budget enforcement: passing-but-over-budget control fails.
cat >.cache/baseline/budget-case.sh <<'EOS'
set -euo pipefail
export REPO_ROOT="$BASELINE_DIR"
source lib/common.sh; source lib/output.sh; source lib/contract.sh; source lib/engine.sh
FORMAT=text QUIET=1 RESUME=0 VERBOSE=0
ctl_slow() { sleep 0.4; }
engine_register okslow "" ctl_slow
engine_execute >/dev/null 2>&1
EOS
chmod +x .cache/baseline/budget-case.sh
BASELINE_BUDGET_OKSLOW=0 BASELINE_CONTROL_BUDGET=0 bash .cache/baseline/budget-case.sh
printf 'budget-enforce: %s\n' "$([[ $? -eq 1 ]] && echo OK || echo FAIL)" >>"$OUT"

# 4) Max jobs clamp.
bash -c '
cd "'"$PWD"'" && export REPO_ROOT="$PWD"
source lib/common.sh; source lib/output.sh; source lib/contract.sh; source lib/engine.sh
[[ "$ENGINE_MAX_JOBS" == "16" ]] && echo maxjobs-clamp: OK || echo maxjobs-clamp: FAIL
' >>"$OUT" 2>&1

# 5) Profile composition (10 §6): --with-implied must keep detection stable,
#    emit valid JSON and imply async-runtime + laravel from telegram.
#    Run detection from the consumer root: profiles.sh resolves its repo via
#    git/PWD, and profile evidence is a consumer-repo concern.
profiles="$(cd "$CONSUMER_ROOT" && bash "$BASELINE_DIR/controls/profiles.sh" 2>/dev/null || true)"
implied="$(cd "$CONSUMER_ROOT" && bash "$BASELINE_DIR/controls/profiles.sh" --with-implied 2>/dev/null || true)"
json="$(cd "$CONSUMER_ROOT" && bash "$BASELINE_DIR/controls/profiles.sh" --with-implied --format=json 2>/dev/null || true)"
json_ok=0
php -r '$d=json_decode($argv[1],true); exit(isset($d["profiles"])&&is_array($d["profiles"])?0:1);' "$json" && json_ok=1
if grep -qw telegram <<<"$implied" \
  && grep -qw async-runtime <<<"$implied" \
  && grep -qw laravel <<<"$implied" \
  && [[ "$json_ok" -eq 1 ]]; then
  printf 'profiles-compose: OK\n' >>"$OUT"
else
  printf 'profiles-compose: FAIL (detected=[%s] implied=[%s] json=%s)\n' "$profiles" "$implied" "$json" >>"$OUT"
fi

rm -f .cache/baseline/cycle-case.sh .cache/baseline/resume-case.sh .cache/baseline/budget-case.sh
echo DONE >>"$OUT"
