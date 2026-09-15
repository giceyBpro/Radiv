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
# Étend un éventuel ~ initial même si la valeur était entre guillemets dans .env
BACKEND_DIR="${BACKEND_DIR/#\~/$HOME}"
FRONTEND_DIR="${FRONTEND_DIR/#\~/$HOME}"
CHECKOUT_DIR="${CHECKOUT_DIR/#\~/$HOME}"
API_PORT="${API_PORT:-3003}"
API_PUBLIC_URL="${API_PUBLIC_URL:-http://127.0.0.1:${API_PORT}/api}"
MONITOR_PUBLIC_CONFIG_URL="${MONITOR_PUBLIC_CONFIG_URL:-${API_PUBLIC_URL%/}/config}"
SITE_PUBLIC_URL="${SITE_PUBLIC_URL:-https://www.example.org}"
# Défaut restrictif: retomber sur "*" ouvrirait l'API à toutes les origines.
API_CORS_ORIGIN="${API_CORS_ORIGIN:-${SITE_PUBLIC_URL}}"
SITE_NAME="${SITE_NAME:-$(sed -E 's#^https?://##; s#/.*$##; s#^www\.##' <<< "${SITE_PUBLIC_URL}")}"
COPYRIGHT_OWNER="${COPYRIGHT_OWNER:-${SITE_NAME}}"
# Host complet (avec le www éventuel) déduit de SITE_PUBLIC_URL, utilisé pour la
# redirection 301 vers www ci-dessous.
SITE_HOST="$(sed -E 's#^https?://##; s#/.*$##' <<< "${SITE_PUBLIC_URL}")"
INSTALL_CRON_MONITOR="${INSTALL_CRON_MONITOR:-true}"

log_step() { echo -e "\n[DEPLOY][STEP] $1"; }
log_info() { echo "[DEPLOY][INFO] $1"; }
log_ok() { echo "[DEPLOY][OK] $1"; }
log_warn() { echo "[DEPLOY][WARN] $1"; }

log_step "Initialisation"
log_info "Répertoires cibles: backend=$BACKEND_DIR | frontend=$FRONTEND_DIR"
mkdir -p "$BACKEND_DIR" "$FRONTEND_DIR"

# Le token ne doit jamais apparaître dans argv (lisible via `ps` par tout utilisateur
# local) ni être écrit dans .git/config, ni sur disque sous forme d'un script exécutable:
# un fichier askpass fraîchement créé (même chmod 700, même hors /tmp) peut être bloqué à
# l'exécution par un antivirus temps réel type Imunify360, courant sur CloudLinux, qui
# reconnaît ce pattern comme un collecteur d'identifiants. On passe donc par
# `credential.helper` en ligne de commande: git l'exécute via un simple `sh -c`, sans
# jamais créer de fichier — GIT_TOKEN reste dans l'environnement, jamais dans argv.
export GIT_TOKEN
run_git() {
  if [[ "$GIT_REPO" == https://* ]]; then
    git -c credential.helper='!f() { echo "username=x-access-token"; echo "password=$GIT_TOKEN"; }; f' "$@"
  else
    git "$@"
  fi
}

if [[ ! -d "$CHECKOUT_DIR/.git" ]]; then
  log_info "Clone initial du dépôt dans $CHECKOUT_DIR"
  run_git clone "$GIT_REPO" "$CHECKOUT_DIR"
fi

pushd "$CHECKOUT_DIR" >/dev/null

if [[ -n "$(git status --porcelain)" ]]; then
  log_warn "Dépôt local modifié dans $CHECKOUT_DIR, reset --hard avant mise à jour."
  git reset --hard
fi

log_step "Mise à jour Git"
log_info "Branche: $GIT_BRANCH"
git remote set-url origin "$GIT_REPO"
run_git fetch --all --prune
git checkout "$GIT_BRANCH"
run_git pull --ff-only origin "$GIT_BRANCH"

log_step "Dépendances backend"
npm install --omit=dev

# Synchronisation backend avec suppression des fichiers supprimés du repo
# tout en protégeant les fichiers de configuration/runtime locaux.
log_step "Synchronisation backend"
rsync -a --delete   --filter='P .env'   --filter='P .runtime.env'   --filter='P monitor_node.sh'   --filter='P radioprotection-api.log'   --filter='P radioprotection-api.pid'   --filter='P logs/'   --include='server.js'   --include='calculation.js'   --include='formules.txt'   --include='deploy.sh'   --include='package.json'   --include='package-lock.json'   --exclude='*'   "$CHECKOUT_DIR/" "$BACKEND_DIR/"

log_info "Mise à jour des droits d'exécution deploy.sh"
chmod +x "$BACKEND_DIR/deploy.sh"

mkdir -p "$BACKEND_DIR/node_modules"
if [[ -d "$CHECKOUT_DIR/node_modules" ]]; then
  log_info "Synchronisation node_modules"
  rsync -a --delete "$CHECKOUT_DIR/node_modules/" "$BACKEND_DIR/node_modules/"
fi

# Synchronisation frontend avec suppression contrôlée des fichiers supprimés du repo,
# sans supprimer les fichiers de configuration statiques du vhost.
log_step "Synchronisation frontend"
# --delete efface tout ce qui n'est pas dans la liste blanche: on refuse de pointer
# vers un répertoire qui contient manifestement autre chose que ce déploiement.
if [[ "$FRONTEND_DIR" == "$HOME" || "$FRONTEND_DIR" == "/" ]]; then
  log_warn "FRONTEND_DIR=$FRONTEND_DIR est trop large pour un rsync --delete. Abandon."
  exit 1
fi
if [[ -n "$(ls -A "$FRONTEND_DIR" 2>/dev/null)" ]] && [[ ! -f "$FRONTEND_DIR/index.html" ]]; then
  log_warn "FRONTEND_DIR=$FRONTEND_DIR n'est pas vide et ne contient pas index.html."
  log_warn "Vérifiez la valeur avant de relancer (rsync --delete effacerait son contenu). Abandon."
  exit 1
fi
# Liste blanche: tout ce qui n'est pas listé ici n'est PAS publié. Une liste noire
# échouerait en mode ouvert, publiant automatiquement tout nouveau fichier du dépôt.
# admin-mesures.html ne contient aucun secret: seuls les appels qu'elle fait vers
# /api/admin/* exigent le jeton (header X-Admin-Token, saisi dans la page). Page
# statique sans lien de navigation, comme tox.html/xplore.html.
rsync -a --delete \
  --filter='P .htaccess' \
  --filter='P .user.ini' \
  --include='index.html' \
  --include='print.html' \
  --include='explain.html' \
  --include='contact.html' \
  --include='mentions-legales.html' \
  --include='api-fonctionnement.html' \
  --include='test-api.html' \
  --include='tox.html' \
  --include='xplore.html' \
  --include='admin-mesures.html' \
  --include='favicon.ico' \
  --include='robots.txt' \
  --include='config.js' \
  --include='downloads/' \
  --include='downloads/**' \
  --exclude='*' \
  "$CHECKOUT_DIR/" "$FRONTEND_DIR/"

log_ok "Frontend synchronisé vers $FRONTEND_DIR"

log_step "Configuration .htaccess (règle /tox)"
HTACCESS="$FRONTEND_DIR/.htaccess"
TOX_RULE="RewriteRule ^tox/?$ tox.html [L]"
if [[ ! -f "$HTACCESS" ]]; then
  printf 'Options -Indexes\nRewriteEngine On\n%s\n' "$TOX_RULE" > "$HTACCESS"
  log_ok ".htaccess créé avec la règle /tox."
elif ! grep -Fq "tox.html" "$HTACCESS"; then
  printf '\n# /tox\nRewriteEngine On\n%s\n' "$TOX_RULE" >> "$HTACCESS"
  log_ok "Règle /tox ajoutée dans .htaccess."
else
  log_info "Règle /tox déjà présente dans .htaccess."
fi

log_step "Configuration .htaccess (règle /legal)"
LEGAL_RULE="RewriteRule ^legal/?$ mentions-legales.html [L]"
if [[ ! -f "$HTACCESS" ]]; then
  printf 'Options -Indexes\nRewriteEngine On\n%s\n' "$LEGAL_RULE" > "$HTACCESS"
  log_ok ".htaccess créé avec la règle /legal."
elif ! grep -Fq "mentions-legales.html" "$HTACCESS"; then
  printf '\n# /legal\nRewriteEngine On\n%s\n' "$LEGAL_RULE" >> "$HTACCESS"
  log_ok "Règle /legal ajoutée dans .htaccess."
else
  log_info "Règle /legal déjà présente dans .htaccess."
fi

log_step "Configuration .htaccess (redirection 301 vers www)"
# N'active la redirection que si SITE_PUBLIC_URL est explicitement en www: on ne force
# jamais un choix de domaine canonique que l'opérateur n'a pas fait lui-même.
# v2: exclut le chemin de l'API de la redirection www. Certains clients tiers (RIS,
# scripts externes) appellent l'API en dur sans www; une redirection 301 sur un POST lui
# fait perdre son corps (méthode réécrite en GET) et certains environnements embarqués ne
# suivent de toute façon pas les redirections cross-origin d'un fetch/XHR. L'API doit donc
# rester joignable telle quelle avec et sans www; seules les pages du site restent forcées
# sur le domaine canonique.
if [[ "$SITE_HOST" == www.* ]]; then
  # Le point du nom de domaine doit être échappé dans le motif regex de RewriteCond
  # (non échappé, il matcherait n'importe quel caractère, pas seulement ".").
  SITE_HOST_RE="${SITE_HOST//./\\.}"
  WWW_MARKER="# /www-redirect (${SITE_HOST}) v2"
  OLD_WWW_MARKER="# /www-redirect (${SITE_HOST})"

  # Remplace automatiquement l'ancien bloc (sans exclusion /api) s'il correspond
  # exactement à la signature générée par les versions précédentes de ce script: pas de
  # suppression aveugle d'un bloc .htaccess, seulement de celui que ce script a lui-même
  # écrit. Un bloc modifié à la main sous le même commentaire déclenche l'avertissement
  # ci-dessous à la place et n'est pas touché.
  OLD_MARKER_LINE="$(grep -nFx "$OLD_WWW_MARKER" "$HTACCESS" 2>/dev/null | head -1 | cut -d: -f1 || true)"
  if [[ -n "${OLD_MARKER_LINE:-}" ]] && [[ -f "$HTACCESS" ]]; then
    EXPECTED_OLD_BLOCK="$(printf 'RewriteEngine On\nRewriteCond %%{HTTPS} off [OR]\nRewriteCond %%{HTTP_HOST} !^%s$ [NC]\nRewriteRule ^ https://%s%%{REQUEST_URI} [L,R=301]' "$SITE_HOST_RE" "$SITE_HOST")"
    ACTUAL_OLD_BLOCK="$(sed -n "$((OLD_MARKER_LINE+1)),$((OLD_MARKER_LINE+4))p" "$HTACCESS")"
    if [[ "$ACTUAL_OLD_BLOCK" == "$EXPECTED_OLD_BLOCK" ]]; then
      DELETE_START="$OLD_MARKER_LINE"
      DELETE_END="$((OLD_MARKER_LINE+4))"
      PREV_LINE_NUM="$((OLD_MARKER_LINE-1))"
      # Supprime aussi la ligne vide séparatrice ajoutée devant le bloc par le printf
      # d'origine, si présente, pour ne pas laisser un blanc orphelin.
      if [[ "$PREV_LINE_NUM" -ge 1 ]] && [[ -z "$(sed -n "${PREV_LINE_NUM}p" "$HTACCESS")" ]]; then
        DELETE_START="$PREV_LINE_NUM"
      fi
      sed -i "${DELETE_START},${DELETE_END}d" "$HTACCESS"
      log_ok "Ancienne redirection www (sans exclusion /api) supprimée automatiquement de .htaccess."
    else
      log_warn "Un bloc /www-redirect existant dans .htaccess ne correspond pas exactement au format attendu; laissé tel quel. Vérifiez/nettoyez .htaccess manuellement."
    fi
  fi

  if grep -Fq "$WWW_MARKER" "$HTACCESS"; then
    log_info "Redirection www déjà présente dans .htaccess pour ${SITE_HOST}."
  else
    API_URI_PATH="$(sed -E 's#^https?://[^/]+##' <<< "${API_PUBLIC_URL%/}")"
    API_URI_PATH="${API_URI_PATH:-/api}"
    API_URI_PATH_RE="${API_URI_PATH//./\\.}"
    printf '\n%s\nRewriteEngine On\nRewriteCond %%{HTTPS} off\nRewriteRule ^ https://%%{HTTP_HOST}%%{REQUEST_URI} [L,R=301]\nRewriteCond %%{HTTP_HOST} !^%s$ [NC]\nRewriteCond %%{REQUEST_URI} !^%s(/|$)\nRewriteRule ^ https://%s%%{REQUEST_URI} [L,R=301]\n' \
      "$WWW_MARKER" "$SITE_HOST_RE" "$API_URI_PATH_RE" "$SITE_HOST" >> "$HTACCESS"
    log_ok "Redirection 301 vers https://${SITE_HOST} ajoutée dans .htaccess (hors ${API_URI_PATH}, accessible avec et sans www)."
  fi
else
  log_info "SITE_PUBLIC_URL (${SITE_HOST}) n'est pas en www: pas de redirection forcée."
fi

log_step "Configuration .htaccess (blocage HTTP des fichiers backend)"
# Sur certains hébergements (cPanel/Passenger), FRONTEND_DIR et BACKEND_DIR pointent
# vers le même dossier (PassengerAppRoot): .env, server.js, node_modules/, logs/ se
# retrouvent alors physiquement dans le docroot public. Ce bloc les rend inaccessibles
# en HTTP sans toucher au routage Passenger (aucune règle de portée globale: seuls ces
# noms de fichiers précis sont concernés, tout le reste — y compris les routes /api/*
# gérées par le Node app — n'est pas affecté). Inoffensif si FRONTEND_DIR est déjà un
# dossier purement statique séparé.
DENY_MARKER="# /deny-backend-files"
if grep -Fq "$DENY_MARKER" "$HTACCESS"; then
  log_info "Blocage des fichiers backend déjà présent dans .htaccess."
else
  cat >> "$HTACCESS" <<'DENYRULES'

# /deny-backend-files
RewriteEngine On
<FilesMatch "^\.env|^\.runtime\.env$|^package(-lock)?\.json$|^server\.js$|^deploy_update\.sh$|^monitor_node\.sh$|^formules\.txt$">
  Require all denied
</FilesMatch>
RewriteRule ^(logs|node_modules)/ - [F,L]
DENYRULES
  log_ok "Blocage HTTP des fichiers backend (.env, server.js, node_modules/, logs/...) ajouté dans .htaccess."
fi

log_step "Configuration frontend runtime"
# Valide que les valeurs ne contiennent pas de caractères dangereux pour JS
for _var_name in API_PUBLIC_URL SITE_PUBLIC_URL SITE_NAME COPYRIGHT_OWNER; do
  _var_val="${!_var_name}"
  # Les sauts de ligne doivent être rejetés aussi: une valeur multiligne injecterait
  # du JS arbitraire dans config.js, servi à tous les visiteurs.
  if [[ "$_var_val" =~ [\"\'\\$'\n'$'\r'] ]]; then
    log_warn "$_var_name contient des caractères invalides (\", ', \\, saut de ligne) — config.js non généré."
    exit 1
  fi
done
# Format ISO (indépendant de la locale du serveur, souvent absente en fr_FR sur un
# hébergement mutualisé) — le formatage lisible en français se fait côté navigateur.
LAST_DEPLOYED_AT="$(date +%F)"
cat > "$FRONTEND_DIR/config.js" <<FRONTCFG
window.RADIOPROTECTION_API_URL = "${API_PUBLIC_URL}";
window.RADIOPROTECTION_SITE_URL = "${SITE_PUBLIC_URL}";
window.RADIOPROTECTION_SITE_NAME = "${SITE_NAME}";
window.RADIOPROTECTION_COPYRIGHT_OWNER = "${COPYRIGHT_OWNER}";
window.RADIOPROTECTION_LAST_DEPLOYED_AT = "${LAST_DEPLOYED_AT}";
FRONTCFG

# sitemap.xml exige une URL absolue (spécification du protocole Sitemaps), donc générée
# ici avec le vrai domaine plutôt que commitée dans le dépôt avec un domaine factice.
# Seule la page d'accueil y figure: c'est la seule que robots.txt autorise à indexer.
xml_escape() {
  local s="$1"
  s="${s//&/&amp;}"; s="${s//</&lt;}"; s="${s//>/&gt;}"
  printf '%s' "$s"
}
SITEMAP_HOME_URL="$(xml_escape "${SITE_PUBLIC_URL%/}/")"
cat > "$FRONTEND_DIR/sitemap.xml" <<SITEMAP
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>${SITEMAP_HOME_URL}</loc>
    <changefreq>monthly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
SITEMAP

SITEMAP_ABS_URL="$(xml_escape "${SITE_PUBLIC_URL%/}/sitemap.xml")"
if ! grep -Fq "Sitemap:" "$FRONTEND_DIR/robots.txt" 2>/dev/null; then
  printf '\nSitemap: %s\n' "$SITEMAP_ABS_URL" >> "$FRONTEND_DIR/robots.txt"
fi

log_step "Configuration backend runtime (.runtime.env)"
# Le fichier doit être en 600 dès sa création: une redirection simple le crée en 644
# et laisse une fenêtre où ADMIN_TOKEN/SMTP_PASS sont lisibles par tout le monde.
install -m 600 /dev/null "$BACKEND_DIR/.runtime.env"
cat > "$BACKEND_DIR/.runtime.env" <<RUNTIME
PORT="${API_PORT}"
API_CORS_ORIGIN="${API_CORS_ORIGIN}"
TRUSTED_PROXIES="${TRUSTED_PROXIES:-127.0.0.1,::1}"
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
SFMN_MODE_ENABLED="${SFMN_MODE_ENABLED:-true}"
SFMN_CALCULATOR_URL="${SFMN_CALCULATOR_URL:-}"
ALERT_ON_API_DOWN_EMAIL="${ALERT_ON_API_DOWN_EMAIL:-false}"
ALERT_ON_API_DOWN_COOLDOWN_SEC="${ALERT_ON_API_DOWN_COOLDOWN_SEC:-600}"
MONITOR_PUBLIC_CONFIG_URL="${MONITOR_PUBLIC_CONFIG_URL}"
RUNTIME

chmod 600 "$BACKEND_DIR/.runtime.env"
log_ok ".runtime.env protégé (600)."

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

  # Les identifiants passent par un fichier de config temporaire en 600 plutôt que par
  # --user: la ligne de commande est lisible via `ps` par tout utilisateur local, et ce
  # script tourne toutes les minutes via cron.
  curl_cfg="$(umask 077 && mktemp "${TMPDIR:-/tmp}/monitor-smtp.XXXXXX")"
  printf 'user = "%s:%s"\n' "$SMTP_USER" "$SMTP_PASS" > "$curl_cfg"

  if curl -fsS --config "$curl_cfg" --url "$smtp_url" \
    --mail-from "<${envelope_from}>" \
    --mail-rcpt "<${CONTACT_DEST}>" \
    --upload-file - <<< "$mail_payload" >/dev/null 2>&1; then
    rm -f "$curl_cfg"
    echo "$now_ts" > "$ALERT_STATE_FILE"
    ok "Email d'alerte indisponibilité envoyé à ${CONTACT_DEST}."
  else
    rm -f "$curl_cfg"
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

  # pgrep -f interprète son motif comme une regex: un chemin contenant . + ( [ élargirait
  # la correspondance. On échappe les métacaractères, puis on revérifie la cmdline exacte.
  backend_re="$(printf '%s' "${BACKEND_DIR}/server.js" | sed -E 's/[][(){}.*+?^$|\\\/]/\\&/g')"
  pids="$(pgrep -f "node .*${backend_re}" 2>/dev/null || true)"
  if [[ -n "$pids" ]]; then
    while IFS= read -r pid; do
      [[ -n "$pid" ]] || continue
      pid_cmdline="$(ps -p "$pid" -o args= 2>/dev/null || true)"
      [[ "$pid_cmdline" == *"$BACKEND_DIR/server.js"* ]] || continue
      kill "$pid" >/dev/null 2>&1 || true
    done <<< "$pids"
  fi
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

# --force-restart (utilisé par deploy.sh juste après une resynchronisation): redémarre
# inconditionnellement, sans attendre un échec du health-check. Sans cet argument (usage
# cron normal, toutes les minutes), le comportement reste conditionnel comme avant — un
# service déjà sain ne doit pas être redémarré à chaque passage cron.
FORCE_RESTART=0
[[ "${1:-}" == "--force-restart" ]] && FORCE_RESTART=1

if [[ "$FORCE_RESTART" == "1" ]]; then
  log "Redémarrage forcé demandé (déploiement)."
  restart_service
  sleep 3
  if ! is_healthy || ! is_public_ok; then
    echo "[$(date -Is)] Échec redémarrage API (forcé)" >> "$LOG_FILE"
    warn "Redémarrage échoué. Voir le log: $LOG_FILE"
    send_api_down_alert
    exit 1
  fi
  ok "API redémarrée et opérationnelle (nouvelle configuration/code pris en compte)."
elif ! is_healthy || ! is_public_ok; then
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
log_step "Redémarrage du service (prise en compte du nouveau code/config)"
# Sans --force-restart, un service déjà en bonne santé (donc l'ancien process, avant ce
# déploiement) n'est jamais relancé: le nouveau server.js et le nouveau .runtime.env restent
# sur le disque mais ne sont jamais chargés tant que le process n'est pas redémarré.
"$BACKEND_DIR/monitor_node.sh" --force-restart

# Sur cPanel/Passenger, l'app Node est démarrée/gérée par Passenger lui-même (PassengerAppRoot),
# indépendamment du process nohup/pm2 que monitor_node.sh vient de relancer ci-dessus: les deux
# peuvent tourner en parallèle, et c'est celui de Passenger qui sert réellement le site public.
# Convention Passenger: toucher tmp/restart.txt déclenche son redémarrage à la requête suivante.
# Sans effet (fichier ignoré) si Passenger n'est pas utilisé pour ce déploiement.
mkdir -p "$BACKEND_DIR/tmp"
touch "$BACKEND_DIR/tmp/restart.txt"
log_info "tmp/restart.txt touché (redémarrage Passenger si applicable)."

CRON_LINE="* * * * * $BACKEND_DIR/monitor_node.sh >/dev/null 2>&1"

cron_present() {
  command -v crontab >/dev/null 2>&1 && \
    grep -Fq "$BACKEND_DIR/monitor_node.sh" <<< "$(crontab -l 2>/dev/null || true)"
}

install_cron() {
  if ! command -v crontab >/dev/null 2>&1; then
    log_warn "crontab indisponible sur ce système, installation automatique impossible."
    return 1
  fi
  local current
  current="$(crontab -l 2>/dev/null || true)"
  if grep -Fq "$BACKEND_DIR/monitor_node.sh" <<< "$current"; then
    log_info "Entrée cron déjà présente (aucune modification)."
    return 0
  fi
  { echo "$current"; echo "$CRON_LINE"; } | crontab -
  log_ok "Entrée cron ajoutée: $CRON_LINE"
}

if [[ "$INSTALL_CRON_MONITOR" == "true" ]]; then
  log_step "Configuration cron de supervision"
  install_cron
fi

popd >/dev/null

log_ok "Déploiement terminé. Site: ${SITE_PUBLIC_URL} | API: ${API_PUBLIC_URL}"
log_info "Contrôle Node: $BACKEND_DIR/monitor_node.sh"

# Validation finale — toujours vérifiée, quelle que soit la valeur de INSTALL_CRON_MONITOR
if cron_present; then
  log_ok "Supervision cron active."
else
  log_warn "Supervision cron ABSENTE. Ajoutez manuellement via 'crontab -e' :"
  log_warn "  $CRON_LINE"
fi
