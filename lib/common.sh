# Shared command infrastructure: strict shell mode, dependency checks,
# exit-code conventions, error helpers.
#
# Source after root.sh; requires REPO_ROOT.

# --- Exit codes (global convention, 02-developer-tooling.md §3) ---
# 0 = success
# 1 = check/gate failure
# 2 = usage / configuration error
# 3 = required dependency unavailable
# 4 = environment / bootstrap failure
# 5 = baseline/policy failure (e.g. expired exception allowlist entry)
readonly EX_OK=0
readonly EX_CHECK=1
readonly EX_USAGE=2
readonly EX_DEP=3
readonly EX_ENV=4
readonly EX_POLICY=5

set -Eeuo pipefail

# require_cmd <name> — exit 3 if a required command is missing.
require_cmd() {
  local name="$1"
  if ! command -v "$name" >/dev/null 2>&1; then
    echo "ERROR: required command '${name}' not found" >&2
    echo "  control:   $0" >&2
    echo "  resource:  ${name}" >&2
    echo "  remediation: install ${name} or ensure it is on PATH" >&2
    exit "$EX_DEP"
  fi
}

# fail <message> — print an actionable error and exit with code 2.
fail() {
  echo "ERROR: $*" >&2
  exit "$EX_USAGE"
}

# require_root_dir — validate that REPO_ROOT looks like this repository.
require_root_dir() {
  if [[ -z "${REPO_ROOT:-}" ]]; then
    echo "ERROR: REPO_ROOT not set — source lib/root.sh first" >&2
    exit "$EX_ENV"
  fi
  if [[ ! -f "$REPO_ROOT/composer.json" ]]; then
    echo "ERROR: ${REPO_ROOT} does not look like the repository root (no composer.json)" >&2
    exit "$EX_ENV"
  fi
}

# die_on_failure <message> — preserve the last command's exit code.
die_on_failure() {
  local status=$?
  if [[ $status -ne 0 ]]; then
    echo "ERROR: $* (exit ${status})" >&2
  fi
  exit "$status"
}

# trap ERR is optional per-command; keep helpers opt-in to avoid surprises.