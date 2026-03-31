#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${ENV_FILE:-${SCRIPT_DIR}/.env}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Fichier .env introuvable: $ENV_FILE" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "$ENV_FILE"

: "${GIT_REPO:?GIT_REPO est requis dans .env}"
: "${GIT_TOKEN:?GIT_TOKEN est requis dans .env}"

GIT_BRANCH="${GIT_BRANCH:-main}"
BACKEND_DIR="${BACKEND_DIR:-$HOME/dosimetrie}"
FRONTEND_DIR="${FRONTEND_DIR:-$HOME/public_htm/dosimetrie.fr}"
CHECKOUT_DIR="${CHECKOUT_DIR:-$BACKEND_DIR/repo}"
API_PORT="${API_PORT:-3000}"
API_CORS_ORIGIN="${API_CORS_ORIGIN:-*}"
API_PUBLIC_URL="${API_PUBLIC_URL:-http://127.0.0.1:${API_PORT}/api}"
SITE_PUBLIC_URL="${SITE_PUBLIC_URL:-https://dosimetrie.fr}"
INSTALL_CRON_MONITOR="${INSTALL_CRON_MONITOR:-false}"

mkdir -p "$BACKEND_DIR" "$FRONTEND_DIR"

AUTH_REPO="$GIT_REPO"
if [[ "$GIT_REPO" == https://* ]]; then
  AUTH_REPO="https://${GIT_TOKEN}@${GIT_REPO#https://}"
fi

if [[ ! -d "$CHECKOUT_DIR/.git" ]]; then
  git clone "$AUTH_REPO" "$CHECKOUT_DIR"
fi

pushd "$CHECKOUT_DIR" >/dev/null

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Dépôt local modifié dans $CHECKOUT_DIR. Nettoyage avant mise à jour." >&2
  git reset --hard
fi

git remote set-url origin "$AUTH_REPO"
git fetch --all --prune
git checkout "$GIT_BRANCH"
git pull --ff-only origin "$GIT_BRANCH"

npm install --omit=dev

cp server.js "$BACKEND_DIR/server.js"
cp admin-mesures.html "$BACKEND_DIR/admin-mesures.html"
cp package.json "$BACKEND_DIR/package.json"
if [[ -f package-lock.json ]]; then
  cp package-lock.json "$BACKEND_DIR/package-lock.json"
fi
mkdir -p "$BACKEND_DIR/node_modules"
if [[ -d "$CHECKOUT_DIR/node_modules" ]]; then
  rsync -a "$CHECKOUT_DIR/node_modules/" "$BACKEND_DIR/node_modules/"
fi

cp index.ml "$FRONTEND_DIR/index.ml"
cp api-fonctionnement.html "$FRONTEND_DIR/api-fonctionnement.html"

cat > "$FRONTEND_DIR/config.js" <<FRONTCFG
window.DOSIMETRIE_API_URL = "${API_PUBLIC_URL}";
window.DOSIMETRIE_SITE_URL = "${SITE_PUBLIC_URL}";
FRONTCFG

cat > "$BACKEND_DIR/.runtime.env" <<RUNTIME
PORT=${API_PORT}
CORS_ORIGIN=${API_CORS_ORIGIN}
ADMIN_TOKEN=${ADMIN_TOKEN:-}
RUNTIME

cat > "$BACKEND_DIR/monitor_node.sh" <<'MONITOR'
#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_FILE="$BACKEND_DIR/.runtime.env"
PID_FILE="$BACKEND_DIR/dosimetrie-api.pid"
LOG_FILE="$BACKEND_DIR/dosimetrie-api.log"

if [[ -f "$RUNTIME_FILE" ]]; then
  # shellcheck source=/dev/null
  source "$RUNTIME_FILE"
fi

PORT="${PORT:-3000}"
HEALTH_URL="http://127.0.0.1:${PORT}/health"

is_healthy() {
  curl -fsS --max-time 3 "$HEALTH_URL" >/dev/null 2>&1
}

start_nohup() {
  nohup node "$BACKEND_DIR/server.js" >>"$LOG_FILE" 2>&1 &
  echo $! > "$PID_FILE"
}

restart_service() {
  if command -v pm2 >/dev/null 2>&1; then
    if pm2 describe dosimetrie-api >/dev/null 2>&1; then
      pm2 restart dosimetrie-api --update-env >/dev/null
    else
      pm2 start "$BACKEND_DIR/server.js" --name dosimetrie-api --time >/dev/null
      pm2 save >/dev/null 2>&1 || true
    fi
    return
  fi

  if [[ -f "$PID_FILE" ]]; then
    old_pid="$(cat "$PID_FILE" || true)"
    if [[ -n "${old_pid}" ]] && kill -0 "$old_pid" >/dev/null 2>&1; then
      kill "$old_pid" >/dev/null 2>&1 || true
      sleep 1
    fi
  fi
  start_nohup
}

if ! is_healthy; then
  restart_service
  sleep 2
  if ! is_healthy; then
    echo "[$(date -Is)] Échec redémarrage API" >> "$LOG_FILE"
    exit 1
  fi
fi
MONITOR

chmod +x "$BACKEND_DIR/monitor_node.sh"
"$BACKEND_DIR/monitor_node.sh"

if [[ "$INSTALL_CRON_MONITOR" == "true" ]]; then
  CRON_LINE="* * * * * $BACKEND_DIR/monitor_node.sh >/dev/null 2>&1"
  CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
  if ! grep -Fq "$BACKEND_DIR/monitor_node.sh" <<< "$CURRENT_CRON"; then
    { echo "$CURRENT_CRON"; echo "$CRON_LINE"; } | crontab -
  fi
fi

popd >/dev/null

echo "Déploiement terminé. Site: ${SITE_PUBLIC_URL} | API: ${API_PUBLIC_URL}"
echo "Contrôle Node: $BACKEND_DIR/monitor_node.sh"
