#!/usr/bin/env bash
# Container image tag policy (03-security-and-supply-chain.md §38 immutability).
#
# Fails when mutable tags (:latest or floating branch tags) are referenced in
# production deployment files. Immutable references = digests (@sha256:...)
# or immutable version tags.
#
# Usage:
#   tools/baseline/image-policy.sh

set -euo pipefail

REPO="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$REPO" ]]; then
  REPO="$PWD"
  while [[ "$REPO" != "/" && ! -f "$REPO/composer.json" ]]; do REPO="$(dirname "$REPO")"; done
fi
cd "$REPO" || exit 1

VIOLATIONS=0
check() {
  local label="$1" path="$2"
  [[ -f "$path" ]] || return 0
  if grep -nE 'image:[^#]*:(latest|main|master|develop)\s*$' "$path" >/dev/null 2>&1; then
    echo "POLICY VIOLATION in $label ($path): mutable image tag:" >&2
    grep -nE 'image:[^#]*:(latest|main|master|develop)\s*$' "$path" >&2 || true
    VIOLATIONS=1
  fi
}

check "compose prod"      "docker-compose.prod.yaml"
# Dev compose is intentionally exempt: local convenience tags are fine there;
# immutability (03 §38) governs what gets deployed.
check "monitoring stack"  "deploy/monitoring/docker-compose.yml"

if (( VIOLATIONS > 0 )); then
  echo "Image immutability policy failed — pin digests or immutable version tags." >&2
  exit 1
fi

echo "image-policy: OK (no mutable tags)"
