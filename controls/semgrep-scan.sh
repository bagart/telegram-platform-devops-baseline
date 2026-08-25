#!/usr/bin/env bash
# SAST scan entry point (03-security-and-supply-chain.md §23-§24).
#
# Single definition site for scan targets and rule sets, shared by
# cmd/dev/security and .github/workflows/security.yml.
#
# Targets list library src/ trees explicitly: nested git repos are skipped
# as untracked when only the parent directory is passed.
#
# Enforcement follows the severity policy (03 §10): ERROR blocks,
# WARNING reports without failing (--error --severity ERROR).
#
# Usage:
#   tools/baseline/semgrep-scan.sh [--local-only] [--changed]
#     --local-only  skip registry rule packs (network); baseline rules still run
#     --changed     scan only files changed vs the diff base (03 §19 diff-aware
#                   scanning); base ref from BASE_DIFF (default: HEAD~1)

set -euo pipefail

REPO="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$REPO" ]]; then
  REPO="$PWD"
  while [[ "$REPO" != "/" && ! -f "$REPO/composer.json" ]]; do REPO="$(dirname "$REPO")"; done
fi
cd "$REPO" || exit 1

LOCAL_ONLY=0
CHANGED_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --local-only) LOCAL_ONLY=1 ;;
    --changed) CHANGED_ONLY=1 ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done

if ! command -v semgrep >/dev/null 2>&1; then
  echo "semgrep is not installed" >&2
  exit 3
fi

if [[ "$CHANGED_ONLY" -eq 1 ]]; then
  BASE_REF="${BASE_DIFF:-HEAD~1}"
  TARGETS=()
  while IFS= read -r f; do
    [[ -f "$f" ]] && TARGETS+=("$f")
  done < <(git diff --name-only --diff-filter=ACM "$BASE_REF" -- app tests \
      misc/BAGArt/php-async-kernel-lib/src misc/BAGArt/php-async-kernel-client/src \
      misc/BAGArt/php-async-kernel-client-redis/src misc/BAGArt/telegram-bot-lib/src \
      misc/BAGArt/telegram-bot-basic-lib/src misc/BAGArt/telegram-bot-management/src || true)
  if [[ ${#TARGETS[@]} -eq 0 ]]; then
    echo "no changed files under scan targets — nothing to scan"
    exit 0
  fi
else
  # Targets list library src/ trees explicitly: nested git repos are skipped
  # as untracked when only the parent directory is passed.
  TARGETS=(
    app
    tests
    misc/BAGArt/php-async-kernel-lib/src
    misc/BAGArt/php-async-kernel-client/src
    misc/BAGArt/php-async-kernel-client-redis/src
    misc/BAGArt/telegram-bot-lib/src
    misc/BAGArt/telegram-bot-basic-lib/src
    misc/BAGArt/telegram-bot-management/src
  )
fi

RULES="${BASELINE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}/defaults/semgrep-rules.yml"
[[ -f tools/baseline/semgrep-rules.yml ]] && RULES=tools/baseline/semgrep-rules.yml
CONFIGS=(--config=$RULES)
if [[ "$LOCAL_ONLY" -eq 0 ]]; then
  # p/security no longer exists upstream (404); owasp-top-ten + secrets are
  # the maintained equivalents.
  CONFIGS+=(--config=p/php --config=p/owasp-top-ten --config=p/secrets)
fi

# Generated DTO trees run under their own tuned pack at WARNING severity
# (03 §10): real vulnerability classes report, style noise stays out, and
# nothing there can block the gate.
GENERATED="misc/BAGArt/telegram-bot-lib/src/TgApi"

run_main_scan() {
  semgrep scan --metrics=off "${CONFIGS[@]}" \
    --exclude '*/src/TgApi/*' --exclude vendor --exclude node_modules --exclude dist \
    --error --severity ERROR --quiet "${TARGETS[@]}"
}

run_generated_scan() {
  [[ -d "$GENERATED" ]] || return 0
  semgrep scan --metrics=off --config tools/baseline/semgrep-rules-generated.yml \
    --severity WARNING --quiet "$GENERATED" || true
}

run_generated_scan
exec run_main_scan
