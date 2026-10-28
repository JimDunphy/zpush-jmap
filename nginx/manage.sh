#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$ROOT_DIR/.env"
ENV_EXAMPLE="$ROOT_DIR/env.example"
DATA_DIR="$ROOT_DIR/zpush" # Main directory for persistent volumes

die() { echo "error: $*" >&2; exit 1; }
info() { echo "[+] $*"; }

ensure_env() {
  if [[ ! -f "$ENV_FILE" ]]; then
    cp "$ENV_EXAMPLE" "$ENV_FILE"
    info "Wrote $ENV_FILE (copied from env.example). Adjust as needed."
  fi
  # shellcheck disable=SC1090
  set -a; source "$ENV_FILE"; set +a
}

cmd_init() {
  mkdir -p "$DATA_DIR/config" "$DATA_DIR/state" "$DATA_DIR/log"
  [[ -f "$ENV_FILE" ]] || cp "$ENV_EXAMPLE" "$ENV_FILE"
  info "Initialized directories in ./zpush/ and created .env file."
}

cmd_build() {
  ensure_env
  local refresh=()
  case "${1:-}" in
    --refresh) refresh=(--pull --no-cache); shift ;;
  esac
  [[ $# -eq 0 ]] || die "Usage: $0 --build [--refresh]"
  info "Building image ${ZPUSH_IMAGE:-local/zpush:latest}"
  PROJECT_ROOT="$(cd "$ROOT_DIR/.." && pwd)"
  local fingerprint
  fingerprint=$(python3 "$ROOT_DIR/release.py" fingerprint)
  local application_version
  application_version=$(python3 "$ROOT_DIR/release.py" application-version)
  docker build "${refresh[@]}" --label "io.zpush.source-sha256=$fingerprint" --label "org.opencontainers.image.version=$application_version" --network "${ZPUSH_BUILD_NETWORK:-default}" -f "$ROOT_DIR/Dockerfile" -t "${ZPUSH_IMAGE:-local/zpush:latest}" "$PROJECT_ROOT"
}

cmd_version() {
  [[ ! -f "$ENV_FILE" ]] || ensure_env
  python3 "$ROOT_DIR/release.py" version --image "${ZPUSH_IMAGE:-local/zpush:latest}"
}

cmd_remote_version() {
  [[ ! -f "$ENV_FILE" ]] || ensure_env
  python3 "$ROOT_DIR/release.py" remote-version "$@"
}

cmd_verify_image() {
  ensure_env
  python3 "$ROOT_DIR/release.py" verify --image "${1:-${ZPUSH_IMAGE:-local/zpush:latest}}"
}

cmd_deploy() {
  ensure_env
  python3 "$ROOT_DIR/release.py" deploy --image "${ZPUSH_IMAGE:-local/zpush:latest}" "$@"
}

cmd_console() {
  ensure_env
  local service_name="stalwart-sync"
  info "Opening a shell in the '${service_name}' service container..."
  docker compose -p zpush-stalwart --env-file "$ENV_FILE" -f "$ROOT_DIR/docker-compose.yml" exec "$service_name" /bin/bash
}

cmd_up() {
  ensure_env
  docker compose -p zpush-stalwart --env-file "$ENV_FILE" -f "$ROOT_DIR/docker-compose.yml" up -d --build
}

cmd_up_no_build() {
  ensure_env
  docker compose -p zpush-stalwart --env-file "$ENV_FILE" -f "$ROOT_DIR/docker-compose.yml" up -d --no-build
}

cmd_down() {
  ensure_env
  docker compose -p zpush-stalwart --env-file "$ENV_FILE" -f "$ROOT_DIR/docker-compose.yml" down
}

cmd_restart() {
  info "Restarting stack (down -> up --build)"
  cmd_down
  cmd_up
}

cmd_restart_no_build() {
  info "Restarting stack (down -> up --no-build)"
  cmd_down
  cmd_up_no_build
}

cmd_logs() {
  docker compose -p zpush-stalwart -f "$ROOT_DIR/docker-compose.yml" logs -f --tail=200
}

cmd_health() {
  ensure_env
  curl -fsS "http://127.0.0.1:${ZPUSH_HTTP_PORT:-10000}/healthz" \
    || curl -fsS "http://${ZPUSH_BIND_ADDRESS:-127.0.0.1}:${ZPUSH_HTTP_PORT:-10000}/healthz" \
    || (echo FAIL; exit 1)
  echo OK
}

cmd_backend_version() {
  ensure_env

  local configured="${STALWART_BACKEND_VERSION:-dev}"
  echo "Configured STALWART_BACKEND_VERSION=${configured}"

  local container_id
  container_id=$(docker compose -p zpush-stalwart --env-file "$ENV_FILE" -f "$ROOT_DIR/docker-compose.yml" ps -q stalwart-sync 2>/dev/null || true)
  if [[ -z "$container_id" ]]; then
    echo "Runtime STALWART_BACKEND_VERSION=container-not-running"
    return 0
  fi

  local runtime_version
  runtime_version=$(docker exec "$container_id" php -r "require '/var/www/zpush/backend/stalwart/config.php'; echo defined('STALWART_BACKEND_VERSION') ? STALWART_BACKEND_VERSION : 'unset';" 2>/dev/null || true)
  if [[ -z "$runtime_version" ]]; then
    runtime_version="unknown"
  fi

  echo "Runtime STALWART_BACKEND_VERSION=${runtime_version}"
}

cmd_zpush_version() {
  ensure_env

  local dockerfile="$ROOT_DIR/Dockerfile"
  local pinned_version="unknown"
  if [[ -f "$dockerfile" ]]; then
    pinned_version=$(awk -F= '/^ARG ZPUSH_VERSION=/{print $2; exit}' "$dockerfile")
  fi
  if [[ -z "$pinned_version" ]]; then
    pinned_version="unknown"
  fi

  echo "Pinned Dockerfile ZPUSH_VERSION=${pinned_version}"

  local container_id
  container_id=$(docker compose -p zpush-stalwart --env-file "$ENV_FILE" -f "$ROOT_DIR/docker-compose.yml" ps -q stalwart-sync 2>/dev/null || true)
  if [[ -z "$container_id" ]]; then
    echo "Runtime Z-Push Version=container-not-running"
    return 0
  fi

  local runtime_version
  runtime_version=$(docker exec "$container_id" sh -lc "cat /var/www/zpush/version 2>/dev/null || true" | tr -d "\r" | head -n 1)
  if [[ -z "$runtime_version" ]]; then
    runtime_version="unknown"
  fi

  echo "Runtime Z-Push Version=${runtime_version}"
}

cmd_backup() {
    info "Backing up persistent data from ./zpush/ directory..."
    TIMESTAMP=$(date +"%Y%m%d-%H%M%S")
    BACKUP_FILE="zpush-backup-${TIMESTAMP}.tar.gz"
    tar -czvf "$BACKUP_FILE" -C "$DATA_DIR" .
    info "Backup created: $BACKUP_FILE"
}

cmd_set_port() {
  local port="${1:-}"
  [[ -n "$port" ]] || die "Usage: $0 --set-port <port>"
  ensure_env
  sed -ri "s/^ZPUSH_HTTP_PORT=.*/ZPUSH_HTTP_PORT=${port}/" "$ENV_FILE"
  info "Set ZPUSH_HTTP_PORT=$port in $ENV_FILE"
}

cmd_set_image() {
  local image="${1:-}"
  [[ -n "$image" ]] || die "Usage: $0 --set-image <image[:tag]>"
  ensure_env
  if grep -q '^ZPUSH_IMAGE=' "$ENV_FILE"; then
    sed -ri "s#^ZPUSH_IMAGE=.*#ZPUSH_IMAGE=${image}#" "$ENV_FILE"
  else
    printf '\nZPUSH_IMAGE=%s\n' "$image" >> "$ENV_FILE"
  fi
  info "Set ZPUSH_IMAGE=$image in $ENV_FILE"
}

cmd_export_image() {
  local out_file="${1:-}"
  [[ -n "$out_file" ]] || die "Usage: $0 --export-image <output.tar|output.tar.gz>"
  ensure_env
  local image="${ZPUSH_IMAGE:-local/zpush:latest}"
  docker image inspect "$image" >/dev/null 2>&1 || die "Image not found: $image"
  info "Exporting image $image to $out_file"
  if [[ "$out_file" == *.gz ]]; then
    docker save "$image" | gzip -c > "$out_file"
  else
    docker save -o "$out_file" "$image"
  fi
  info "Export complete: $out_file"
}

cmd_import_image() {
  local in_file="${1:-}"
  [[ -n "$in_file" ]] || die "Usage: $0 --import-image <input.tar|input.tar.gz>"
  [[ -f "$in_file" ]] || die "File not found: $in_file"
  info "Importing image from $in_file"
  if [[ "$in_file" == *.gz ]]; then
    gzip -dc "$in_file" | docker load
  else
    docker load -i "$in_file"
  fi
  info "Import complete from $in_file"
}

usage() {
  cat <<USAGE
Usage: $(basename "$0") <command> [args]

Container Lifecycle & Data
  --init      Create .env and data dirs (config/state/log)
  --build [--refresh]  Build image; --refresh pulls bases and reinstalls packages
  --verify-image [image]  Test image locally and record source/image verification
  --deploy [--dry-run]  Push verified source/image to ZPUSH_DEPLOY_SSH/ZPUSH_DEPLOY_DIR
  --up        docker compose up -d --build
  --up-no-build   docker compose up -d --no-build (deploy prebuilt image)
  --down      docker compose down
  --restart   docker compose down && up -d --build
  --restart-no-build docker compose down && up -d --no-build
  --logs      Tail container logs
  --health    Check container /healthz
  --version   Show checkout, built/running application, image, Z-Push and PHP versions
  --remote-version [--host HOST --dir PATH]  Read versions from the configured SSH target
  --backend-version  Show configured and running STALWART_BACKEND_VERSION
  --zpush-version  Show pinned Dockerfile and running Z-Push versions
  --console   Open shell in container
  --backup    Create a timestamped backup of the ./zpush data directory
  --set-port <port>  Update .env ZPUSH_HTTP_PORT
  --set-image <image[:tag]>  Update .env ZPUSH_IMAGE
  --export-image <output.tar|output.tar.gz>  Save current image to file
  --import-image <input.tar|input.tar.gz>    Load image from file

USAGE
}

main() {
  local cmd="${1:-}"; shift || true
  case "$cmd" in
    help|-h|--help|'') usage; exit 0;;
    --init|init) cmd_init "$@";;
    --build|build) cmd_build "$@";;
    --verify-image|verify-image) cmd_verify_image "$@";;
    --deploy|deploy) cmd_deploy "$@";;
    --console|console) cmd_console "$@";;
    --up|up) cmd_up "$@";;
    --up-no-build|up-no-build|--up-nobuild|up-nobuild) cmd_up_no_build "$@";;
    --down|down) cmd_down "$@";;
    --restart|restart) cmd_restart "$@";;
    --restart-no-build|restart-no-build|--restart-nobuild|restart-nobuild) cmd_restart_no_build "$@";;
    --logs|logs) cmd_logs "$@";;
    --health|health) cmd_health "$@";;
    --version|version) cmd_version "$@";;
    --remote-version|remote-version) cmd_remote_version "$@";;
    --backend-version|backend-version) cmd_backend_version "$@";;
    --zpush-version|zpush-version) cmd_zpush_version "$@";;
    --backup|backup) cmd_backup "$@";;
    --set-port|set-port) cmd_set_port "$@";;
    --set-image|set-image) cmd_set_image "$@";;
    --export-image|export-image) cmd_export_image "$@";;
    --import-image|import-image) cmd_import_image "$@";;
    *) usage; [[ -n "$cmd" ]] && exit 1 || true ;;
  esac
}

main "$@"
