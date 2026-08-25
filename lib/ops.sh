#!/usr/bin/env bash
# Ops correlation helper (06-runtime-operations.md §60–§62).
#
# Every ops command that mutates state or aggregates diagnostics stamps its
# run with a correlation id. The id propagates from the caller (CI job id,
# incident ticket) via OPS_CORRELATION_ID or is generated locally, and is
# appended to storage/logs/ops-correlation.log so outputs can be matched
# across restarts and cron runs.
#
# Usage:
#   source cmd/lib/ops.sh   # after common.sh/output.sh, REPO_ROOT set
#   ops_correlation_begin "diagnose"
#   ... work ...
#   ops_correlation_end "$EXIT_CODE"

ops_correlation_begin() {
  local command_name="$1"
  CORRELATION_ID="${OPS_CORRELATION_ID:-$(date -u +%Y%m%dT%H%M%SZ)-$$}"
  say "correlation-id: $CORRELATION_ID (${command_name})"
}

ops_correlation_end() {
  local exit_code="${1:-0}"
  local log_dir="$REPO_ROOT/storage/logs"
  mkdir -p "$log_dir" 2>/dev/null || return 0
  printf '%s\t%s\trc=%s\t%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$CORRELATION_ID" "$exit_code" "${OPS_CORRELATION_NOTE:-${0##*/}}" \
    >>"$log_dir/ops-correlation.log" 2>/dev/null || true
}
