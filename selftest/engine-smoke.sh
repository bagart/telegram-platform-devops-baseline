#!/usr/bin/env bash
# Functional smoke test for the control engine (not part of check gates).
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

ctl_a() { echo a-ok; }
ctl_b() { echo b-ok; }
ctl_slow() { sleep 0.3; }
engine_register "a" "" ctl_a
engine_register "b" "a" ctl_b
engine_register "slow" "a" ctl_slow

if engine_execute; then
  echo "ENGINE: pass"
else
  echo "ENGINE: fail"
  exit 1
fi

# Cycle detection must fail. engine_execute exits the shell on a cycle, so
# the subshell's exit status is the verdict.
if (
  FORMAT=text
  ENGINE_IDS=(); ENGINE_DEPS=(); ENGINE_CMDS=()
  engine_register x y true
  engine_register y x true
  engine_execute >/dev/null 2>&1
); then
  echo "CYCLE: not detected"
  exit 1
else
  echo "CYCLE: detected"
fi
