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
BACKEND_DIR="${BACKEND_DIR:-$HOME/backend}"
FRONTEND_DIR="${FRONTEND_DIR:-$HOME/public_html/frontend}"
CHECKOUT_DIR="${CHECKOUT_DIR:-$BACKEND_DIR/repo}"
API_PORT="${API_PORT:-3003}"
API_CORS_ORIGIN="${API_CORS_ORIGIN:-*}"
API_PUBLIC_URL="${API_PUBLIC_URL:-http://127.0.0.1:${API_PORT}/api}"
MONITOR_PUBLIC_CONFIG_URL="${MONITOR_PUBLIC_CONFIG_URL:-${API_PUBLIC_URL%/}/config}"
SITE_PUBLIC_URL="${SITE_PUBLIC_URL:-https://www.example.org}"
SITE_NAME="${SITE_NAME:-$(sed -E 's#^https?://##; s#/.*$##; s#^www\.##' <<< "${SITE_PUBLIC_URL}")}"
COPYRIGHT_OWNER="${COPYRIGHT_OWNER:-${SITE_NAME}}"
INSTALL_CRON_MONITOR="${INSTALL_CRON_MONITOR:-true}"

log_step() { echo -e "\n[DEPLOY][STEP] $1"; }
log_info() { echo "[DEPLOY][INFO] $1"; }
log_ok() { echo "[DEPLOY][OK] $1"; }
log_warn() { echo "[DEPLOY][WARN] $1"; }

log_step "Initialisation"
log_info "Répertoires cibles: backend=$BACKEND_DIR | frontend=$FRONTEND_DIR"
mkdir -p "$BACKEND_DIR" "$FRONTEND_DIR"

AUTH_REPO="$GIT_REPO"
if [[ "$GIT_REPO" == https://* ]]; then
  AUTH_REPO="https://${GIT_TOKEN}@${GIT_REPO#https://}"
fi

if [[ ! -d "$CHECKOUT_DIR/.git" ]]; then
  log_info "Clone initial du dépôt dans $CHECKOUT_DIR"
  git clone "$AUTH_REPO" "$CHECKOUT_DIR"
fi

pushd "$CHECKOUT_DIR" >/dev/null

if [[ -n "$(git status --porcelain)" ]]; then
  log_warn "Dépôt local modifié dans $CHECKOUT_DIR, reset --hard avant mise à jour."
  git reset --hard
fi

log_step "Mise à jour Git"
log_info "Branche: $GIT_BRANCH"
git remote set-url origin "$AUTH_REPO"
git fetch --all --prune
git checkout "$GIT_BRANCH"
git pull --ff-only origin "$GIT_BRANCH"

log_step "Dépendances backend"
npm install --omit=dev

# Synchronisation backend avec suppression des fichiers supprimés du repo
# tout en protégeant les fichiers de configuration/runtime locaux.
log_step "Synchronisation backend"
rsync -a --delete   --filter='P .env'   --filter='P .runtime.env'   --filter='P monitor_node.sh'   --filter='P radioprotection-api.log'   --filter='P radioprotection-api.pid'   --filter='P logs/'   --include='server.js'   --include='admin-mesures.html'   --include='formules.txt'   --include='deploy_update.sh'   --include='package.json'   --include='package-lock.json'   --exclude='*'   "$CHECKOUT_DIR/" "$BACKEND_DIR/"

log_info "Mise à jour des droits d'exécution deploy_update.sh"
chmod +x "$BACKEND_DIR/deploy_update.sh"

mkdir -p "$BACKEND_DIR/node_modules"
if [[ -d "$CHECKOUT_DIR/node_modules" ]]; then
  log_info "Synchronisation node_modules"
  rsync -a --delete "$CHECKOUT_DIR/node_modules/" "$BACKEND_DIR/node_modules/"
fi

# Synchronisation frontend avec suppression contrôlée des fichiers supprimés du repo,
# sans supprimer les fichiers de configuration statiques du vhost.
log_step "Synchronisation frontend"
rsync -a --delete \
  --filter='P .htaccess' \
  --filter='P .user.ini' \
  --exclude='.git/' \
  --exclude='node_modules/' \
  --exclude='logs/' \
  --exclude='.env' \
  --exclude='.runtime.env' \
  --exclude='server.js' \
  --exclude='formules.txt' \
  --exclude='deploy_update.sh' \
  --exclude='monitor_node.sh' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  "$CHECKOUT_DIR/" "$FRONTEND_DIR/"

log_ok "Frontend synchronisé vers $FRONTEND_DIR"

log_step "Configuration frontend runtime"
cat > "$FRONTEND_DIR/config.js" <<FRONTCFG
window.RADIOPROTECTION_API_URL = "${API_PUBLIC_URL}";
window.RADIOPROTECTION_SITE_URL = "${SITE_PUBLIC_URL}";
window.RADIOPROTECTION_SITE_NAME = "${SITE_NAME}";
window.RADIOPROTECTION_COPYRIGHT_OWNER = "${COPYRIGHT_OWNER}";
FRONTCFG

log_step "Configuration backend runtime (.runtime.env)"
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
ALERT_ON_API_DOWN_EMAIL="${ALERT_ON_API_DOWN_EMAIL:-false}"
ALERT_ON_API_DOWN_COOLDOWN_SEC="${ALERT_ON_API_DOWN_COOLDOWN_SEC:-600}"
MONITOR_PUBLIC_CONFIG_URL="${MONITOR_PUBLIC_CONFIG_URL}"
RUNTIME

log_step "Génération du script monitor_node.sh"
cat > "$BACKEND_DIR/monitor_node.sh" <<'MONITOR'
#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_FILE="$BACKEND_DIR/.runtime.env"
PID_FILE="$BACKEND_DIR/radioprotection-api.pid"
LOG_FILE="$BACKEND_DIR/radioprotection-api.log"

if [[ -f "$RUNTIME_FILE" ]]; then
  # shellcheck source=/dev/null
  source "$RUNTIME_FILE"
fi

PORT="${PORT:-3003}"
HEALTH_URL="http://127.0.0.1:${PORT}/health"
PUBLIC_CONFIG_URL="${MONITOR_PUBLIC_CONFIG_URL:-}"
ALERT_ON_API_DOWN_EMAIL="${ALERT_ON_API_DOWN_EMAIL:-false}"
ALERT_ON_API_DOWN_COOLDOWN_SEC="${ALERT_ON_API_DOWN_COOLDOWN_SEC:-600}"
ALERT_STATE_FILE="$BACKEND_DIR/.api_down_alert.last"
log() { echo "[MONITOR] $1"; }
ok() { echo "[MONITOR][OK] $1"; }
warn() { echo "[MONITOR][WARN] $1"; }

send_api_down_alert() {
  [[ "$ALERT_ON_API_DOWN_EMAIL" == "true" ]] || { log "Alerte email désactivée."; return 0; }
  [[ -n "${SMTP_HOST:-}" && -n "${SMTP_PORT:-}" && -n "${SMTP_USER:-}" && -n "${SMTP_PASS:-}" && -n "${CONTACT_DEST:-}" ]] || { warn "Alerte email activée mais configuration SMTP incomplète."; return 0; }

  now_ts="$(date +%s)"
  last_ts=0
  if [[ -f "$ALERT_STATE_FILE" ]]; then
    last_ts="$(cat "$ALERT_STATE_FILE" 2>/dev/null || echo 0)"
  fi
  if (( now_ts - last_ts < ALERT_ON_API_DOWN_COOLDOWN_SEC )); then
    log "Cooldown alerte actif, aucun email envoyé."
    return 0
  fi

  from_header="${SMTP_FROM:-$SMTP_USER}"
  from_match="$(sed -n 's/.*<\([^>]*\)>.*/\1/p' <<< "$from_header")"
  envelope_from="${from_match:-$SMTP_USER}"
  smtp_url="smtp://${SMTP_HOST}:${SMTP_PORT}"
  if [[ "${SMTP_SECURE:-false}" == "true" ]]; then
    smtp_url="smtps://${SMTP_HOST}:${SMTP_PORT}"
  fi

  mail_payload="$(cat <<EOF
From: ${from_header}
To: <${CONTACT_DEST}>
Subject: [Radioprotection RIV] Alerte indisponibilité API
Date: $(date -R)
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

L'API Radioprotection RIV est indisponible malgré une tentative de redémarrage.
Serveur: $(hostname)
Date: $(date -Is)
Healthcheck: ${HEALTH_URL}
EOF
)"

  if curl -fsS --url "$smtp_url" \
    --user "${SMTP_USER}:${SMTP_PASS}" \
    --mail-from "<${envelope_from}>" \
    --mail-rcpt "<${CONTACT_DEST}>" \
    --upload-file - <<< "$mail_payload" >/dev/null 2>&1; then
    echo "$now_ts" > "$ALERT_STATE_FILE"
    ok "Email d'alerte indisponibilité envoyé à ${CONTACT_DEST}."
  else
    warn "Échec envoi email d'alerte indisponibilité."
  fi
}

is_healthy() {
  curl -fsS --max-time 3 "$HEALTH_URL" >/dev/null 2>&1
}

is_public_ok() {
  if [[ -z "$PUBLIC_CONFIG_URL" ]]; then
    return 0
  fi
  curl -fsS --max-time 8 "$PUBLIC_CONFIG_URL" >/dev/null 2>&1
}

kill_managed_processes() {
  if [[ -f "$PID_FILE" ]]; then
    old_pid="$(cat "$PID_FILE" || true)"
    if [[ -n "${old_pid}" ]] && kill -0 "$old_pid" >/dev/null 2>&1; then
      cmdline="$(ps -p "$old_pid" -o args= 2>/dev/null || true)"
      if [[ "$cmdline" == *"$BACKEND_DIR/server.js"* ]]; then
        kill "$old_pid" >/dev/null 2>&1 || true
      fi
    fi
  fi

  while IFS= read -r pid; do
    [[ -n "$pid" ]] || continue
    kill "$pid" >/dev/null 2>&1 || true
  done < <(pgrep -f "node .*${BACKEND_DIR}/server.js" || true)
}

start_nohup() {
  nohup node "$BACKEND_DIR/server.js" >>"$LOG_FILE" 2>&1 &
  echo $! > "$PID_FILE"
}

restart_service() {
  if command -v pm2 >/dev/null 2>&1; then
    if pm2 describe radioprotection-api >/dev/null 2>&1; then
      pm2 restart radioprotection-api --update-env >/dev/null
    else
      pm2 start "$BACKEND_DIR/server.js" --name radioprotection-api --time >/dev/null
      pm2 save >/dev/null 2>&1 || true
    fi
    return
  fi

  kill_managed_processes
  sleep 1
  start_nohup
}

if ! is_healthy || ! is_public_ok; then
  warn "API indisponible (local/public). Tentative de redémarrage..."
  restart_service
  sleep 3
  if ! is_healthy || ! is_public_ok; then
    echo "[$(date -Is)] Échec redémarrage API" >> "$LOG_FILE"
    warn "Redémarrage échoué. Voir le log: $LOG_FILE"
    send_api_down_alert
    exit 1
  fi
  ok "API relancée et opérationnelle."
else
  ok "API opérationnelle (checks local/public OK)."
fi
MONITOR

chmod +x "$BACKEND_DIR/monitor_node.sh"
log_step "Exécution d'un contrôle monitor immédiat"
"$BACKEND_DIR/monitor_node.sh"

if [[ "$INSTALL_CRON_MONITOR" == "true" ]]; then
  if ! command -v crontab >/dev/null 2>&1; then
    log_warn "crontab indisponible, impossible d'installer la supervision cron automatiquement."
    popd >/dev/null
    log_ok "Déploiement terminé. Site: ${SITE_PUBLIC_URL} | API: ${API_PUBLIC_URL}"
    log_info "Contrôle Node: $BACKEND_DIR/monitor_node.sh"
    exit 0
  fi
  log_step "Configuration cron de supervision"
  CRON_LINE="* * * * * $BACKEND_DIR/monitor_node.sh >/dev/null 2>&1"
  CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
  if ! grep -Fq "$BACKEND_DIR/monitor_node.sh" <<< "$CURRENT_CRON"; then
    { echo "$CURRENT_CRON"; echo "$CRON_LINE"; } | crontab -
    log_ok "Entrée cron ajoutée: $CRON_LINE"
  else
    log_info "Entrée cron déjà présente (aucune modification)."
  fi
fi

popd >/dev/null

log_ok "Déploiement terminé. Site: ${SITE_PUBLIC_URL} | API: ${API_PUBLIC_URL}"
log_info "Contrôle Node: $BACKEND_DIR/monitor_node.sh"
