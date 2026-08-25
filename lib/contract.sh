# Canonical CLI contract (02-developer-tooling.md §3).
#
# Flags:
#   --format=text|json|github   (--json is a documented alias of --format=json)
#   levels: --quick | --full | --ci
#   --verbose --quiet --help
#
# Source after common.sh. Provides:
#   contract_parse_args "$@"   -> sets FORMAT, LEVEL, VERBOSE, QUIET, HELP, POSITIONAL
#   report_result <control> <passed|failed|skipped> <message>
#   report_finish <exit_code>  -> emits text summary or JSON/GitHub payload, exits

FORMAT="text"
LEVEL=""
VERBOSE=0
QUIET=0
HELP=0
OFFLINE=0
RESUME=0
POSITIONAL=()

__CONTRACT_RESULTS=()

contract_parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --format=json|--json) FORMAT="json"; shift ;;
      --format=text|--format=text) FORMAT="text"; shift ;;
      --format=github) FORMAT="github"; shift ;;
      --format)
        [[ $# -lt 2 ]] && fail "--format requires a value: text|json|github"
        case "$2" in text|json|github) FORMAT="$2" ;; *) fail "Invalid --format '$2' (expected text|json|github)" ;; esac
        shift 2
        ;;
      --quick|--full|--ci)
        [[ -n "$LEVEL" && "$LEVEL" != "${1#--}" ]] && fail "Only one level flag allowed (--quick|--full|--ci)"
        LEVEL="${1#--}"
        shift
        ;;
      --offline) OFFLINE=1; shift ;;
      --resume) RESUME=1; shift ;;
      --verbose) VERBOSE=1; shift ;;
      --quiet) QUIET=1; shift ;;
      --help|-h) HELP=1; shift ;;
      --*) fail "Unknown option '$1' (see --help)" ;;
      *) POSITIONAL+=("$1"); shift ;;
    esac
  done
}

# contract_exec <control> <command...> — run a tool under the baseline timeout.
# A timeout is a failure, not success (02-developer-tooling.md §36).
# Shell functions are executed directly; the external timeout binary cannot
# wrap them.
contract_exec() {
  local control="$1"; shift
  if declare -F "$1" >/dev/null 2>&1; then
    "$@"
    return
  fi
  if command -v timeout >/dev/null 2>&1; then
    timeout "${BASELINE_TOOL_TIMEOUT:-600}" "$@"
  else
    "$@"
  fi
}

contract_usage() {
  cat <<'USAGE'
Options:
  --format=text|json|github   Output format (--json is an alias for --format=json)
  --quick                     Fast validation for interactive development
  --full                      Complete local validation
  --ci                        CI-equivalent mode (full + dependency audits)
  --offline                   Skip controls that require network access
  --resume                    Reuse controls that passed in the previous run (02 §38)
  --verbose                   Show tool output and details
  --quiet                     Only errors and the final verdict
  --help                      Show this help

Environment:
  BASELINE_TOOL_TIMEOUT       Per-tool timeout in seconds (default: 600)
  BASELINE_MAX_JOBS           Max controls running in parallel (default: nproc, 1-16)
  BASELINE_CACHE              Set to 1 to cache passing control results (02 §34)
  BASELINE_CONTROL_BUDGET     Default wall-clock budget per control, seconds (02 §45)
  BASELINE_BUDGET_<ID>        Per-control budget override (e.g. BASELINE_BUDGET_TESTS=300)
USAGE
}

# report_result <control> <passed|failed|skipped> <message> [duration_ms]
report_result() {
  local control="$1" status="$2" message="${3:-}" duration="${4:-}"
  local extra=""
  [[ "$duration" =~ ^[0-9]+$ ]] && extra=",\"duration_ms\":$duration"
  __CONTRACT_RESULTS+=("$(printf '%s' "{\"control\":\"${control//\"/\\\"}\",\"status\":\"${status}\",\"message\":\"${message//\"/\\\"}\"${extra}}")")
  if [[ "$FORMAT" == "text" ]]; then
    case "$status" in
      passed)  (( QUIET )) || ok "$control passed" ;;
      failed)  err "$control failed${message:+ — $message}" ;;
      skipped) (( QUIET )) || say "$control skipped${message:+ — $message}" ;;
    esac
  elif [[ "$FORMAT" == "github" && "$status" == "failed" ]]; then
    printf '::error title=%s::%s\n' "$control" "${message:-check failed}"
  fi
}

# report_finish <exit_code> [summary_line]
report_finish() {
  local code="$1" summary="${2:-}"
  if [[ "$FORMAT" == "json" ]]; then
    printf '{"exit_code":%d,"results":[%s]}\n' "$code" "$(IFS=,; echo "${__CONTRACT_RESULTS[*]:-}")"
  fi
  exit "$code"
}
