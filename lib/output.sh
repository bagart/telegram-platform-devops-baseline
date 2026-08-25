# Output helpers: concise human-readable output with consistent markers.
#
# Source after common.sh.
#
# Machine-readable output (--format=json) is intentionally not implemented
# yet — no consumer exists. The helpers below keep a stable interface so a
# json mode can be added without breaking human output.

say() {
  echo "==> $*"
}

ok() {
  echo "✓ $*"
}

warn() {
  echo "WARN: $*" >&2
}

err() {
  echo "ERROR: $*" >&2
}