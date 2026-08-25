# Docker Compose wrapper helpers: file selection, project handling, availability.
#
# Source after root.sh + common.sh.
#
# Compose file convention:
#   docker-compose.dev.yaml   — development stack
#   docker-compose.prod.yaml  — production stack
#
# The caller picks the profile by passing the file explicitly; no magic
# default discovery is used (legacy bare `docker compose` relies on the
# default filename and must go through cmd/docker/*).

readonly COMPOSE_DEV_FILE="$REPO_ROOT/docker-compose.dev.yaml"
readonly COMPOSE_PROD_FILE="$REPO_ROOT/docker-compose.prod.yaml"

require_docker() {
  require_cmd docker
  if ! docker compose version >/dev/null 2>&1; then
    echo "ERROR: Docker Compose (docker compose plugin) is required" >&2
    echo "  control:   $0" >&2
    echo "  resource:  docker compose" >&2
    echo "  remediation: enable the compose plugin or upgrade Docker" >&2
    exit "$EX_DEP"
  fi
}

# compose_file <dev|prod> — print the absolute Compose file path.
compose_file() {
  case "$1" in
    dev)  echo "$COMPOSE_DEV_FILE" ;;
    prod) echo "$COMPOSE_PROD_FILE" ;;
    *)
      echo "ERROR: unknown Compose profile '$1' (expected 'dev' or 'prod')" >&2
      exit "$EX_USAGE"
      ;;
  esac
}

# compose_run <profile> [args...] — run `docker compose` from the repo root.
compose_run() {
  local profile="$1"
  shift
  local file
  file="$(compose_file "$profile")"
  cd "$REPO_ROOT"
  docker compose -f "$file" "$@"
}