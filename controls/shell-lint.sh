#!/usr/bin/env bash
# Syntax-check every bash entry point (02-developer-tooling.md §12).
# Auto-discovers bash scripts under cmd/, tools/ and deploy/ so new
# commands are covered without editing this list.
set -u
REPO="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$REPO" ]]; then
  REPO="$PWD"
  while [[ "$REPO" != "/" && ! -f "$REPO/composer.json" ]]; do REPO="$(dirname "$REPO")"; done
fi
BASELINE_DIR="${BASELINE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
if [[ "$BASELINE_DIR" == "$REPO/tools/baseline"* || "$BASELINE_DIR" == "$REPO" ]]; then
  # Legacy layout: engine lives inside the consumer repo.
  cd "$REPO" || exit 1
  SCOPES=(cmd tools deploy)
else
  # Package layout: self-lint the engine tree itself.
  cd "$BASELINE_DIR" || exit 1
  SCOPES=(lib bin hooks controls defaults selftest)
fi
status=0
while IFS= read -r -d '' f; do
  head -n 1 "$f" | grep -q '^#!.*bash' || continue
  if bash -n "$f"; then
    echo "OK   $f"
  else
    echo "FAIL $f"
    status=1
  fi
done < <(find "${SCOPES[@]}" -type f -print0 2>/dev/null)
exit "$status"
