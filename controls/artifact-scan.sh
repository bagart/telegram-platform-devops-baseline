#!/usr/bin/env bash
# Artifact secret-scan layer (03-security-and-supply-chain.md §5, §10).
#
# Secret protection is layered: hook -> CI -> GitHub -> artifact scan. Build
# outputs (frontend bundles, dist trees) are typically untracked and therefore
# invisible to source-tree scans; a secret can still reach production through
# them. This control runs the same baseline scanner over the artifact
# directories that exist on disk.
#
# Artifact locations are configurable via BASELINE_ARTIFACT_DIRS
# (comma-separated, default "public/build"). Absent directories are skipped;
# when no artifact directory exists at all the control passes with a notice —
# there is nothing built to scan yet.
#
# Usage:
#   tools/baseline/artifact-scan.sh [--format=text|json]

set -euo pipefail

# Resolve the sibling control before the cd below, so the scan works from any cwd.
CONTROLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

REPO="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$REPO" ]]; then
  REPO="$PWD"
  while [[ "$REPO" != "/" && ! -f "$REPO/composer.json" ]]; do REPO="$(dirname "$REPO")"; done
fi
cd "$REPO" || exit 1

ARTIFACT_DIRS="${BASELINE_ARTIFACT_DIRS:-public/build}"

EXISTING=""
OLDIFS="$IFS"
IFS=","
for dir in $ARTIFACT_DIRS; do
  if [[ -d "$dir" ]]; then
    EXISTING="${EXISTING:+${EXISTING},}${dir}"
  fi
done
IFS="$OLDIFS"

if [[ -z "$EXISTING" ]]; then
  echo "artifact-scan: no build artifacts present (${ARTIFACT_DIRS}) — nothing to scan"
  exit 0
fi

ARGS=(--dir="$EXISTING")
for arg in "$@"; do
  case "$arg" in
    --format=json|--json) ARGS+=("--format=json") ;;
    --format=text) ARGS+=("--format=text") ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

exec php "$CONTROLS_DIR/secret-scan.php" "${ARGS[@]}"
