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
FRONTEND_DIR="${FRONTEND_DIR:-$HOME/public_html/dosimetrie.fr}"
CHECKOUT_DIR="${CHECKOUT_DIR:-$BACKEND_DIR/repo}"
API_PORT="${API_PORT:-3000}"
API_CORS_ORIGIN="${API_CORS_ORIGIN:-*}"
API_PUBLIC_URL="${API_PUBLIC_URL:-http://127.0.0.1:${API_PORT}/api}"
SITE_PUBLIC_URL="${SITE_PUBLIC_URL:-https://dosimetrie.fr}"
INSTALL_CRON_MONITOR="${INSTALL_CRON_MONITOR:-false}"

echo "[DEPLOY] Initialisation des répertoires..."
mkdir -p "$BACKEND_DIR" "$FRONTEND_DIR"

AUTH_REPO="$GIT_REPO"
if [[ "$GIT_REPO" == https://* ]]; then
  AUTH_REPO="https://${GIT_TOKEN}@${GIT_REPO#https://}"
fi

if [[ ! -d "$CHECKOUT_DIR/.git" ]]; then
  echo "[DEPLOY] Clone initial du dépôt..."
  git clone "$AUTH_REPO" "$CHECKOUT_DIR"
fi

pushd "$CHECKOUT_DIR" >/dev/null

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Dépôt local modifié dans $CHECKOUT_DIR. Nettoyage avant mise à jour." >&2
  git reset --hard
fi

echo "[DEPLOY] Mise à jour git (${GIT_BRANCH})..."
git remote set-url origin "$AUTH_REPO"
git fetch --all --prune
git checkout "$GIT_BRANCH"
git pull --ff-only origin "$GIT_BRANCH"

echo "[DEPLOY] Installation des dépendances backend..."
npm install --omit=dev

# Synchronisation backend avec suppression des fichiers supprimés du repo
# tout en protégeant les fichiers de configuration/runtime locaux.
echo "[DEPLOY] Synchronisation backend..."
rsync -a --delete   --filter='P .env'   --filter='P .runtime.env'   --filter='P monitor_node.sh'   --filter='P dosimetrie-api.log'   --filter='P dosimetrie-api.pid'   --filter='P logs/'   --include='server.js'   --include='admin-mesures.html'   --include='deploy_update.sh'   --include='package.json'   --include='package-lock.json'   --exclude='*'   "$CHECKOUT_DIR/" "$BACKEND_DIR/"

echo "[DEPLOY] Mise à jour du script deploy_update.sh sur backend..."
chmod +x "$BACKEND_DIR/deploy_update.sh"

mkdir -p "$BACKEND_DIR/node_modules"
if [[ -d "$CHECKOUT_DIR/node_modules" ]]; then
  echo "[DEPLOY] Synchronisation node_modules..."
  rsync -a --delete "$CHECKOUT_DIR/node_modules/" "$BACKEND_DIR/node_modules/"
fi

# Synchronisation frontend avec suppression contrôlée des fichiers supprimés du repo,
# sans supprimer les fichiers de configuration statiques du vhost.
echo "[DEPLOY] Synchronisation frontend..."
rsync -a --delete \
  --filter='P .htaccess' \
  --filter='P .user.ini' \
  --exclude='.git/' \
  --exclude='node_modules/' \
  --exclude='logs/' \
  --exclude='.env' \
  --exclude='.runtime.env' \
  --exclude='server.js' \
  --exclude='deploy_update.sh' \
  --exclude='monitor_node.sh' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  "$CHECKOUT_DIR/" "$FRONTEND_DIR/"

echo "[DEPLOY] Frontend synchronisé vers $FRONTEND_DIR"

echo "[DEPLOY] Génération config.js frontend..."
cat > "$FRONTEND_DIR/config.js" <<FRONTCFG
window.DOSIMETRIE_API_URL = "${API_PUBLIC_URL}";
window.DOSIMETRIE_SITE_URL = "${SITE_PUBLIC_URL}";
FRONTCFG

echo "[DEPLOY] Écriture .runtime.env backend..."
cat > "$BACKEND_DIR/.runtime.env" <<RUNTIME
PORT="${API_PORT}"
CORS_ORIGIN="${API_CORS_ORIGIN}"
ADMIN_TOKEN="${ADMIN_TOKEN:-}"
RECAPTCHA_SECRET_KEY="${RECAPTCHA_SECRET_KEY:-}"
RECAPTCHA_SITE_KEY="${RECAPTCHA_SITE_KEY:-}"
SMTP_HOST="${SMTP_HOST:-}"
SMTP_PORT="${SMTP_PORT:-587}"
SMTP_SECURE="${SMTP_SECURE:-false}"
SMTP_USER="${SMTP_USER:-}"
SMTP_PASS="${SMTP_PASS:-}"
SMTP_FROM="${SMTP_FROM:-}"
CONTACT_DEST="${CONTACT_DEST:-}"
RUNTIME

echo "[DEPLOY] Génération script de supervision monitor_node.sh..."
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
