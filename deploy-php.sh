#!/usr/bin/env bash
# Déploiement/mise à jour idempotent de la version PHP (api/) + frontend statique.
# Remplace deploy.sh (Node) à la bascule: plus de process à superviser, donc plus de
# monitor_node.sh, de cron de surveillance, de PID/kill/restart ni de tmp/restart.txt (Passenger).
# PHP s'exécute par requête sous Apache: déployer = copier les fichiers. Frontend et API vivent
# dans un seul dossier (FRONTEND_DIR, la racine web), l'API sous <racine>/api/.
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
# BACKEND_DIR n'a plus de rôle propre (plus de process Node): il ne sert qu'à retrouver le
# dossier du clone, pour qu'un .env écrit pour deploy.sh fonctionne tel quel.
BACKEND_DIR="${BACKEND_DIR:-$HOME/backend}"
FRONTEND_DIR="${FRONTEND_DIR:-$HOME/public_html/frontend}"
CHECKOUT_DIR="${CHECKOUT_DIR:-$BACKEND_DIR/repo}"
# Étend un éventuel ~ initial même si la valeur était entre guillemets dans .env
BACKEND_DIR="${BACKEND_DIR/#\~/$HOME}"
FRONTEND_DIR="${FRONTEND_DIR/#\~/$HOME}"
CHECKOUT_DIR="${CHECKOUT_DIR/#\~/$HOME}"
API_PUBLIC_URL="${API_PUBLIC_URL:-https://www.example.org/api}"
SITE_PUBLIC_URL="${SITE_PUBLIC_URL:-https://www.example.org}"
# Défaut restrictif: retomber sur "*" ouvrirait l'API à toutes les origines.
API_CORS_ORIGIN="${API_CORS_ORIGIN:-${SITE_PUBLIC_URL}}"
SITE_NAME="${SITE_NAME:-$(sed -E 's#^https?://##; s#/.*$##; s#^www\.##' <<< "${SITE_PUBLIC_URL}")}"
COPYRIGHT_OWNER="${COPYRIGHT_OWNER:-${SITE_NAME}}"
# Host complet (avec le www éventuel) déduit de SITE_PUBLIC_URL, utilisé pour la
# redirection 301 vers www ci-dessous.
SITE_HOST="$(sed -E 's#^https?://##; s#/.*$##' <<< "${SITE_PUBLIC_URL}")"

log_step() { echo -e "\n[DEPLOY][STEP] $1"; }
log_info() { echo "[DEPLOY][INFO] $1"; }
log_ok() { echo "[DEPLOY][OK] $1"; }
log_warn() { echo "[DEPLOY][WARN] $1"; }

log_step "Initialisation"
log_info "Racine web (frontend + api/): $FRONTEND_DIR | clone: $CHECKOUT_DIR"
mkdir -p "$FRONTEND_DIR" "$(dirname "$CHECKOUT_DIR")"

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

log_step "Contrôle PHP"
# Garde-fou avant de publier quoi que ce soit: un fichier PHP à la syntaxe invalide mettrait
# l'API hors service jusqu'au prochain déploiement. Sans php en ligne de commande (rare sur
# cPanel), le contrôle est simplement sauté.
if command -v php >/dev/null 2>&1; then
  php_version="$(php -r 'echo PHP_VERSION;')"
  if ! php -r 'exit(version_compare(PHP_VERSION, "8.0.0", ">=") ? 0 : 1);'; then
    log_warn "php en ligne de commande est en $php_version (< 8.0): le code d'api/ exige PHP 8.0+. Abandon."
    log_warn "Vérifiez aussi la version PHP choisie pour le site dans cPanel (Sélecteur PHP)."
    exit 1
  fi
  syntax_errors=0
  while IFS= read -r -d '' phpfile; do
    if ! php -l "$phpfile" >/dev/null 2>&1; then
      log_warn "Erreur de syntaxe PHP: $phpfile"
      syntax_errors=$((syntax_errors + 1))
    fi
  done < <(find api -name '*.php' -print0)
  if [[ "$syntax_errors" -gt 0 ]]; then
    log_warn "$syntax_errors fichier(s) PHP invalide(s): déploiement abandonné, rien n'a été modifié."
    exit 1
  fi
  log_ok "Syntaxe PHP valide (php $php_version en ligne de commande)."
  log_info "Rappel: la version PHP du SITE se règle dans cPanel (Sélecteur PHP) et doit aussi être >= 8.0."
else
  log_warn "php introuvable en ligne de commande: contrôle de syntaxe sauté."
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
# v1.html: ancienne version de l'interface (design précédent), conservée sans
# lien de/vers index.html. Accessible uniquement en tapant l'URL. Pas de route
# courte dans SHORT_ROUTES ci-dessous, pour ne pas la rendre plus visible que
# les autres pages non liées.
# app.js: logique JS partagée entre index.html et v1.html (chargée via <script
# src="app.js">), pour qu'une évolution du calcul/de l'affichage s'applique aux
# deux pages depuis un seul fichier, sans duplication à maintenir à la main.
# api/ n'est volontairement pas dans cette liste: il a sa propre synchronisation plus bas
# (avec ses fichiers protégés: .env, logs/, var/), et les fichiers non listés ici ne sont
# jamais supprimés (pas de --delete-excluded).
rsync -a --delete \
  --filter='P .htaccess' \
  --filter='P .user.ini' \
  --include='index.html' \
  --include='v1.html' \
  --include='app.js' \
  --include='print.html' \
  --include='explain.html' \
  --include='contact.html' \
  --include='mentions-legales.html' \
  --include='api-fonctionnement.html' \
  --include='test-api.html' \
  --include='xplore.html' \
  --include='favicon.ico' \
  --include='robots.txt' \
  --include='config.js' \
  --include='downloads/' \
  --include='downloads/**' \
  --exclude='*' \
  "$CHECKOUT_DIR/" "$FRONTEND_DIR/"

log_ok "Frontend synchronisé vers $FRONTEND_DIR"

# Pages retirées du site (tox est devenu un site indépendant). La liste blanche rsync ne supprime
# jamais ce qu'elle n'inclut pas: un site déjà déployé garderait ces pages en ligne, on les retire donc
# explicitement, ainsi que l'ancienne règle /tox du .htaccess (uniquement le bloc écrit par ce script).
for _retired in tox.html .tox-complet.html; do
  if [[ -e "$FRONTEND_DIR/$_retired" ]]; then
    rm -f "$FRONTEND_DIR/$_retired"
    log_ok "Page retirée: $_retired"
  fi
done

log_step "Synchronisation de l'API PHP"
API_DIR="$FRONTEND_DIR/api"
mkdir -p "$API_DIR"
# Protégés contre --delete (jamais écrasés ni supprimés): la configuration locale (.env,
# .runtime.env) et les données d'exécution (logs/ = mesures RGPD, var/ = compteurs de rate-limit
# et cache géoIP). tests/ est un outillage de développement: il n'est pas publié.
rsync -a --delete \
  --filter='P .env' \
  --filter='P .runtime.env' \
  --filter='P logs/' \
  --filter='P var/' \
  --exclude='tests/' \
  "$CHECKOUT_DIR/api/" "$API_DIR/"
rm -rf "${API_DIR:?}/tests"
log_ok "API synchronisée vers $API_DIR"

log_step "Configuration .htaccess (raccourcis de pages sans .html)"
HTACCESS="$FRONTEND_DIR/.htaccess"
if [[ ! -f "$HTACCESS" ]]; then
  printf 'Options -Indexes\nRewriteEngine On\n' > "$HTACCESS"
  log_ok ".htaccess créé."
fi
# Une ancienne configuration Passenger (app Node déclarée dans cPanel) enverrait encore /api/*
# vers Node, et non vers PHP: ce n'est pas à ce script de la modifier (bloc géré par cPanel).
if grep -Eqi 'PassengerAppRoot|CLOUDLINUX PASSENGER CONFIGURATION|PassengerNodejs' "$HTACCESS"; then
  log_warn "Le .htaccess contient encore une configuration Passenger/Node."
  log_warn "Désactivez/supprimez l'application dans cPanel (Setup Node.js App) AVANT de basculer, sinon"
  log_warn "/api/* continuera d'être servi par Node et non par PHP."
fi
# L'administration du site n'a ni page statique ni raccourci: elle vit sous /auth (voir plus bas)
# et n'est liée depuis aucune page.
declare -A SHORT_ROUTES=(
  [legal]="mentions-legales.html"
  [contact]="contact.html"
  [print]="print.html"
  [explain]="explain.html"
  [doc]="api-fonctionnement.html"
  [test-api]="test-api.html"
  [xplore]="xplore.html"
)
if [[ -f "$HTACCESS" ]] && grep -Fq 'RewriteRule ^tox/?$ tox.html [L]' "$HTACCESS"; then
  sed -i '/^# \/tox$/,/^RewriteRule \^tox\/?\$ tox\.html \[L\]$/d' "$HTACCESS"
  log_ok "Ancienne règle /tox retirée de .htaccess."
fi
for route in "${!SHORT_ROUTES[@]}"; do
  target="${SHORT_ROUTES[$route]}"
  if grep -Fq "$target" "$HTACCESS"; then
    log_info "Règle /$route (-> $target) déjà présente dans .htaccess."
  else
    printf '\n# /%s\nRewriteEngine On\nRewriteRule ^%s/?$ %s [L]\n' "$route" "$route" "$target" >> "$HTACCESS"
    log_ok "Règle /$route ajoutée dans .htaccess."
  fi
done

log_step "Configuration .htaccess (/health vers l'API PHP)"
# /health est la seule route hors /api/: elle est réécrite vers le point d'entrée de l'API
# (api/.htaccess ne voit que ce qui commence par /api/). L'URL reste /health, sans .php.
HEALTH_MARKER="# /health (API PHP)"
if grep -Fq "$HEALTH_MARKER" "$HTACCESS"; then
  log_info "Règle /health déjà présente dans .htaccess."
else
  printf '\n%s\nRewriteEngine On\nRewriteRule ^health/?$ api/index.php [L]\n' "$HEALTH_MARKER" >> "$HTACCESS"
  log_ok "Règle /health ajoutée dans .htaccess."
fi

log_step "Configuration .htaccess (/auth vers l'API PHP)"
# L'administration du site (connexion Google, mesures, mises à jour) répond sous /auth, point
# d'entrée volontairement discret et lié depuis aucune page. Comme /health, la règle est ici et non
# dans api/.htaccess (qui ne voit que ce qui commence par /api/). Sans effet visible tant que la
# configuration Google n'est pas renseignée: l'API répond alors le 404 générique.
AUTH_MARKER="# /auth (API PHP)"
if grep -Fq "$AUTH_MARKER" "$HTACCESS"; then
  log_info "Règle /auth déjà présente dans .htaccess."
else
  printf '\n%s\nRewriteEngine On\nRewriteRule ^auth(/.*)?$ api/index.php [L]\n' "$AUTH_MARKER" >> "$HTACCESS"
  log_ok "Règle /auth ajoutée dans .htaccess."
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

log_step "Configuration de l'API (api/.runtime.env)"
# Mêmes noms de variables que pour le backend Node: le .env existant fonctionne tel quel.
# Seules les variables d'exécution y sont copiées (jamais GIT_TOKEN ni les chemins de déploiement),
# et seulement si elles sont renseignées: sinon c'est le défaut du code qui s'applique.
# Le fichier doit être en 600 dès sa création: une redirection simple le crée en 644
# et laisse une fenêtre où GOOGLE_CLIENT_SECRET/SMTP_PASS sont lisibles par tout le monde.
runtime_line() {
  local name="$1" value="${!1:-}"
  [[ -n "$value" ]] || return 0
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    log_warn "$name contient un saut de ligne: variable ignorée." >&2
    return 0
  fi
  # Guillemets doubles, ou simples si la valeur contient déjà des guillemets doubles (mot
  # de passe SMTP, par ex.): le lecteur de .env d'api/ coupe à la première guillemet fermante.
  if [[ "$value" != *\"* ]]; then
    printf '%s="%s"\n' "$name" "$value"
  elif [[ "$value" != *\'* ]]; then
    printf "%s='%s'\n" "$name" "$value"
  else
    log_warn "$name contient à la fois des guillemets simples et doubles: variable ignorée (à définir directement dans api/.env)." >&2
  fi
}
RUNTIME_VARS=(
  API_CORS_ORIGIN TRUSTED_PROXIES ADMIN_MEASUREMENTS_ENABLED SITE_PUBLIC_URL
  RECAPTCHA_SECRET_KEY RECAPTCHA_SITE_KEY
  SMTP_HOST SMTP_PORT SMTP_SECURE SMTP_USER SMTP_PASS SMTP_FROM CONTACT_DEST SMTP_TIMEOUT_MS
  SFMN_MODE_ENABLED SFMN_CALCULATOR_URL SFMN_DEBUG
  RESTRICTION_ROUNDING_MODE CALCULATE_RATE_LIMIT RATE_LIMIT_BACKEND
  MEASUREMENT_LOGGING_LEVEL LOGS_RETENTION_MONTHS LOGS_MAX_BYTES
  API_PUBLIC_URL ADMIN_SITE_ENABLED ADMIN_UPDATE_ENABLED ADMIN_GOOGLE_EMAILS GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET
  UPDATE_GITHUB_REPO UPDATE_GITHUB_TOKEN
)
# TRUSTED_PROXIES vide est une valeur valable (= ne jamais croire X-Forwarded-For): runtime_line
# l'omettrait; on le réécrit explicitement s'il est défini mais vide.
install -m 600 /dev/null "$API_DIR/.runtime.env"
{
  echo "# Généré par deploy-php.sh à chaque déploiement — ne pas éditer ici (utiliser le .env de déploiement"
  echo "# ou, pour un réglage purement local, api/.env qui n'est jamais écrasé)."
  for _name in "${RUNTIME_VARS[@]}"; do
    if [[ "$_name" == "TRUSTED_PROXIES" ]]; then
      if [[ -n "${TRUSTED_PROXIES+x}" ]]; then printf 'TRUSTED_PROXIES="%s"\n' "$TRUSTED_PROXIES"; fi
    else
      runtime_line "$_name"
    fi
  done
} > "$API_DIR/.runtime.env"
chmod 600 "$API_DIR/.runtime.env"
log_ok "api/.runtime.env généré et protégé (600)."

# Les données d'exécution (mesures, compteurs) ne doivent jamais être lisibles par HTTP: le
# code d'api/ pose lui-même un .htaccess dans logs/ et var/ à la création, on le fait aussi ici
# pour couvrir un dossier créé avant ce déploiement.
for _dir in logs var; do
  mkdir -p "$API_DIR/$_dir"
  chmod 750 "$API_DIR/$_dir"
  [[ -f "$API_DIR/$_dir/.htaccess" ]] || printf 'Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n' > "$API_DIR/$_dir/.htaccess"
done

log_step "Vérification de l'ancienne supervision Node"
# L'ancien cron relance Node dès que /health ne répond plus sur le port 3003: après la bascule il
# redémarrerait en boucle un process devenu inutile. On ne touche pas au crontab sans demande
# explicite (comme pour .htaccess): on signale seulement.
if command -v crontab >/dev/null 2>&1 && crontab -l 2>/dev/null | grep -Fq 'monitor_node.sh'; then
  log_warn "Une entrée cron monitor_node.sh existe encore. À supprimer (crontab -e), elle n'a plus d'objet:"
  log_warn "  $(crontab -l 2>/dev/null | grep -F 'monitor_node.sh' | head -1)"
else
  log_info "Aucun cron monitor_node.sh trouvé."
fi

popd >/dev/null

log_ok "Déploiement terminé. Site: ${SITE_PUBLIC_URL} | API: ${API_PUBLIC_URL}"

log_step "Contrôle de l'API déployée"
# Non bloquant: l'URL publique peut ne pas encore pointer vers ce serveur (DNS, bascule en cours).
if command -v curl >/dev/null 2>&1; then
  if curl -fsS --max-time 10 "${API_PUBLIC_URL%/}/config" >/dev/null 2>&1; then
    log_ok "GET ${API_PUBLIC_URL%/}/config répond."
  else
    log_warn "GET ${API_PUBLIC_URL%/}/config ne répond pas (encore Node ? DNS ? version PHP du site ?). Voir README, section bascule."
  fi
fi
