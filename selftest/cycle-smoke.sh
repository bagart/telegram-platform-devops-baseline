#!/usr/bin/env bash
set -euo pipefail
PKG="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PKG"
export REPO_ROOT="$PWD"
BASELINE_DIR="${BASELINE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export BASELINE_DIR
source "$BASELINE_DIR/lib/common.sh"
BASELINE_DIR="${BASELINE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export BASELINE_DIR
source "$BASELINE_DIR/lib/output.sh"
BASELINE_DIR="${BASELINE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export BASELINE_DIR
source "$BASELINE_DIR/lib/contract.sh"
BASELINE_DIR="${BASELINE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
export BASELINE_DIR
source "$BASELINE_DIR/lib/engine.sh"
FORMAT=text QUIET=0 RESUME=0 VERBOSE=0
echo MARK-1
engine_register x y true
engine_register y x true
echo MARK-2
# engine_execute exits the shell on a cycle, so isolate it in a subshell.
rc=0
( engine_execute ) >/tmp/cycle-out.txt 2>&1 || rc=$?
echo "MARK-3 rc=$rc"
cat /tmp/cycle-out.txt
