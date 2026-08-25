#!/usr/bin/env bash
# Locate the engine (BASELINE_DIR) and the consumer repository (REPO_ROOT).
#
# BASELINE_DIR — this package's installation root (contains lib/, controls/,
# defaults/, hooks/, bin/). Resolution order:
#   1. environment override,
#   2. derived from this file's location (works under vendor/ and in-tree).
#
# REPO_ROOT — the repository being checked ("consumer"). Marker: composer.json
# plus .git, discovered by walking up from the current working directory.
# Nothing may assume BASELINE_DIR == REPO_ROOT; engine assets always come from
# BASELINE_DIR, consumer policy/state from REPO_ROOT.
#
# Legacy fallback: when the engine lives inside the consumer tree
# ($BASELINE_DIR/tools/baseline-style layout), REPO_ROOT resolves relative to
# BASH_SOURCE exactly as the pre-package root.sh did.

_lib_baseline_dir() {
  if [[ -n "${BASELINE_DIR:-}" && -d "${BASELINE_DIR}" ]]; then
    cd -P "${BASELINE_DIR}" >/dev/null 2>&1 && pwd
    return
  fi
  # lib/root.sh -> package root is one level up.
  cd -P "$(dirname "${BASH_SOURCE[0]}")/.." >/dev/null 2>&1 && pwd
}

_lib_consumer_root() {
  local dir start
  start="$(pwd -P)"
  dir="$start"
  while [[ "$dir" != "/" ]]; do
    if [[ -f "$dir/composer.json" && -d "$dir/.git" ]]; then
      echo "$dir"
      return
    fi
    dir="$(dirname "$dir")"
  done

  # Legacy layout: engine checked out inside the consumer repo
  # (tools/baseline sibling of cmd/lib). Walk up from the engine location.
  dir="$(cd -P "$(dirname "${BASH_SOURCE[0]}")/../.." >/dev/null 2>&1 && pwd)"
  while [[ "$dir" != "/" ]]; do
    if [[ -f "$dir/composer.json" ]]; then
      echo "$dir"
      return
    fi
    dir="$(dirname "$dir")"
  done

  echo "${REPO_ROOT:-}"
}

BASELINE_DIR="$(_lib_baseline_dir)"
if [[ -z "$BASELINE_DIR" ]]; then
  echo "ERROR: unable to locate baseline engine directory" >&2
  exit 4
fi

REPO_ROOT="${REPO_ROOT:-$(_lib_consumer_root)}"
if [[ -z "$REPO_ROOT" ]]; then
  echo "ERROR: unable to locate repository root (composer.json + .git not found)" >&2
  exit 4
fi
export BASELINE_DIR REPO_ROOT
