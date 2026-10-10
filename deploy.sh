#!/usr/bin/env bash
# Déploiement/mise à jour idempotent : front-end public (dépôt: public/) + backend privé (dépôt: backend/).
# PHP s'exécute par requête sous Apache: déployer = copier les fichiers. Le front-end va dans FRONTEND_DIR (la racine
# web, avec la façade api/index.php) ; le backend (code, configuration, données) dans BACKEND_DIR, par défaut
# <FRONTEND_DIR>/backend (protégé par .htaccess), idéalement hors de la racine web.
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

# Le site en ligne n'a pas accès à ce .env : le dépôt à mettre à jour lui est transmis (backend/config/runtime.env).
# Déduit de GIT_REPO (https://github.com/propriétaire/nom[.git]) sauf si UPDATE_GITHUB_REPO est fourni.
if [[ -z "${UPDATE_GITHUB_REPO:-}" && "$GIT_REPO" =~ ^https://([^@/]+@)?github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/?$ ]]; then
  UPDATE_GITHUB_REPO="${BASH_REMATCH[2]%.git}"
fi

GIT_BRANCH="${GIT_BRANCH:-main}"
FRONTEND_DIR="${FRONTEND_DIR:-$HOME/public_html/frontend}"
# Backend : par défaut dans la racine web (protégé par son .htaccess), modifiable (chemin hors de la racine conseillé).
FRONTEND_DIR="${FRONTEND_DIR/#\~/$HOME}"
BACKEND_DIR="${BACKEND_DIR:-$FRONTEND_DIR/backend}"
# Clone du dépôt : jamais dans la racine web.
CHECKOUT_DIR="${CHECKOUT_DIR:-$HOME/radiv-repo}"
# Étend un éventuel ~ initial même si la valeur était entre guillemets dans .env
BACKEND_DIR="${BACKEND_DIR/#\~/$HOME}"
CHECKOUT_DIR="${CHECKOUT_DIR/#\~/$HOME}"
SITE_PUBLIC_URL="${SITE_PUBLIC_URL:-https://www.example.org}"
# Par défaut l'API est sous le site (/api): une URL d'exemple écrite en dur se retrouverait dans config.js
# et dans la configuration de l'API si le .env ne définit pas API_PUBLIC_URL.
API_PUBLIC_URL="${API_PUBLIC_URL:-${SITE_PUBLIC_URL%/}/api}"
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
log_info "Racine web: $FRONTEND_DIR | backend: $BACKEND_DIR | clone: $CHECKOUT_DIR"
# Garde-fous : le backend ne peut être ni la racine web ni un dossier qui la contient (ni inversement pour le clone).
case "$FRONTEND_DIR/" in "$BACKEND_DIR/"*) log_warn "BACKEND_DIR ($BACKEND_DIR) ne peut pas contenir la racine web. Abandon."; exit 1;; esac
if [[ "$BACKEND_DIR" == "$FRONTEND_DIR" || "$BACKEND_DIR" == "$FRONTEND_DIR/api" || "$BACKEND_DIR" == "$FRONTEND_DIR/api/"* ]]; then
  log_warn "BACKEND_DIR ($BACKEND_DIR) est invalide (racine web ou dossier api/). Abandon."; exit 1
fi
case "$CHECKOUT_DIR/" in "$FRONTEND_DIR/"*) log_warn "CHECKOUT_DIR ($CHECKOUT_DIR) ne doit pas être dans la racine web. Abandon."; exit 1;; esac
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
    log_warn "php en ligne de commande est en $php_version (< 8.0): le code du backend exige PHP 8.0+. Abandon."
    log_warn "Vérifiez aussi la version PHP choisie pour le site dans cPanel (Sélecteur PHP)."
    exit 1
  fi
  syntax_errors=0
  while IFS= read -r -d '' phpfile; do
    if ! php -l "$phpfile" >/dev/null 2>&1; then
      log_warn "Erreur de syntaxe PHP: $phpfile"
      syntax_errors=$((syntax_errors + 1))
    fi
  done < <(find public backend -name '*.php' -print0)
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
# Source: public/ du dépôt. Les fichiers non listés ici ne sont jamais supprimés (pas de --delete-excluded) ;
# api/ ne reçoit que la façade (index.php, .htaccess), jamais api/backend.php (emplacement du backend, généré plus bas).
rsync -a --delete \
  --filter='P .htaccess' \
  --filter='P .user.ini' \
  --filter='P api/backend.php' \
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
  --include='vendor/' \
  --include='vendor/**' \
  --include='api/' \
  --include='api/index.php' \
  --include='api/.htaccess' \
  --exclude='*' \
  "$CHECKOUT_DIR/public/" "$FRONTEND_DIR/"

log_ok "Frontend synchronisé vers $FRONTEND_DIR"

log_step "Synchronisation du backend"
mkdir -p "$BACKEND_DIR"
# Protégés contre --delete (jamais écrasés ni supprimés): la configuration (config/) et les données d'exécution
# (data/ : mesures RGPD, sessions, état des mises à jour). Un dossier non vide qui n'est pas un backend est refusé.
if [[ -n "$(ls -A "$BACKEND_DIR" 2>/dev/null)" && ! -f "$BACKEND_DIR/src/app.php" ]]; then
  log_warn "BACKEND_DIR=$BACKEND_DIR n'est pas vide et ne contient pas de backend (src/app.php). Abandon (rsync --delete effacerait son contenu)."
  exit 1
fi
rsync -a --delete \
  --filter='P config/' \
  --filter='P data/' \
  "$CHECKOUT_DIR/backend/" "$BACKEND_DIR/"
log_ok "Backend synchronisé vers $BACKEND_DIR"
# Emplacement du backend pour la façade publique (toujours écrit, explicite).
printf '<?php\n// Généré par deploy.sh : emplacement du backend.\nreturn %s;\n' "'${BACKEND_DIR//\'/\\\'}'" > "$FRONTEND_DIR/api/backend.php"

log_step "Configuration .htaccess (raccourcis de pages sans .html)"
HTACCESS="$FRONTEND_DIR/.htaccess"
if [[ ! -f "$HTACCESS" ]]; then
  printf 'Options -Indexes\nRewriteEngine On\n' > "$HTACCESS"
  log_ok ".htaccess créé."
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

log_step "Configuration .htaccess (HTTPS et en-têtes de sécurité des pages)"
# Redirection HTTP -> HTTPS (308: la méthode et le corps d'un POST sont conservés) placée en TÊTE du fichier, pour
# passer avant les autres règles; uniquement si le site est déclaré en https. En-têtes de sécurité posés seulement
# s'ils ne le sont pas déjà (setifempty): l'API et /auth envoient les leurs, plus stricts, qui restent prioritaires.
HTTPS_MARKER="# /https (redirection HTTP vers HTTPS) v1"
if [[ "$SITE_PUBLIC_URL" == https://* ]]; then
  if grep -Fq "$HTTPS_MARKER" "$HTACCESS"; then
    log_info "Redirection HTTPS déjà présente dans .htaccess."
  else
    _tmp_ht="$(mktemp)"
    printf '%s\nRewriteEngine On\nRewriteCond %%{HTTPS} off\nRewriteCond %%{HTTP:X-Forwarded-Proto} !https\nRewriteRule ^ https://%%{HTTP_HOST}%%{REQUEST_URI} [L,R=308]\n\n' "$HTTPS_MARKER" > "$_tmp_ht"
    cat "$HTACCESS" >> "$_tmp_ht" && cat "$_tmp_ht" > "$HTACCESS" && rm -f "$_tmp_ht"
    log_ok "Redirection HTTP -> HTTPS ajoutée en tête de .htaccess."
  fi
fi
# Backend dans la racine web : son adresse est interdite par une règle en tête de .htaccess (en plus de son propre
# .htaccess). Fichiers de configuration éventuellement copiés dans la racine (.env, .runtime.env) : jamais servis.
if [[ "$BACKEND_DIR" == "$FRONTEND_DIR/"* ]]; then
  _backend_rel="${BACKEND_DIR#"$FRONTEND_DIR"/}"
  BACKEND_DENY_MARKER="# /${_backend_rel} (backend : accès refusé) v1"
  if grep -Fq "$BACKEND_DENY_MARKER" "$HTACCESS"; then
    log_info "Règle d'interdiction du backend déjà présente dans .htaccess."
  else
    _tmp_ht="$(mktemp)"
    printf '%s\nRewriteEngine On\nRewriteRule ^%s(/|$) - [F,L]\n\n' "$BACKEND_DENY_MARKER" "$(sed 's/[][\.^$*+?(){}|]/\\&/g' <<< "$_backend_rel")" > "$_tmp_ht"
    cat "$HTACCESS" >> "$_tmp_ht" && cat "$_tmp_ht" > "$HTACCESS" && rm -f "$_tmp_ht"
    log_ok "Backend (/${_backend_rel}) interdit d'accès HTTP dans .htaccess."
  fi
fi
CONFIG_DENY_MARKER="# Fichiers de configuration refusés v1"
if ! grep -Fq "$CONFIG_DENY_MARKER" "$HTACCESS"; then
  printf '\n%s\n<FilesMatch "^\\.(env|runtime\\.env)$">\nRequire all denied\n</FilesMatch>\n' "$CONFIG_DENY_MARKER" >> "$HTACCESS"
fi
HEADERS_MARKER="# En-têtes de sécurité des pages v1"
if grep -Fq "$HEADERS_MARKER" "$HTACCESS"; then
  log_info "En-têtes de sécurité déjà présents dans .htaccess."
else
  {
    printf '\n%s\n<IfModule mod_headers.c>\n' "$HEADERS_MARKER"
    printf 'Header setifempty X-Content-Type-Options "nosniff"\nHeader setifempty X-Frame-Options "DENY"\n'
    printf 'Header setifempty Referrer-Policy "strict-origin-when-cross-origin"\n'
    printf 'Header setifempty Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"\n'
    printf 'Header setifempty Content-Security-Policy "frame-ancestors '"'"'none'"'"'; base-uri '"'"'self'"'"'; object-src '"'"'none'"'"'; form-action '"'"'self'"'"'"\n'
    [[ "$SITE_PUBLIC_URL" == https://* ]] && printf 'Header setifempty Strict-Transport-Security "max-age=31536000"\n'
    printf '</IfModule>\n'
  } >> "$HTACCESS"
  log_ok "En-têtes de sécurité ajoutés dans .htaccess."
fi

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

log_step "Configuration de l'API (backend/config/runtime.env)"
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
    log_warn "$name contient à la fois des guillemets simples et doubles: variable ignorée (à définir directement dans backend/config/local.env)." >&2
  fi
}
# Description du fichier : sections, variables dans l'ordre, commentaires (lignes « | » = suite du commentaire).
# Strictement identique à celle de install.php (tests/check-runtime-layout.js le vérifie).
RUNTIME_LAYOUT="$(cat <<'LAYOUT'
== Site et API
SITE_PUBLIC_URL|URL publique du site, sans « / » final (ex. https://www.exemple.fr).
|Sert à l'URL de retour Google (/auth/callback) et au contrôle d'origine des formulaires.
API_PUBLIC_URL|URL publique de l'API. Facultatif : par défaut SITE_PUBLIC_URL suivi de /api.
SITE_NAME|Nom affiché dans le bandeau du site. Facultatif : par défaut le nom de domaine de SITE_PUBLIC_URL (sans www).
COPYRIGHT_OWNER|Propriétaire affiché en bas de page (© année propriétaire). Facultatif : par défaut SITE_NAME.
API_CORS_ORIGIN|Origine autorisée à appeler l'API depuis un navigateur (en général l'adresse du site).
|« * » l'ouvre à tous les sites : à éviter.
TRUSTED_PROXIES|Adresses des proxys autorisés à fournir l'IP réelle du visiteur (en-tête X-Forwarded-For),
|séparées par des virgules. Vide = ne jamais croire cet en-tête. Non défini = 127.0.0.1,::1.
== Emplacement des données
DATA_DIR|Dossier des journaux et de l'état du site (sessions, version installée, sauvegarde). Facultatif :
|par défaut le dossier data/ du backend. Chemin absolu, ou relatif au backend ; jamais lisible depuis le web.
== Administration du site (/auth, connexion Google)
GOOGLE_CLIENT_ID|Identifiant du client OAuth créé dans Google Cloud (type « Application Web »).
GOOGLE_CLIENT_SECRET|Secret du client OAuth (confidentiel).
ADMIN_GOOGLE_EMAILS|Adresses Google autorisées à se connecter à /auth, séparées par des virgules.
|Comparaison exacte : ni domaine entier, ni joker.
ADMIN_SITE_ENABLED|true ou false. false coupe entièrement /auth (réponse 404). Défaut : true.
ADMIN_MEASUREMENTS_ENABLED|true ou false. false retire la consultation des mesures de /auth. Défaut : true.
== Mises à jour depuis /auth
ADMIN_UPDATE_ENABLED|true ou false. Autorise les mises à jour du site depuis /auth. Défaut : false.
UPDATE_GITHUB_REPO|Dépôt GitHub à télécharger lors d'une mise à jour (propriétaire/nom).
UPDATE_GITHUB_TOKEN|Jeton GitHub en lecture seule (confidentiel). Non défini = /auth en demande un à chaque
|mise à jour et ne le conserve pas.
== Formulaire de contact
RECAPTCHA_SITE_KEY|Clé publique reCAPTCHA v3 (chargée dans la page de contact).
RECAPTCHA_SECRET_KEY|Clé secrète reCAPTCHA v3 (confidentielle). Sans elle, le formulaire refuse d'envoyer.
RECAPTCHA_MIN_SCORE|Score minimal reCAPTCHA v3 accepté, de 0 (robot) à 1 (humain). Défaut : 0.5.
SMTP_HOST|Serveur SMTP qui envoie les messages du formulaire.
SMTP_PORT|Port SMTP : 587 (STARTTLS) ou 465 (TLS direct).
SMTP_SECURE|true = TLS direct (port 465), false = STARTTLS (port 587).
SMTP_USER|Identifiant SMTP.
SMTP_PASS|Mot de passe SMTP (confidentiel).
SMTP_FROM|Expéditeur affiché, ex. Site <no-reply@exemple.fr>.
CONTACT_DEST|Adresse qui reçoit les messages du formulaire.
SMTP_TIMEOUT_MS|Délai maximal des échanges SMTP, en millisecondes. Défaut : 15000.
== Calcul des durées de restriction
RESTRICTION_ROUNDING_MODE|round = jour le plus proche (défaut) ; floor = troncature, identique à l'outil SFMN de référence.
SFMN_MODE_ENABLED|true ou false. Active le mode « calcul SFMN » (interroge un site distant). Défaut : true.
|false = calcul local seul.
SFMN_CALCULATOR_URL|Adresse du calculateur SFMN distant (utile seulement si le mode SFMN est actif).
SFMN_DEBUG|true ou false. Diagnostic détaillé du mode SFMN : expose des données distantes dans les réponses,
|à laisser sur false. Défaut : false.
== Mesures et journaux (RGPD)
MEASUREMENT_LOGGING_LEVEL|full = tout (IP, géolocalisation, données saisies, résultat) ; user = date, IP et lieu
|seulement ; none = rien. Défaut : full.
LOGS_RETENTION_MONTHS|Supprime les journaux plus vieux que N mois. Non défini = 12 mois. 0 = conservation illimitée (à justifier).
LOGS_MAX_BYTES|Taille maximale d'un journal mensuel, en octets. Défaut : 52428800 (50 Mo).
== Protection contre les abus
CALCULATE_RATE_LIMIT|Nombre maximal de calculs par minute et par adresse IP. Défaut : 60.
SFMN_GLOBAL_RATE_LIMIT|Nombre maximal d'interrogations du site SFMN par minute, tous visiteurs confondus. Défaut : 120.
RATE_LIMIT_BACKEND|Stockage des compteurs : auto (APCu si disponible, sinon fichiers), apcu ou file. Défaut : auto.
== Essais en local uniquement
ADMIN_ALLOW_INSECURE_HTTP|true autorise /auth en http (essais sur ordinateur). NE JAMAIS l'activer en production.
LAYOUT
)"
# Écrit « NOM="valeur" » (ou « NOM='valeur' » si la valeur contient des guillemets doubles), rien si la
# variable n'est pas définie. Retourne 1 si rien n'a été écrit.
runtime_assignment() {
  local name="$1" value="${!1:-}"
  # TRUSTED_PROXIES vide est une valeur valable (= ne jamais croire X-Forwarded-For).
  if [[ "$name" == "TRUSTED_PROXIES" && -n "${TRUSTED_PROXIES+x}" && -z "$value" ]]; then
    printf 'TRUSTED_PROXIES=""\n'
    return 0
  fi
  [[ -n "$value" ]] || return 1
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    log_warn "$name contient un saut de ligne: variable ignorée." >&2
    return 1
  fi
  # Guillemets doubles, ou simples si la valeur contient déjà des guillemets doubles (mot de passe
  # SMTP, par ex.): le lecteur de .env d'api/ coupe à la première guillemet fermante.
  if [[ "$value" != *\"* ]]; then
    printf '%s="%s"\n' "$name" "$value"
  elif [[ "$value" != *\'* ]]; then
    printf "%s='%s'\n" "$name" "$value"
  else
    log_warn "$name contient à la fois des guillemets simples et doubles: variable ignorée (à définir directement dans backend/config/local.env)." >&2
    return 1
  fi
}
render_runtime_env() {
  echo "# Configuration du site. Lue à CHAQUE requête : toute modification est prise en compte immédiatement."
  echo "# Généré par deploy.sh le $(date '+%Y-%m-%d %H:%M') — chaque déploiement RÉÉCRIT ce fichier à partir du .env"
  echo "# de déploiement : reportez-y vos changements durables. backend/config/local.env reste pour les réglages purement locaux."
  echo "#"
  echo "# Une ligne qui commence par # est un commentaire. Une variable écrite « #NOM= » n'est pas définie : le site"
  echo "# utilise alors sa valeur par défaut. Pour la définir, retirez le # et mettez la valeur entre guillemets."
  local line first=1 name comment pending_name="" pending_comment="" assignment
  flush_var() {
    [[ -n "$pending_name" ]] || return 0
    echo
    printf '%s\n' "$pending_comment"
    if assignment="$(runtime_assignment "$pending_name")"; then printf '%s\n' "$assignment"; else printf '#%s=\n' "$pending_name"; fi
    pending_name=""; pending_comment=""
  }
  while IFS= read -r line; do
    if [[ "$line" == "== "* ]]; then
      flush_var
      echo; echo
      echo "# ------------------------------------------------------------------------------------------"
      echo "# ${line#== }"
      echo "# ------------------------------------------------------------------------------------------"
    elif [[ "$line" == "|"* ]]; then
      pending_comment+=$'\n'"# ${line#|}"
    else
      flush_var
      pending_name="${line%%|*}"
      pending_comment="# ${line#*|}"
    fi
  done <<< "$RUNTIME_LAYOUT"
  flush_var
}
mkdir -p "$BACKEND_DIR/config"
install -m 600 /dev/null "$BACKEND_DIR/config/runtime.env"
render_runtime_env > "$BACKEND_DIR/config/runtime.env"
chmod 600 "$BACKEND_DIR/config/runtime.env"
log_ok "backend/config/runtime.env généré et protégé (600)."

# Les données d'exécution (mesures, sessions, état) ne doivent jamais être lisibles par HTTP: le code du backend
# pose lui-même un .htaccess dans chaque dossier de données à la création, on le fait aussi ici pour couvrir un
# dossier créé avant ce déploiement.
DATA_PATH="${DATA_DIR:-$BACKEND_DIR/data}"
[[ "$DATA_PATH" == /* ]] || DATA_PATH="$BACKEND_DIR/$DATA_PATH"
for _dir in "$BACKEND_DIR/config" "$DATA_PATH" "$DATA_PATH/logs" "$DATA_PATH/var"; do
  mkdir -p "$_dir"
  chmod 750 "$_dir"
  [[ -f "$_dir/.htaccess" ]] || printf 'Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n' > "$_dir/.htaccess"
done

popd >/dev/null

log_ok "Déploiement terminé. Site: ${SITE_PUBLIC_URL} | API: ${API_PUBLIC_URL}"

log_step "Contrôle de la protection du backend"
# Le backend ne doit JAMAIS être lisible par HTTP quand il est dans la racine web (contrôle non bloquant: l'URL publique
# peut ne pas pointer encore vers ce serveur).
if [[ "$BACKEND_DIR" == "$FRONTEND_DIR/"* ]] && command -v curl >/dev/null 2>&1; then
  _probe_name="probe-$$.txt"
  printf 'x' > "$BACKEND_DIR/config/$_probe_name"
  _probe_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "${SITE_PUBLIC_URL%/}/${BACKEND_DIR#"$FRONTEND_DIR"/}/config/$_probe_name" || true)"
  rm -f "$BACKEND_DIR/config/$_probe_name"
  case "$_probe_code" in
    200) log_warn "ATTENTION: le backend est LISIBLE par HTTP. Placez BACKEND_DIR hors de la racine web et redéployez."; exit 1;;
    000|"") log_info "Protection du backend non vérifiable depuis ce serveur.";;
    *) log_ok "Backend illisible par HTTP (HTTP $_probe_code).";;
  esac
else
  log_info "Backend hors de la racine web ou curl absent: contrôle HTTP sans objet."
fi

log_step "Contrôle de l'API déployée"
# Non bloquant: l'URL publique peut ne pas encore pointer vers ce serveur (DNS, bascule en cours).
if command -v curl >/dev/null 2>&1; then
  if curl -fsS --max-time 10 "${API_PUBLIC_URL%/}/config" >/dev/null 2>&1; then
    log_ok "GET ${API_PUBLIC_URL%/}/config répond."
  else
    log_warn "GET ${API_PUBLIC_URL%/}/config ne répond pas (DNS ? version PHP du site ?)."
  fi
fi
