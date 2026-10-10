<?php
// ============================================================================================
// INSTALLATION INITIALE DU SITE (à envoyer seul par FTP, à la racine web, puis ouvrir dans un
// navigateur : https://<votre-domaine>/install.php).
//
// Ce fichier n'est JAMAIS publié par deploy.sh ni par la page /auth : il ne sert
// qu'une fois. Il télécharge le dépôt GitHub privé (archive ZIP du commit demandé), valide tout
// dans un dossier de préparation, installe le site et l'API, génère la configuration puis SE
// SUPPRIME. Les mises à jour suivantes se font depuis https://<votre-domaine>/auth.
//
// AVANT DE L'ENVOYER : remplacez la clé ci-dessous par une valeur secrète d'au moins 24 caractères
// (par exemple le résultat de `openssl rand -hex 24`). Sans cette clé, la page refuse tout. La clé
// est demandée à chaque étape ; ne la communiquez à personne et ne laissez pas ce fichier en ligne
// après l'installation (il se supprime seul ; sinon, supprimez-le par FTP).
//
// Écrit en PHP 7.0 volontairement : sur un hébergement resté en PHP 5/7, la page doit pouvoir
// afficher un message clair au lieu d'une erreur blanche. Le site lui-même exige PHP 8.0 ou plus.
// ============================================================================================

const INSTALL_KEY = 'CHANGEZ-MOI';
// Fichier .env de déploiement lu par l'installeur s'il existe. Formes acceptées : relatif au dossier du
// site ('../.env'), absolu ('/home/utilisateur/prive/.env') ou depuis le dossier personnel ('~/prive/.env').
// Par défaut : le dossier PARENT du site (chez OVH, au-dessus de www/), non accessible depuis le web.
// Ce chemin n'est pas un secret ; le jeton GitHub, lui, ne doit JAMAIS être écrit dans ce fichier-ci (install.php) :
// mettez-le dans le .env (UPDATE_GITHUB_TOKEN) ou saisissez-le dans la page. Mettre '' pour désactiver la lecture.
// Un fichier placé dans le dossier public serait lisible par tous : il est alors supprimé après l'installation.
const ENV_FILE = '../.env';
const GITHUB_API = 'https://api.github.com';   // modifié uniquement par les tests (faux GitHub local)
const DEFAULT_REPO = 'giceyBpro/Radiv';
const DEFAULT_SFMN_URL = 'https://www.acoramen.net/index.php?option=com_evictionperiod&Itemid=5142&lang=fr';

// Même liste blanche que backend/src/lib/updater.php et deploy.sh (tests/test-install.js vérifie
// qu'elles restent identiques). Chemins du DÉPÔT : « public/... » va dans la racine web, « backend/... » dans le
// dossier du backend. downloads/, vendor/ et backend/ sont traités par préfixe dans allowed().
$FRONTEND_FILES = array(
    'index.html', 'v1.html', 'app.js', 'print.html', 'explain.html', 'contact.html',
    'mentions-legales.html', 'api-fonctionnement.html', 'test-api.html', 'xplore.html',
    'favicon.ico', 'robots.txt',
);
$REQUIRED_FILES = array(
    'public/index.html', 'public/api/.htaccess', 'public/api/index.php',
    'backend/src/app.php', 'backend/src/calculation.php', 'backend/src/lib/config.php',
    'backend/src/lib/updater.php', 'backend/src/lib/admin_site.php', 'backend/src/lib/google.php', 'backend/src/lib/session.php',
);
$TEXT_FIELDS = array('repo', 'ref', 'site_url', 'google_id', 'google_secret', 'admin_emails', 'recaptcha_site', 'recaptcha_secret', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'contact_dest', 'rounding', 'logging', 'retention', 'logs_mb', 'sfmn_url', 'backend_dir');
$BOOL_FIELDS = array('update_enabled', 'smtp_secure', 'sfmn');
// Variables du .env transmises telles quelles à config/runtime.env (réglages sans champ de formulaire).
$PASSTHROUGH_VARS = array('API_CORS_ORIGIN', 'API_PUBLIC_URL', 'TRUSTED_PROXIES', 'ADMIN_MEASUREMENTS_ENABLED', 'ADMIN_SITE_ENABLED', 'CALCULATE_RATE_LIMIT', 'RATE_LIMIT_BACKEND', 'SFMN_DEBUG', 'SMTP_TIMEOUT_MS', 'SITE_NAME', 'COPYRIGHT_OWNER', 'DATA_DIR', 'RECAPTCHA_MIN_SCORE', 'SFMN_GLOBAL_RATE_LIMIT');
// Description de config/runtime.env (dans le backend) : sections, variables dans l'ordre, commentaires (lignes « | » = suite du
// commentaire). Strictement identique à celle de deploy.sh (tests/check-runtime-layout.js le vérifie).
$RUNTIME_LAYOUT = <<<'LAYOUT'
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
LAYOUT;
const MAX_ZIP_BYTES = 20971520;
const MAX_FILE_BYTES = 10485760;
const MAX_TOTAL_BYTES = 52428800;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
@ini_set('display_errors', '0');

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page($title, $body, $status = 200)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . '</title><style>'
        . 'body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#eef1f3;color:#111}'
        . 'main{max-width:760px;margin:24px auto;background:#fff;padding:24px 28px;border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12)}'
        . 'h1{margin-top:0;font-size:1.5rem}h2{margin:24px 0 8px;font-size:1.1rem}'
        . 'label{display:block;margin:10px 0 4px;font-weight:bold}input[type=text],input[type=password],input[type=email],select{display:block;width:100%;box-sizing:border-box;padding:8px;font:inherit}'
        . 'label.inline{font-weight:normal;display:flex;gap:8px;align-items:center}label.inline input{width:auto}'
        . 'button{margin-top:18px;padding:10px 20px;border:0;border-radius:6px;background:#1637b8;color:#fff;font:inherit;cursor:pointer}'
        . '.ok{color:#1b7a2e}.ko{color:#b00020;font-weight:bold}.small{font-size:.85rem;color:#555}.msg{padding:10px 12px;border-radius:6px;background:#fbe6e6;border:1px solid #e0a0a0}'
        . 'table{border-collapse:collapse;width:100%}td,th{border:1px solid #d5dde1;padding:6px;text-align:left;font-size:.92rem}code{background:#eef1f3;padding:1px 4px;border-radius:3px}'
        . '</style></head><body><main><h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

// --- Garde-fous d'accès ------------------------------------------------------------------------

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    page('PHP trop ancien', '<p class="msg">Cet hébergement utilise PHP ' . h(PHP_VERSION) . '. Le site exige PHP 8.0 ou plus : choisissez une version plus récente dans le panneau de l\'hébergeur, puis rechargez cette page.</p>', 500);
}
if (strlen(INSTALL_KEY) < 24 || INSTALL_KEY === 'CHANGEZ-MOI') {
    page('Installation non autorisée', '<p class="msg">Aucune clé d\'installation n\'est définie. Ouvrez <code>install.php</code> dans un éditeur, remplacez la valeur de <code>INSTALL_KEY</code> par une clé secrète d\'au moins 24 caractères, puis renvoyez le fichier par FTP.</p>', 403);
}
$docroot = rtrim(str_replace('\\', '/', __DIR__), '/');
if (is_file($docroot . '/api/.install-done')) {
    page('Introuvable', '<p>Cette page n\'existe pas.</p>', 404); // déjà installé: aucune information donnée
}
$isLocal = in_array(isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '', array('localhost', '127.0.0.1'), true);
$https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if (!$https && !$isLocal) {
    page('HTTPS requis', '<p class="msg">Des secrets (clé d\'installation, jeton, mots de passe) sont saisis dans cette page : ouvrez-la en <code>https://</code>.</p>', 403);
}

// Limite les essais de clé: 10 échecs par heure et par adresse (fichier temporaire du dossier).
function throttle_file()
{
    return __DIR__ . '/.install-attempts-' . substr(hash('sha256', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 12);
}
function throttled()
{
    $f = throttle_file();
    $data = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($data) && $data['count'] >= 10 && time() - $data['first'] < 3600;
}
function record_failure()
{
    $f = throttle_file();
    $data = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($data) || time() - $data['first'] >= 3600) $data = array('count' => 0, 'first' => time());
    $data['count']++;
    @file_put_contents($f, json_encode($data), LOCK_EX);
    sleep(2);
}

$step = isset($_POST['step']) ? (string) $_POST['step'] : '';
if ($step !== '') {
    if (throttled()) page('Trop d\'essais', '<p class="msg">Trop de clés incorrectes. Réessayez dans une heure.</p>', 429);
    $given = isset($_POST['key']) && is_string($_POST['key']) ? $_POST['key'] : '';
    if (!hash_equals(INSTALL_KEY, $given)) {
        record_failure();
        page('Clé incorrecte', '<p class="msg">Clé d\'installation incorrecte.</p>' . key_form(), 403);
    }
}

function key_form()
{
    return '<form method="post"><input type="hidden" name="step" value="check">'
        . '<label for="key">Clé d\'installation</label><input id="key" type="password" name="key" autocomplete="off" required>'
        . '<button type="submit">Vérifier l\'hébergement</button></form>';
}

if ($step === '') {
    page('Installation du site', '<p>Saisissez la clé d\'installation écrite dans le fichier <code>install.php</code>.</p>' . key_form());
}

// --- Outils ------------------------------------------------------------------------------------

function starts_with($s, $prefix)
{
    return strncmp($s, $prefix, strlen($prefix)) === 0;
}
function ends_with($s, $suffix)
{
    return $suffix === '' || substr($s, -strlen($suffix)) === $suffix;
}
function has_text($s, $needle)
{
    return $needle === '' || strpos($s, $needle) !== false;
}

function rrmdir($dir)
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path) && !is_link($path)) rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

function make_dir($dir)
{
    return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
}

function guard_dir($dir)
{
    if (!make_dir($dir)) return false;
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return true;
}

function gh_headers($token, $accept = 'application/vnd.github+json')
{
    return array('Authorization: Bearer ' . $token, 'Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: radiv-site-installer');
}

// Requête HTTP simple. Retourne array(status, body, location) ou null.
function http_get($url, $headers, $timeoutMs = 15000, $headOnly = false)
{
    $ch = curl_init($url);
    $location = null;
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT_MS => 8000,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_NOBODY => $headOnly,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$location) {
            if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
            return strlen($line);
        },
    ));
    $body = curl_exec($ch);
    if ($body === false) {
        curl_close($ch);
        return null;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return array($status, (string) $body, $location);
}

function download_to($url, $file, $maxBytes)
{
    $out = @fopen($file, 'wb');
    if (!$out) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_FILE => $out,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT_MS => 10000,
        CURLOPT_TIMEOUT_MS => 90000,
        CURLOPT_HTTPHEADER => array('User-Agent: radiv-site-installer'),
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($c, $dlTotal, $dlNow) use ($maxBytes) {
            return ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0;
        },
    ));
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($out);
    return $ok !== false && $status === 200;
}

// Seuls ces chemins (relatifs à la racine du dépôt) peuvent être écrits par l'installation.
function allowed($rel)
{
    global $FRONTEND_FILES;
    if ($rel === '' || $rel[0] === '/' || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false) return false;
    $parts = explode('/', $rel);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
    }
    $count = count($parts);
    if ($parts[0] === 'public') {
        $sub = implode('/', array_slice($parts, 1));
        if (in_array($sub, $FRONTEND_FILES, true)) return true;
        if ($sub === 'api/index.php' || $sub === 'api/.htaccess') return true; // la façade, et rien d'autre dans api/
        if ($count === 3 && $parts[1] === 'downloads') {
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $parts[2]) === 1;
        }
        if ($parts[1] === 'vendor') { // polices et bibliothèques hébergées sur le site (vendor/ ou vendor/fonts/)
            if ($count < 3 || $count > 4) return false;
            // Jamais de script serveur ici : seuls les fichiers statiques d'extension connue sont acceptés.
            if ($count === 4 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $parts[2]) !== 1) return false;
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:css|js|woff2?|txt|map)$/', $parts[$count - 1]) === 1;
        }
        return false;
    }
    if ($parts[0] === 'backend') {
        if ($rel === 'backend/.htaccess') return true;
        // Code : src/*.php et src/lib/*.php. Ressources de l'administration : assets/<fichier statique>.
        // Jamais config/ ni data/ (réglages et données du site).
        if ($parts[1] === 'src' && ($count === 3 || ($count === 4 && $parts[2] === 'lib'))) {
            return preg_match('/^[A-Za-z0-9_-]+\.php$/', $parts[$count - 1]) === 1;
        }
        if ($parts[1] === 'assets' && $count === 3) {
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.(?:css|js|txt|png|svg|woff2?)$/', $parts[2]) === 1;
        }
        return false;
    }
    return false;
}

// Destination d'un chemin du dépôt : public/ → racine web, backend/ → dossier du backend.
function dest_of($rel, $docroot, $backendDir)
{
    if (strpos($rel, 'public/') === 0) return $docroot . '/' . substr($rel, 7);
    if (strpos($rel, 'backend/') === 0) return $backendDir . '/' . substr($rel, 8);
    throw new RuntimeException('Chemin hors liste blanche : ' . $rel);
}

function extract_archive($zipFile, $staging, $sha)
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) throw new RuntimeException('Archive illisible.');
    if ($zip->numFiles > 2000) throw new RuntimeException('Archive refusée (trop de fichiers).');
    $files = array();
    $root = null;
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $segments = explode('/', $name, 2);
        if ($root === null) $root = $segments[0];
        if ($segments[0] !== $root) throw new RuntimeException('Archive invalide (plusieurs dossiers racines).');
        if (!has_text($root, substr($sha, 0, 7))) throw new RuntimeException('Archive ne correspondant pas au commit demandé.');
        if (strpos($name, "\0") !== false || strpos($name, '\\') !== false || starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
            throw new RuntimeException('Archive refusée (chemin invalide).');
        }
        if (ends_with($name, '/') || !isset($segments[1])) continue;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
            throw new RuntimeException('Archive refusée (lien symbolique).');
        }
        $rel = $segments[1];
        if (!allowed($rel)) continue;
        $stat = $zip->statIndex($i);
        $size = isset($stat['size']) ? (int) $stat['size'] : -1;
        $total += $size;
        if ($size < 0 || $size > MAX_FILE_BYTES || $total > MAX_TOTAL_BYTES) throw new RuntimeException('Archive refusée (taille).');
        $dest = $staging . '/' . $rel;
        make_dir(dirname($dest));
        $in = $zip->getStream($name);
        $out = @fopen($dest, 'wb');
        if (!$in || !$out) throw new RuntimeException('Extraction impossible.');
        $written = stream_copy_to_stream($in, $out, MAX_FILE_BYTES + 1);
        fclose($in);
        fclose($out);
        if ($written !== $size) throw new RuntimeException('Archive corrompue.');
        $files[] = $rel;
    }
    $zip->close();
    sort($files);
    return $files;
}

function lint_php($staging, $files)
{
    foreach ($files as $rel) {
        if (!ends_with($rel, '.php')) continue;
        try {
            token_get_all((string) file_get_contents($staging . '/' . $rel), TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new RuntimeException('Erreur de syntaxe PHP dans ' . $rel . '.');
        }
    }
}

// Valeur de .env : entre guillemets doubles, ou simples si elle contient des guillemets doubles.
function env_line($name, $value)
{
    $value = (string) $value;
    if ($value === '') return '';
    if (strpbrk($value, "\r\n\0") !== false) throw new RuntimeException($name . ' contient un caractère interdit.');
    if (strpos($value, '"') === false) return $name . '="' . $value . '"' . "\n";
    if (strpos($value, "'") === false) return $name . "='" . $value . "'\n";
    throw new RuntimeException($name . ' ne peut pas contenir à la fois des guillemets simples et doubles.');
}

// --- Étape 1 : diagnostic de l'hébergement ------------------------------------------------------

function diagnostics($docroot)
{
    $rows = array();
    $add = function ($label, $ok, $detail, $critical = true) use (&$rows) {
        $rows[] = array($label, $ok, $detail, $critical);
    };
    $add('PHP 8.0 ou plus', version_compare(PHP_VERSION, '8.0.0', '>='), 'PHP ' . PHP_VERSION);
    foreach (array('curl' => 'cURL (téléchargements, e-mail, reCAPTCHA)', 'mbstring' => 'mbstring', 'openssl' => 'OpenSSL (SMTP, HTTPS)', 'json' => 'JSON') as $ext => $label) {
        $add('Extension ' . $label, extension_loaded($ext), extension_loaded($ext) ? 'présente' : 'absente');
    }
    $add('Extension zip (installation et mises à jour)', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'présente' : 'absente : installation par FTP nécessaire');
    $probe = $docroot . '/.install-probe-' . bin2hex(random_bytes(3));
    $writable = @file_put_contents($probe, 'x') !== false;
    @unlink($probe);
    $add('Écriture dans le dossier du site', $writable, $writable ? 'oui' : 'non : vérifiez les droits du dossier');
    $free = @disk_free_space($docroot);
    $add('Espace disque disponible', $free === false || $free > 30 * 1048576, $free === false ? 'inconnu' : round($free / 1048576) . ' Mo', false);
    if (function_exists('apache_get_modules')) {
        $has = in_array('mod_rewrite', apache_get_modules(), true);
        $add('Réécriture d\'URL (mod_rewrite)', $has, $has ? 'active' : 'absente : les adresses /api/... ne fonctionneront pas');
    } else {
        $add('Réécriture d\'URL (mod_rewrite)', true, 'non vérifiable ici ; contrôlée après l\'installation', false);
    }
    $gh = function_exists('curl_init') ? http_get(GITHUB_API . '/zen', array('User-Agent: radiv-site-installer'), 8000) : null;
    $add('Accès sortant vers GitHub', $gh !== null && $gh[0] > 0 && $gh[0] < 500, $gh === null ? 'impossible : connexions sortantes bloquées ?' : 'HTTP ' . $gh[0]);
    $add('Connexion HTTPS', true, ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'oui' : 'non (essai local uniquement)'), false);
    return $rows;
}

function render_form($key, $values, $errors)
{
    $v = function ($name, $default = '') use ($values) {
        return h(isset($values[$name]) ? $values[$name] : $default);
    };
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $guess = $scheme . '://' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST']) : '');
    $out = '';
    if ($errors) {
        $out .= '<p class="msg">' . implode('<br>', array_map('h', $errors)) . '</p>';
    }
    $out .= '<form method="post" autocomplete="off"><input type="hidden" name="step" value="install"><input type="hidden" name="key" value="' . h($key) . '">';
    $out .= '<h2>Code source</h2>'
        . '<label>Dépôt GitHub</label><input type="text" name="repo" value="' . $v('repo', DEFAULT_REPO) . '" required>'
        . '<label>Version à installer (tag, branche ou commit)</label><input type="text" name="ref" value="' . $v('ref', 'main') . '" required>'
        . '<label>Jeton d\'accès</label><input type="password" name="token" required>';
    $out .= '<h2>Site</h2>'
        . '<label>Adresse du site (sans chemin)</label><input type="text" name="site_url" value="' . $v('site_url', $guess) . '" required>'
        . '<label>Dossier du backend (code, configuration et données ; vide = <code>backend</code> dans la racine du site)</label><input type="text" name="backend_dir" value="' . $v('backend_dir') . '">'
        . '<p class="small">Il peut aussi se trouver hors de la racine web (chemin absolu, <code>~/…</code> ou <code>../…</code>), ce qui est préférable quand l\'hébergement le permet. Dans tous les cas il est protégé par un fichier .htaccess.</p>';
    $out .= '<h2>Administration (connexion Google)</h2>'
        . '<p class="small">URI de redirection à déclarer dans Google Cloud : <code>&lt;adresse du site&gt;/auth/callback</code></p>'
        . '<label>Identifiant client Google</label><input type="text" name="google_id" value="' . $v('google_id') . '">'
        . '<label>Secret client Google</label><input type="password" name="google_secret">'
        . '<label>Adresses autorisées (séparées par des virgules)</label><input type="text" name="admin_emails" value="' . $v('admin_emails') . '">'
        . '<label class="inline"><input type="checkbox" name="update_enabled" value="1"' . (isset($values['update_enabled']) || !$values ? ' checked' : '') . '> Autoriser les mises à jour depuis la page d\'administration</label>';
    $out .= '<h2>Formulaire de contact</h2><p class="small">Laissez vide pour ne pas configurer le formulaire de contact.</p>'
        . '<label>Clé du site reCAPTCHA</label><input type="text" name="recaptcha_site" value="' . $v('recaptcha_site') . '">'
        . '<label>Clé secrète reCAPTCHA</label><input type="password" name="recaptcha_secret">'
        . '<label>Serveur SMTP</label><input type="text" name="smtp_host" value="' . $v('smtp_host') . '">'
        . '<label>Port SMTP</label><input type="text" name="smtp_port" value="' . $v('smtp_port', '587') . '">'
        . '<label class="inline"><input type="checkbox" name="smtp_secure" value="1"' . (isset($values['smtp_secure']) ? ' checked' : '') . '> Connexion TLS directe (port 465) au lieu de STARTTLS (587)</label>'
        . '<label>Identifiant SMTP</label><input type="text" name="smtp_user" value="' . $v('smtp_user') . '">'
        . '<label>Mot de passe SMTP</label><input type="password" name="smtp_pass">'
        . '<label>Expéditeur affiché</label><input type="text" name="smtp_from" value="' . $v('smtp_from') . '">'
        . '<label>Adresse qui reçoit les messages</label><input type="text" name="contact_dest" value="' . $v('contact_dest') . '">';
    $out .= '<h2>Options</h2>'
        . '<label>Arrondi des durées</label><select name="rounding"><option value="round">Au jour le plus proche (round)</option><option value="floor"' . ((isset($values['rounding']) && $values['rounding'] === 'floor') ? ' selected' : '') . '>Troncature (floor)</option></select>'
        . '<label>Journalisation des mesures</label><select name="logging">'
        . '<option value="full">Complète</option><option value="user"' . ((isset($values['logging']) && $values['logging'] === 'user') ? ' selected' : '') . '>Qui et quand uniquement</option><option value="none"' . ((isset($values['logging']) && $values['logging'] === 'none') ? ' selected' : '') . '>Aucune</option></select>'
        . '<label>Conservation des journaux (mois ; vide = 12 ; 0 = illimitée)</label><input type="text" name="retention" value="' . $v('retention', '12') . '">'
        . '<label>Taille maximale d\'un journal mensuel (Mo)</label><input type="text" name="logs_mb" value="' . $v('logs_mb', '5') . '">'
        . '<label class="inline"><input type="checkbox" name="sfmn" value="1"' . (isset($values['sfmn']) ? ' checked' : '') . '> Activer le mode « calcul SFMN » (appelle un site distant)</label>';
    $out .= '<button type="submit">Installer</button></form>';
    return $out;
}

function isInsideDocroot($path, $docroot)
{
    return strpos($path, $docroot . '/') === 0;
}

function mask($value)
{
    return $value === '' ? '— (non renseigné)' : '●●● (renseigné)';
}

// Regroupe les erreurs de validation par ensemble de champs à (re)saisir. 'other' = non corrigeable depuis la page.
function error_groups($errors)
{
    $g = array();
    foreach ($errors as $m) {
        if (strpos($m, 'Dépôt') === 0) $g['repo'] = true;
        elseif (strpos($m, 'Version') === 0 || strpos($m, 'Jeton') === 0 || strpos($m, 'Un jeton') === 0 || strpos($m, 'Dossier du backend') === 0) $g['always'] = true; // toujours demandés
        elseif (strpos($m, 'Adresse du site') === 0) $g['site'] = true;
        elseif (strpos($m, 'Connexion Google') === 0 || strpos($m, 'Identifiant client Google') === 0 || strpos($m, 'Secret client Google') === 0 || strpos($m, 'Adresse administrateur') === 0) $g['google'] = true;
        elseif (strpos($m, 'reCAPTCHA') === 0) $g['recaptcha'] = true;
        elseif (strpos($m, 'SMTP') === 0 || strpos($m, 'Port SMTP') === 0 || strpos($m, 'Adresse destinataire') === 0) $g['smtp'] = true;
        elseif (strpos($m, 'Arrondi') === 0 || strpos($m, 'Niveau de journalisation') === 0 || strpos($m, 'Conservation') === 0 || strpos($m, 'Taille de journal') === 0) $g['options'] = true;
        elseif (strpos($m, 'Adresse SFMN') === 0) $g['sfmn'] = true;
        else $g['other'] = true;
    }
    return $g;
}

// Champs à (re)saisir pour les groupes en erreur. Les secrets ne sont jamais préremplis (laissés vides = valeur du fichier conservée).
function render_missing_fields($groups, $d)
{
    $v = function ($n) use ($d) { return h(isset($d[$n]) ? $d[$n] : ''); };
    $out = '';
    if (isset($groups['repo'])) $out .= '<label>Dépôt GitHub</label><input type="text" name="repo" value="' . $v('repo') . '" required>';
    if (isset($groups['site'])) $out .= '<label>Adresse du site (sans chemin)</label><input type="text" name="site_url" value="' . $v('site_url') . '" required>';
    if (isset($groups['google'])) {
        $out .= '<h2>Administration (connexion Google)</h2><p class="small">Renseignez les trois champs ; ceux déjà présents dans le fichier sont conservés s\'ils sont laissés vides.</p>'
            . '<label>Identifiant client Google</label><input type="text" name="google_id" value="' . $v('google_id') . '">'
            . '<label>Secret client Google</label><input type="password" name="google_secret">'
            . '<label>Adresses autorisées (séparées par des virgules)</label><input type="text" name="admin_emails" value="' . $v('admin_emails') . '">';
    }
    if (isset($groups['recaptcha'])) {
        $out .= '<h2>reCAPTCHA</h2><label>Clé du site reCAPTCHA</label><input type="text" name="recaptcha_site" value="' . $v('recaptcha_site') . '">'
            . '<label>Clé secrète reCAPTCHA</label><input type="password" name="recaptcha_secret">';
    }
    if (isset($groups['smtp'])) {
        $out .= '<h2>Envoi des messages du formulaire de contact</h2>'
            . '<label>Serveur SMTP</label><input type="text" name="smtp_host" value="' . $v('smtp_host') . '">'
            . '<label>Port SMTP</label><input type="text" name="smtp_port" value="' . $v('smtp_port') . '">'
            . '<label>Identifiant SMTP</label><input type="text" name="smtp_user" value="' . $v('smtp_user') . '">'
            . '<label>Mot de passe SMTP</label><input type="password" name="smtp_pass">'
            . '<label>Expéditeur affiché</label><input type="text" name="smtp_from" value="' . $v('smtp_from') . '">'
            . '<label>Adresse qui reçoit les messages</label><input type="text" name="contact_dest" value="' . $v('contact_dest') . '">';
    }
    if (isset($groups['options'])) {
        $out .= '<h2>Options</h2><label>Arrondi (round ou floor)</label><input type="text" name="rounding" value="' . $v('rounding') . '">'
            . '<label>Journalisation (full, user ou none)</label><input type="text" name="logging" value="' . $v('logging') . '">'
            . '<label>Conservation des journaux (mois ; 0 = illimitée)</label><input type="text" name="retention" value="' . $v('retention') . '">'
            . '<label>Taille maximale d\'un journal mensuel (Mo)</label><input type="text" name="logs_mb" value="' . $v('logs_mb') . '">';
    }
    if (isset($groups['sfmn'])) $out .= '<label>Adresse du calculateur SFMN (https://…)</label><input type="text" name="sfmn_url" value="' . $v('sfmn_url') . '">';
    return $out;
}

// Résumé de la configuration lue dans le fichier de configuration (secrets masqués, jamais renvoyés dans le HTML) et
// formulaire qui ne demande que ce qui manque (version, dossier du backend, jeton si nécessaire, et les champs invalides).
function render_env_summary($key, $d, $extras, $errors, $needToken, $inside)
{
    $rows = array(
        array('Dépôt', $d['repo']), array('Version (GIT_BRANCH)', $d['ref']), array('Adresse du site', $d['site_url']),
        array('Dossier du backend', isset($d['backend_path']) && $d['backend_path'] !== null ? $d['backend_path'] : '—'),
        array('Connexion Google', $d['google_id'] !== '' ? 'identifiant ' . $d['google_id'] . ', secret ' . mask($d['google_secret']) : '— (non configurée : /auth répondra 404)'),
        array('Adresses administrateur', $d['admin_emails'] !== '' ? $d['admin_emails'] : '—'),
        array('Mises à jour depuis /auth', $d['update_enabled'] ? 'autorisées' : 'désactivées'),
        array('Contact (SMTP)', $d['smtp_host'] !== '' ? $d['smtp_host'] . ':' . $d['smtp_port'] . ($d['smtp_secure'] ? ' (TLS direct)' : ' (STARTTLS)') . ', utilisateur ' . $d['smtp_user'] . ', mot de passe ' . mask($d['smtp_pass']) . ', vers ' . $d['contact_dest'] : '—'),
        array('reCAPTCHA', $d['recaptcha_site'] !== '' ? 'clé du site ' . $d['recaptcha_site'] . ', clé secrète ' . mask($d['recaptcha_secret']) : '—'),
        array('Mode SFMN', $d['sfmn'] ? 'activé' : 'désactivé'),
        array('Arrondi / journalisation', $d['rounding'] . ' / ' . $d['logging'] . ', conservation ' . ($d['retention'] === '' ? '12 mois (défaut)' : ($d['retention'] === '0' ? 'illimitée' : $d['retention'] . ' mois')) . ', journal ' . $d['logs_mb'] . ' Mo'),
        array('Autres variables transmises', $extras ? implode(', ', array_keys($extras)) : '—'),
    );
    $out = '<h2>Configuration lue dans le fichier de configuration</h2><table>';
    foreach ($rows as $r) $out .= '<tr><td>' . h($r[0]) . '</td><td>' . h($r[1]) . '</td></tr>';
    $out .= '</table>';
    if ($inside) {
        $out .= '<p class="msg">Ce fichier est dans le dossier public du site : il peut être lu par n\'importe qui tant qu\'il y reste. Il sera supprimé à la fin de l\'installation. Placez-le de préférence dans le dossier parent.</p>';
    }
    $groups = error_groups($errors);
    if ($errors) {
        $out .= '<p class="msg">' . implode('<br>', array_map('h', $errors)) . '</p>';
        if (isset($groups['other'])) {
            return $out . '<p>Corrigez le fichier de configuration puis relancez la vérification.</p>'
                . '<form method="post"><input type="hidden" name="step" value="check"><input type="hidden" name="key" value="' . h($key) . '"><button type="submit">Relire le fichier</button></form>';
        }
        $out .= '<p>Complétez les informations ci-dessous (le reste est lu dans le fichier).</p>';
    }
    $out .= '<form method="post" autocomplete="off"><input type="hidden" name="step" value="install"><input type="hidden" name="key" value="' . h($key) . '"><input type="hidden" name="use_env" value="1">'
        . '<label>Version à installer (tag, branche ou commit)</label><input type="text" name="ref" value="' . h($d['ref']) . '" required>'
        . '<label>Dossier du backend (vide = valeur du fichier, sinon <code>backend</code> dans la racine du site)</label><input type="text" name="backend_dir" value="">'
        . render_missing_fields($groups, $d);
    if ($needToken) $out .= '<label>Jeton d\'accès</label><input type="password" name="token" required>';
    return $out . '<button type="submit">Installer</button></form>';
}

if ($step === 'check') {
    $key = (string) $_POST['key'];
    $rows = diagnostics($docroot);
    $blocked = false;
    $out = '<h2>État de l\'hébergement</h2><table>';
    foreach ($rows as $r) {
        if (!$r[1] && $r[3]) $blocked = true;
        $out .= '<tr><td>' . h($r[0]) . '</td><td class="' . ($r[1] ? 'ok' : ($r[3] ? 'ko' : '')) . '">' . ($r[1] ? 'OK' : ($r[3] ? 'Échec' : 'Attention')) . '</td><td>' . h($r[2]) . '</td></tr>';
    }
    $out .= '</table>';
    if ($blocked) {
        $out .= '<p class="msg">Corrigez les points en échec avant d\'installer. Si l\'extension zip ou les connexions sortantes sont indisponibles, l\'installation se fait par FTP (voir le README).</p>';
        page('Installation du site', $out, 200);
    }
    $envPath = find_env_file($docroot);
    if ($envPath === null) {
        $note = ENV_FILE === '' ? '' : '<p class="small">Aucun fichier de configuration lisible (<code>' . h(ENV_FILE) . '</code>, ni <code>.env</code> / <code>.runtime.env</code> dans la racine du site) : saisie manuelle de la configuration.</p>';
        page('Installation du site', $out . $note . render_form($key, array(), array()));
    }
    // Un .env de déploiement a été trouvé: la configuration vient de lui, la page ne demande que la version
    // et, si aucun jeton du fichier n'est accepté par GitHub, le jeton d'accès.
    $env = parse_env_file($envPath);
    list($src, $extras, $envTokens) = env_to_source($env === null ? array() : $env);
    list($d, $errors) = validate_inputs($src);
    $errors = array_merge($errors, validate_extras($extras));
    $needToken = true;
    if (!$errors && function_exists('curl_init')) {
        $cands = array();
        foreach ($envTokens as $n => $v) $cands[] = array($v, $n);
        $needToken = first_valid_token($d['repo'], $cands) === null;
    }
    page('Installation du site', $out . render_env_summary($key, $d, $extras, $errors, $needToken, isInsideDocroot($envPath, $docroot)));
}

// --- Étape 2 : installation --------------------------------------------------------------------

function field($name)
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
}

// Valeurs saisies dans le formulaire manuel.
function post_fields()
{
    global $TEXT_FIELDS, $BOOL_FIELDS;
    $src = array();
    foreach ($TEXT_FIELDS as $name) $src[$name] = field($name);
    foreach ($BOOL_FIELDS as $name) $src[$name] = isset($_POST[$name]) && $_POST[$name] === '1';
    return $src;
}

// Lecture d'un fichier .env (mêmes règles que backend/src/lib/config.php: guillemets, commentaires " #").
function parse_env_file($path)
{
    $content = @file_get_contents($path);
    if ($content === false) return null;
    $out = array();
    foreach (preg_split('/\r?\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, 'export ') === 0) $line = ltrim(substr($line, 7));
        $i = strpos($line, '=');
        if ($i === false) continue;
        $name = trim(substr($line, 0, $i));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) continue;
        $value = trim(substr($line, $i + 1));
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $close = strpos($value, $value[0], 1);
            if ($close !== false) $value = substr($value, 1, $close - 1);
        } elseif (preg_match('/\s#/', $value, $m, PREG_OFFSET_CAPTURE)) {
            $value = trim(substr($value, 0, $m[0][1]));
        }
        $out[$name] = $value;
    }
    return $out;
}

// Chemin réel du .env à lire, ou null (absent, illisible, ou open_basedir qui l'interdit).
// Dossier personnel de l'utilisateur de l'hébergement (pour '~/...'), ou null s'il n'est pas déterminable.
function home_dir()
{
    foreach (array(getenv('HOME'), isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '') as $home) {
        if (is_string($home) && $home !== '' && $home[0] === '/') return rtrim($home, '/');
    }
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['dir'])) return rtrim($pw['dir'], '/');
    }
    return null;
}

function find_env_file($docroot)
{
    $candidates = array();
    if (ENV_FILE !== '') {
        $path = ENV_FILE;
        if ($path === '~' || strpos($path, '~/') === 0) {
            $home = home_dir();
            $path = $home === null ? '' : $home . substr($path, 1); // '~' non résoluble ici : candidat ignoré
        } elseif ($path[0] !== '/') {
            $path = $docroot . '/' . $path;
        }
        if ($path !== '') $candidates[] = $path;
    }
    // Un fichier copié dans la racine du site convient aussi : un .env, ou le config/runtime.env (ancien .runtime.env)
    // d'une installation précédente, au même format.
    $candidates[] = $docroot . '/.env';
    $candidates[] = $docroot . '/.runtime.env';
    foreach ($candidates as $path) {
        $real = @realpath($path);
        if ($real !== false && is_file($real) && is_readable($real)) return $real;
    }
    return null;
}

// Résout . et .. sans exiger que le chemin existe.
function normalize_path($path)
{
    $out = array();
    foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    return '/' . implode('/', $out);
}

// Dossier du backend demandé (vide = <racine>/backend ; relatif à la racine, absolu ou ~/...). Retourne array(chemin, erreur).
function resolve_backend_dir($raw, $docroot)
{
    $raw = trim($raw);
    if ($raw === '') return array($docroot . '/backend', null);
    if ($raw === '~' || strpos($raw, '~/') === 0) {
        $home = home_dir();
        if ($home === null) return array(null, 'Dossier du backend : « ~ » n\'est pas résoluble ici, indiquez un chemin absolu.');
        $raw = $home . substr($raw, 1);
    } elseif ($raw[0] !== '/') {
        $raw = $docroot . '/' . $raw;
    }
    $path = normalize_path($raw);
    if ($path === '/' || $path === $docroot || strpos($docroot . '/', $path . '/') === 0) {
        return array(null, 'Dossier du backend invalide : il ne peut être ni la racine du site ni un dossier qui la contient.');
    }
    if ($path === $docroot . '/api' || strpos($path, $docroot . '/api/') === 0 || $path === $docroot . '/vendor' || $path === $docroot . '/downloads') {
        return array(null, 'Dossier du backend invalide : réservé au site.');
    }
    if (is_file($path)) return array(null, 'Dossier du backend invalide : c\'est un fichier.');
    if (is_dir($path) && array_diff(scandir($path), array('.', '..')) !== array() && !is_file($path . '/src/app.php')) {
        return array(null, 'Dossier du backend non vide et sans installation précédente : choisissez un dossier vide ou inexistant.');
    }
    return array($path, null);
}

// Chemin du backend relatif à la racine web (pour l'adresse HTTP à contrôler), ou null s'il est hors de la racine web.
function backend_url_path($backendDir, $docroot)
{
    return strpos($backendDir, $docroot . '/') === 0 ? substr($backendDir, strlen($docroot) + 1) : null;
}

// Traduit un .env en valeurs d'installation. Retourne array($src, $extras, $tokens) où $tokens liste les
// jetons éventuellement présents (nom => valeur). Les secrets ne sont jamais renvoyés au navigateur.
function env_to_source($env)
{
    global $PASSTHROUGH_VARS;
    $get = function ($n, $default = '') use ($env) {
        return isset($env[$n]) ? $env[$n] : $default;
    };
    $repo = $get('UPDATE_GITHUB_REPO');
    if ($repo === '' && preg_match('#^https://(?:[^@/]+@)?github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $get('GIT_REPO'), $m)) $repo = $m[1];
    $bytes = $get('LOGS_MAX_BYTES');
    $src = array(
        'repo' => $repo, 'ref' => $get('GIT_BRANCH', 'main'), 'site_url' => $get('SITE_PUBLIC_URL'),
        'google_id' => $get('GOOGLE_CLIENT_ID'), 'google_secret' => $get('GOOGLE_CLIENT_SECRET'), 'admin_emails' => $get('ADMIN_GOOGLE_EMAILS'),
        'update_enabled' => strtolower($get('ADMIN_UPDATE_ENABLED', 'false')) === 'true',
        'recaptcha_site' => $get('RECAPTCHA_SITE_KEY'), 'recaptcha_secret' => $get('RECAPTCHA_SECRET_KEY'),
        'smtp_host' => $get('SMTP_HOST'), 'smtp_port' => $get('SMTP_PORT', '587'), 'smtp_secure' => strtolower($get('SMTP_SECURE', 'false')) === 'true',
        'smtp_user' => $get('SMTP_USER'), 'smtp_pass' => $get('SMTP_PASS'), 'smtp_from' => $get('SMTP_FROM'), 'contact_dest' => $get('CONTACT_DEST'),
        'rounding' => $get('RESTRICTION_ROUNDING_MODE', 'round'), 'logging' => $get('MEASUREMENT_LOGGING_LEVEL', 'full'),
        'retention' => $get('LOGS_RETENTION_MONTHS'),
        'logs_mb' => preg_match('/^\d+$/', $bytes) ? (string) max(1, (int) ceil((int) $bytes / 1048576)) : '5',
        // Absent du .env: valeur par défaut de l'application (activé).
        'sfmn' => strtolower($get('SFMN_MODE_ENABLED', 'true')) !== 'false',
        'sfmn_url' => $get('SFMN_CALCULATOR_URL'),
        'backend_dir' => $get('BACKEND_DIR'),
    );
    $extras = array();
    foreach ($PASSTHROUGH_VARS as $name) {
        if (isset($env[$name])) $extras[$name] = $env[$name];
    }
    $tokens = array();
    foreach (array('UPDATE_GITHUB_TOKEN', 'GIT_TOKEN') as $name) {
        if (isset($env[$name]) && $env[$name] !== '') $tokens[$name] = $env[$name];
    }
    return array($src, $extras, $tokens);
}

function validate_extras($extras)
{
    $e = array();
    foreach ($extras as $name => $value) {
        if (strlen($value) > 300 || strpbrk($value, "\r\n\0") !== false) $e[] = 'Variable ' . $name . ' invalide dans le .env.';
    }
    return $e;
}

// Premier jeton accepté par GitHub pour ce dépôt. Retourne array(jeton, nom_de_la_source) ou null.
function first_valid_token($repo, $candidates)
{
    foreach ($candidates as $c) {
        if (!preg_match('/^[A-Za-z0-9_\-.]{20,255}$/', $c[0])) continue;
        $r = http_get(GITHUB_API . '/repos/' . $repo, gh_headers($c[0]));
        if ($r !== null && $r[0] === 200) return $c;
    }
    return null;
}

function validate_inputs($src)
{
    global $TEXT_FIELDS, $BOOL_FIELDS;
    $e = array();
    $d = array();
    foreach ($TEXT_FIELDS as $name) {
        $d[$name] = isset($src[$name]) ? trim((string) $src[$name]) : '';
        if (strpbrk($d[$name], "\r\n\0") !== false) $e[] = 'Le champ « ' . $name . ' » contient un caractère interdit.';
    }
    foreach ($BOOL_FIELDS as $name) $d[$name] = !empty($src[$name]);
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $d['repo'])) $e[] = 'Dépôt invalide (attendu : propriétaire/nom).';
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $d['ref']) || strpos($d['ref'], '..') !== false) $e[] = 'Version invalide.';
    $local = preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $d['site_url']) === 1;
    if (!$local && !preg_match('#^https://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d+)?$#', $d['site_url'])) $e[] = 'Adresse du site invalide (https://exemple.fr, sans chemin).';
    $d['local'] = $local;
    $d['site_url'] = rtrim($d['site_url'], '/');
    global $docroot;
    list($d['backend_path'], $backendError) = resolve_backend_dir($d['backend_dir'], $docroot);
    if ($backendError !== null) $e[] = $backendError;
    if ($d['google_id'] !== '' || $d['google_secret'] !== '' || $d['admin_emails'] !== '') {
        if ($d['google_id'] === '' || $d['google_secret'] === '' || $d['admin_emails'] === '') $e[] = 'Connexion Google : renseignez l\'identifiant, le secret et au moins une adresse (ou laissez les trois vides).';
        if (!preg_match('/^[A-Za-z0-9._\-]+$/', $d['google_id']) && $d['google_id'] !== '') $e[] = 'Identifiant client Google invalide.';
        if (!preg_match('/^[A-Za-z0-9._\-]+$/', $d['google_secret']) && $d['google_secret'] !== '') $e[] = 'Secret client Google invalide.';
        foreach (array_filter(array_map('trim', explode(',', $d['admin_emails']))) as $mail) {
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) $e[] = 'Adresse administrateur invalide : ' . $mail;
        }
    }
    if (($d['recaptcha_site'] === '') !== ($d['recaptcha_secret'] === '')) $e[] = 'reCAPTCHA : renseignez les deux clés (ou aucune).';
    if ($d['smtp_host'] !== '') {
        if (!preg_match('/^\d{1,5}$/', $d['smtp_port'])) $e[] = 'Port SMTP invalide.';
        foreach (array('smtp_user' => 'identifiant SMTP', 'smtp_pass' => 'mot de passe SMTP', 'smtp_from' => 'expéditeur', 'contact_dest' => 'destinataire') as $f => $label) {
            if ($d[$f] === '') $e[] = 'SMTP : ' . $label . ' manquant.';
        }
        if ($d['contact_dest'] !== '' && !filter_var($d['contact_dest'], FILTER_VALIDATE_EMAIL)) $e[] = 'Adresse destinataire invalide.';
    }
    if ($d['sfmn_url'] !== '' && !preg_match('#^https://[^\s"\'<>]{4,250}$#', $d['sfmn_url'])) $e[] = 'Adresse SFMN invalide (https:// attendu).';
    if (!in_array($d['rounding'], array('round', 'floor'), true)) $e[] = 'Arrondi invalide.';
    if (!in_array($d['logging'], array('full', 'user', 'none'), true)) $e[] = 'Niveau de journalisation invalide.';
    if ($d['retention'] !== '' && !preg_match('/^(?:0|[1-9]\d{0,2})$/', $d['retention'])) $e[] = 'Conservation des journaux invalide.';
    if (!preg_match('/^[1-9]\d{0,2}$/', $d['logs_mb'])) $e[] = 'Taille de journal invalide.';
    return array($d, $e);
}

function htaccess_blocks($siteUrl, $backendUrlPath)
{
    $routes = array(
        'legal' => 'mentions-legales.html', 'contact' => 'contact.html', 'print' => 'print.html',
        'explain' => 'explain.html', 'doc' => 'api-fonctionnement.html', 'test-api' => 'test-api.html', 'xplore' => 'xplore.html',
    );
    $blocks = array();
    foreach ($routes as $route => $target) {
        $blocks[$target] = "# /" . $route . "\nRewriteEngine On\nRewriteRule ^" . $route . "/?$ " . $target . " [L]\n";
    }
    $blocks['# /health (API PHP)'] = "# /health (API PHP)\nRewriteEngine On\nRewriteRule ^health/?$ api/index.php [L]\n";
    $blocks['# /auth (API PHP)'] = "# /auth (API PHP)\nRewriteEngine On\nRewriteRule ^auth(/.*)?$ api/index.php [L]\n";
    // Fichiers de configuration éventuellement copiés dans la racine (.env, .runtime.env) : jamais servis.
    $blocks['# Fichiers de configuration refusés v1'] = "# Fichiers de configuration refusés v1\n<FilesMatch \"^\\.(env|runtime\\.env)$\">\nRequire all denied\n</FilesMatch>\n";
    // Pas de redirection www/HTTPS pour un essai local (http://127.0.0.1) : seulement si le site est en https.
    $isHttps = strpos($siteUrl, 'https://') === 0;
    $headers = "<IfModule mod_headers.c>\nHeader setifempty X-Content-Type-Options \"nosniff\"\nHeader setifempty X-Frame-Options \"DENY\"\n"
        . "Header setifempty Referrer-Policy \"strict-origin-when-cross-origin\"\n"
        . "Header setifempty Permissions-Policy \"camera=(), microphone=(), geolocation=(), payment=()\"\n"
        . "Header setifempty Content-Security-Policy \"frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'\"\n"
        . ($isHttps ? "Header setifempty Strict-Transport-Security \"max-age=31536000\"\n" : '') . "</IfModule>\n";
    $blocks['# En-têtes de sécurité des pages v1'] = "# En-têtes de sécurité des pages v1\n" . $headers;
    $host = preg_replace('#^https?://#', '', $siteUrl);
    if (strpos($host, 'www.') === 0 && strpos($siteUrl, 'https://') === 0) {
        $re = str_replace('.', '\\.', preg_replace('/:\d+$/', '', $host));
        $marker = '# /www-redirect (' . $host . ') v2';
        $blocks[$marker] = $marker . "\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n"
            . 'RewriteCond %{HTTP_HOST} !^' . $re . "$ [NC]\nRewriteCond %{REQUEST_URI} !^/api(/|$)\nRewriteRule ^ https://" . $host . "%{REQUEST_URI} [L,R=301]\n";
    }
    return $blocks;
}

// Règle de tête de .htaccess qui interdit l'adresse du backend quand il se trouve dans la racine web (en plus de son
// propre .htaccess). Vide si le backend est hors de la racine web.
function backend_deny_block($backendUrlPath)
{
    if ($backendUrlPath === null || $backendUrlPath === '') return '';
    $re = preg_quote($backendUrlPath, '#');
    return '# /' . $backendUrlPath . " (backend : accès refusé) v1\nRewriteEngine On\nRewriteRule ^" . $re . "(/|$) - [F,L]\n\n";
}

// Contenu de config/runtime.env : mêmes sections et mêmes commentaires que celui de deploy.sh. Une variable non définie
// est écrite « #NOM= » (valeur par défaut du site) ; TRUSTED_PROXIES défini mais vide reste une valeur (jamais de X-Forwarded-For).
function render_runtime_env($vars)
{
    global $RUNTIME_LAYOUT;
    $out = "# Configuration du site. Lue à CHAQUE requête : toute modification est prise en compte immédiatement.\n"
        . "# Généré par install.php le " . date('Y-m-d H:i') . " — les mises à jour de /auth ne modifient jamais ce fichier.\n"
        . "# Pour réinstaller ou régénérer, voir le README ; config/local.env reste pour les réglages purement locaux.\n"
        . "#\n"
        . "# Une ligne qui commence par # est un commentaire. Une variable écrite « #NOM= » n'est pas définie : le site\n"
        . "# utilise alors sa valeur par défaut. Pour la définir, retirez le # et mettez la valeur entre guillemets.\n";
    $name = '';
    $comment = array();
    $flush = function () use (&$name, &$comment, &$out, $vars) {
        if ($name === '') return;
        $line = '';
        if (array_key_exists($name, $vars) && ($vars[$name] !== '' || $name === 'TRUSTED_PROXIES')) {
            $line = $name === 'TRUSTED_PROXIES' && $vars[$name] === '' ? 'TRUSTED_PROXIES=""' . "\n" : env_line($name, $vars[$name]);
        }
        $out .= "\n" . implode("\n", $comment) . "\n" . ($line !== '' ? $line : '#' . $name . "=\n");
        $name = '';
        $comment = array();
    };
    foreach (explode("\n", $RUNTIME_LAYOUT) as $row) {
        if (strpos($row, '== ') === 0) {
            $flush();
            $out .= "\n\n# ------------------------------------------------------------------------------------------\n# " . substr($row, 3)
                . "\n# ------------------------------------------------------------------------------------------\n";
        } elseif (isset($row[0]) && $row[0] === '|') {
            $comment[] = '# ' . substr($row, 1);
        } else {
            $flush();
            $parts = explode('|', $row, 2);
            $name = $parts[0];
            $comment = array('# ' . (isset($parts[1]) ? $parts[1] : ''));
        }
    }
    $flush();
    return $out;
}

function copy_atomic($src, $dest)
{
    make_dir(dirname($dest));
    $tmp = $dest . '.inst' . bin2hex(random_bytes(3));
    if (!@copy($src, $tmp)) throw new RuntimeException('Écriture impossible : ' . basename($dest));
    @chmod($tmp, 0644);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        throw new RuntimeException('Remplacement impossible : ' . basename($dest));
    }
}

function health_get($url)
{
    $r = http_get($url, array('User-Agent: radiv-site-installer'), 10000);
    return $r;
}

if ($step !== 'install') page('Introuvable', '<p>Cette page n\'existe pas.</p>', 404);

@set_time_limit(300);
ignore_user_abort(true);
$key = (string) $_POST['key'];
$envPath = null;
$extras = array();
$envTokens = array();
if (isset($_POST['use_env']) && $_POST['use_env'] === '1') {
    // Configuration lue dans le fichier de configuration (jamais dans le navigateur) ; le formulaire ne sert qu'à la version,
    // au dossier du backend, au jeton, et aux informations manquantes ou invalides (une valeur saisie remplace celle du fichier,
    // un champ laissé vide la conserve).
    $envPath = find_env_file($docroot);
    $env = $envPath === null ? null : parse_env_file($envPath);
    if ($env === null) page('Installation du site', '<p class="msg">Le fichier de configuration est introuvable ou illisible.</p><form method="post"><input type="hidden" name="step" value="check"><input type="hidden" name="key" value="' . h($key) . '"><button type="submit">Relire</button></form>', 400);
    list($src, $extras, $envTokens) = env_to_source($env);
    // Seuls peuvent être saisis : la version, le dossier du backend, et les champs que le fichier ne renseigne pas correctement
    // (groupes en erreur). Tout le reste vient du fichier et ne peut pas être remplacé depuis le navigateur.
    list($d0, $errors0) = validate_inputs($src);
    $groupFields = array('repo' => array('repo'), 'site' => array('site_url'), 'google' => array('google_id', 'google_secret', 'admin_emails'),
        'recaptcha' => array('recaptcha_site', 'recaptcha_secret'), 'smtp' => array('smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'contact_dest'),
        'options' => array('rounding', 'logging', 'retention', 'logs_mb'), 'sfmn' => array('sfmn_url'));
    $overridable = array('ref', 'backend_dir');
    foreach (array_keys(error_groups($errors0)) as $g) {
        if (isset($groupFields[$g])) $overridable = array_merge($overridable, $groupFields[$g]);
    }
    foreach ($overridable as $name) {
        if (field($name) !== '') $src[$name] = field($name);
    }
    list($d, $errors) = validate_inputs($src);
    $errors = array_merge($errors, validate_extras($extras));
    if ($errors) page('Installation du site', render_env_summary($key, $d, $extras, $errors, true, isInsideDocroot($envPath, $docroot)), 400);
} else {
    list($d, $errors) = validate_inputs(post_fields());
    if ($errors) page('Installation du site', render_form($key, $d, $errors), 400);
}
$candidates = array();
if (field('token') !== '' && !preg_match('/^[A-Za-z0-9_\-.]{20,255}$/', field('token'))) {
    $msg = array('Jeton invalide.');
    page('Installation du site', $envPath !== null ? render_env_summary($key, $d, $extras, $msg, true, isInsideDocroot($envPath, $docroot)) : render_form($key, $d, $msg), 400);
}
if (field('token') !== '') $candidates[] = array(field('token'), 'form');
foreach ($envTokens as $n => $v) $candidates[] = array($v, $n);
if (!$candidates) {
    $msg = array('Un jeton d\'accès est nécessaire.');
    page('Installation du site', $envPath !== null ? render_env_summary($key, $d, $extras, $msg, true, isInsideDocroot($envPath, $docroot)) : render_form($key, $d, $msg), 400);
}
if (!class_exists('ZipArchive') || !function_exists('curl_init')) page('Installation du site', render_form($key, $d, array('Extensions zip et cURL requises.')), 400);

$backendDir = $d['backend_path'];
$backendUrl = backend_url_path($backendDir, $docroot);            // null = backend hors de la racine web
$backendExisted = is_dir($backendDir);
$dataDir = $backendDir . '/data';
if (isset($extras['DATA_DIR']) && $extras['DATA_DIR'] !== '') {
    $dd = $extras['DATA_DIR'];
    $dataDir = $dd[0] === '/' ? normalize_path($dd) : normalize_path($backendDir . '/' . $dd);
}
$dataExisted = is_dir($dataDir);

$tmp = $docroot . '/.install-tmp-' . bin2hex(random_bytes(4));
$written = array();    // fichiers créés ou remplacés, chemins absolus (pour retour arrière en cas d'échec)
$backups = array();    // chemin absolu => copie de ce qui existait avant
$fatal = null;
$probe = null;         // résultat du contrôle de protection du backend : true protégé, false lisible, null non vérifiable
$saveBackup = function ($dest) use (&$backups, $tmp) {
    if (is_file($dest)) {
        $bk = $tmp . '/backup/' . sha1($dest);
        make_dir(dirname($bk));
        if (!@copy($dest, $bk)) throw new RuntimeException('Sauvegarde impossible.');
        $backups[$dest] = $bk;
    }
};
try {
    // 1) Jeton valide + commit exact
    $good = first_valid_token($d['repo'], $candidates);
    if ($good === null) throw new RuntimeException('Le jeton n\'est pas valide ou n\'a pas accès à ce dépôt.');
    $token = $good[0];
    $persistToken = $good[1] === 'UPDATE_GITHUB_TOKEN'; // conservé seulement s'il a été écrit tel quel dans le .env
    $candidates = array();
    $encoded = str_replace('%2F', '/', rawurlencode($d['ref']));
    $r = http_get(GITHUB_API . '/repos/' . $d['repo'] . '/commits/' . $encoded, gh_headers($token, 'application/vnd.github.sha'));
    $sha = ($r !== null && $r[0] === 200) ? strtolower(trim($r[1])) : '';
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new RuntimeException('Version introuvable dans le dépôt.');

    // 2) Téléchargement: la redirection est suivie à la main pour ne JAMAIS envoyer le jeton au second appel
    if (!make_dir($tmp) || !guard_dir($tmp)) throw new RuntimeException('Dossier temporaire inaccessible.');
    $r = http_get(GITHUB_API . '/repos/' . $d['repo'] . '/zipball/' . $sha, gh_headers($token));
    if ($r === null || $r[0] !== 302 || !preg_match('#^https?://#i', (string) $r[2])) throw new RuntimeException('Téléchargement impossible.');
    $zipFile = $tmp . '/repo.zip';
    if (!download_to($r[2], $zipFile, MAX_ZIP_BYTES)) throw new RuntimeException('Téléchargement impossible.');
    if (@file_get_contents($zipFile, false, null, 0, 2) !== 'PK') throw new RuntimeException('Fichier téléchargé invalide.');
    $token = ''; // plus utile: jamais conservé (sauf UPDATE_GITHUB_TOKEN écrit par l'utilisateur dans son .env)

    // 3) Validation complète avant toute écriture sur le site
    $staging = $tmp . '/new';
    $files = extract_archive($zipFile, $staging, $sha);
    $missing = array_diff($REQUIRED_FILES, $files);
    if ($missing) throw new RuntimeException('Version incomplète ou trop ancienne (' . implode(', ', array_slice($missing, 0, 3)) . ').');
    lint_php($staging, $files);
    @unlink($zipFile);

    // 4) Installation des fichiers (ce qui existait déjà, ex. une page d'attente de l'hébergeur, est sauvegardé)
    //    Le backend d'abord (son .htaccess en premier), la façade publique ensuite.
    usort($files, function ($a, $b) {
        $rank = function ($rel) { return $rel === 'backend/.htaccess' ? 0 : (strpos($rel, 'backend/') === 0 ? 1 : ($rel === 'public/api/index.php' ? 3 : 2)); };
        $ra = $rank($a); $rb = $rank($b);
        return $ra === $rb ? strcmp($a, $b) : $ra - $rb;
    });
    foreach ($files as $rel) {
        $dest = dest_of($rel, $docroot, $backendDir);
        $saveBackup($dest);
        copy_atomic($staging . '/' . $rel, $dest);
        $written[] = $dest;
    }
    // Façade publique : emplacement du backend (toujours écrit, explicite)
    $pointer = $docroot . '/api/backend.php';
    $saveBackup($pointer);
    if (@file_put_contents($pointer, "<?php\n// Généré par install.php : emplacement du backend.\nreturn " . var_export($backendDir, true) . ";\n") === false) throw new RuntimeException('Écriture impossible : api/backend.php');
    $written[] = $pointer;

    // 5) Fichiers générés (racine web)
    $site = $d['site_url'];
    $siteHost = preg_replace('#^https?://#', '', $site);
    $gen = array(
        'config.js' => 'window.RADIOPROTECTION_API_URL = "' . $site . '/api";' . "\n"
            . 'window.RADIOPROTECTION_SITE_URL = "' . $site . '";' . "\n"
            . 'window.RADIOPROTECTION_SITE_NAME = "' . preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $siteHost)) . '";' . "\n"
            . 'window.RADIOPROTECTION_COPYRIGHT_OWNER = "' . preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $siteHost)) . '";' . "\n"
            . 'window.RADIOPROTECTION_LAST_DEPLOYED_AT = "' . date('Y-m-d') . '";' . "\n",
        'sitemap.xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . '  <url>' . "\n" . '    <loc>' . h($site . '/') . '</loc>' . "\n" . '    <changefreq>monthly</changefreq>' . "\n" . '    <priority>1.0</priority>' . "\n" . '  </url>' . "\n" . '</urlset>' . "\n",
    );
    foreach ($gen as $rel => $content) {
        $dest = $docroot . '/' . $rel;
        $saveBackup($dest);
        if (@file_put_contents($dest, $content) === false) throw new RuntimeException('Écriture impossible : ' . $rel);
        $written[] = $dest;
    }
    $robots = $docroot . '/robots.txt';
    if (is_file($robots) && !preg_match('/^Sitemap:/mi', (string) file_get_contents($robots))) {
        file_put_contents($robots, rtrim((string) file_get_contents($robots)) . "\n\nSitemap: " . $site . "/sitemap.xml\n");
    }
    // .htaccess: ajout des seuls blocs manquants (le contenu existant de l'hébergeur est conservé)
    $ht = $docroot . '/.htaccess';
    $existing = is_file($ht) ? (string) file_get_contents($ht) : '';
    $saveBackup($ht);
    $add = $existing === '' ? "Options -Indexes\nRewriteEngine On\n" : '';
    foreach (htaccess_blocks($site, $backendUrl) as $marker => $block) {
        if (strpos($existing, $marker) === false) $add .= "\n" . $block;
    }
    // En TÊTE (pour passer avant les autres règles) : interdiction du dossier du backend quand il est dans la racine web, et
    // redirection HTTP -> HTTPS (308 : un POST garde sa méthode et son corps).
    $head = '';
    $denyBlock = backend_deny_block($backendUrl);
    if ($denyBlock !== '' && strpos($existing, strtok($denyBlock, "\n")) === false) $head .= $denyBlock;
    $httpsMarker = '# /https (redirection HTTP vers HTTPS) v1';
    if (strpos($site, 'https://') === 0 && strpos($existing, $httpsMarker) === false) {
        $head .= $httpsMarker . "\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteCond %{HTTP:X-Forwarded-Proto} !https\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=308]\n\n";
    }
    if (@file_put_contents($ht, $head . $existing . $add) === false) throw new RuntimeException('Écriture impossible : .htaccess');
    $written[] = $ht;

    // 6) Dossiers du backend protégés, contrôle que le backend n'est PAS lisible par HTTP (avant d'y écrire des secrets),
    //    puis configuration (jamais écrasée par les mises à jour)
    if (!guard_dir($backendDir) || !guard_dir($backendDir . '/config') || !guard_dir($dataDir) || !guard_dir($dataDir . '/logs') || !guard_dir($dataDir . '/var')) {
        throw new RuntimeException('Dossiers du backend inaccessibles.');
    }
    if ($backendUrl !== null) {
        $probeName = 'probe-' . bin2hex(random_bytes(4)) . '.txt';
        $probeFile = $backendDir . '/config/' . $probeName;
        if (@file_put_contents($probeFile, 'x') !== false) {
            $pr = health_get($site . '/' . $backendUrl . '/config/' . $probeName);
            @unlink($probeFile);
            if ($pr === null) $probe = null;
            elseif ($pr[0] === 200 && trim($pr[1]) === 'x') throw new RuntimeException('Le dossier du backend est lisible depuis le web (les fichiers .htaccess ne sont pas appliqués par cet hébergement). Indiquez un dossier du backend situé HORS de la racine web (ex. ../backend) et recommencez.');
            else $probe = true;
        }
    }
    $vars = array(
        'SITE_PUBLIC_URL' => $site, 'API_PUBLIC_URL' => $site . '/api', 'API_CORS_ORIGIN' => $site,
        'GOOGLE_CLIENT_ID' => $d['google_id'], 'GOOGLE_CLIENT_SECRET' => $d['google_secret'], 'ADMIN_GOOGLE_EMAILS' => $d['admin_emails'],
        'ADMIN_UPDATE_ENABLED' => $d['update_enabled'] ? 'true' : 'false',
        'UPDATE_GITHUB_REPO' => $d['repo'],
        'RECAPTCHA_SITE_KEY' => $d['recaptcha_site'], 'RECAPTCHA_SECRET_KEY' => $d['recaptcha_secret'],
        'SMTP_HOST' => $d['smtp_host'], 'SMTP_PORT' => $d['smtp_host'] !== '' ? $d['smtp_port'] : '', 'SMTP_SECURE' => $d['smtp_host'] !== '' ? ($d['smtp_secure'] ? 'true' : 'false') : '',
        'SMTP_USER' => $d['smtp_user'], 'SMTP_PASS' => $d['smtp_pass'], 'SMTP_FROM' => $d['smtp_from'], 'CONTACT_DEST' => $d['contact_dest'],
        'SFMN_MODE_ENABLED' => $d['sfmn'] ? 'true' : 'false', 'SFMN_CALCULATOR_URL' => $d['sfmn'] ? ($d['sfmn_url'] !== '' ? $d['sfmn_url'] : DEFAULT_SFMN_URL) : '',
        'RESTRICTION_ROUNDING_MODE' => $d['rounding'], 'MEASUREMENT_LOGGING_LEVEL' => $d['logging'],
        'LOGS_RETENTION_MONTHS' => $d['retention'], 'LOGS_MAX_BYTES' => (string) ((int) $d['logs_mb'] * 1048576),
    );
    if ($d['local']) $vars['ADMIN_ALLOW_INSECURE_HTTP'] = 'true'; // uniquement pour un essai en local (http://127.0.0.1)
    foreach ($extras as $name => $value) $vars[$name] = $value; // réglages du .env sans champ de formulaire
    if ($persistToken) $vars['UPDATE_GITHUB_TOKEN'] = $good[0];
    $env = render_runtime_env($vars);
    $envFile = $backendDir . '/config/runtime.env';
    $saveBackup($envFile);
    if (@file_put_contents($envFile, $env) === false) throw new RuntimeException('Écriture impossible : config/runtime.env');
    @chmod($envFile, 0600);
    $written[] = $envFile;
    $good = null; // le jeton n'est plus nécessaire
    $candidates = array();
    if (!is_readable($envFile)) throw new RuntimeException('config/runtime.env illisible par le serveur web.');

    // 7) État de référence pour les mises à jour de /auth
    $now = gmdate('Y-m-d\TH:i:s.000\Z');
    file_put_contents($dataDir . '/var/version.json', json_encode(array('sha' => $sha, 'ref' => $d['ref'], 'at' => $now, 'by' => 'install.php')));
    file_put_contents($dataDir . '/var/manifest.json', json_encode(array('files' => $files)));
    file_put_contents($dataDir . '/logs/updates.jsonl', json_encode(array('at' => $now, 'action' => 'install', 'by' => 'install.php', 'ref' => $d['ref'], 'to' => $sha, 'ok' => true)) . "\n", FILE_APPEND);
} catch (Exception $e) {
    $fatal = $e->getMessage();
} catch (Throwable $e) {
    $fatal = 'Erreur interne pendant l\'installation.';
    error_log('[INSTALL] ' . get_class($e) . ': ' . $e->getMessage());
}

if ($fatal !== null) {
    // Retour arrière: ce qui a été écrit est retiré, ce qui existait avant est rétabli.
    foreach (array_reverse($written) as $dest) {
        if (isset($backups[$dest])) @copy($backups[$dest], $dest); else @unlink($dest);
    }
    foreach (array($dataDir . '/var/version.json', $dataDir . '/var/manifest.json', $dataDir . '/logs/updates.jsonl') as $f) @unlink($f);
    // Dossiers créés par cette tentative : retirés (le backend et les données seulement s'ils n'existaient pas avant).
    if (!$dataExisted) rrmdir($dataDir);
    if (!$backendExisted) rrmdir($backendDir);
    foreach (array_reverse($written) as $dest) {
        for ($dir = dirname($dest); strpos($dir, $docroot . '/') === 0; $dir = dirname($dir)) {
            if (!@rmdir($dir)) break; // non vide (ou préexistant et utilisé): conservé
        }
    }
    rrmdir($tmp);
    page('Installation interrompue', '<p class="msg">' . h($fatal) . '</p><p>Les fichiers ont été remis dans leur état précédent. Corrigez le point signalé puis recommencez.</p>'
        . '<form method="post"><input type="hidden" name="step" value="check"><input type="hidden" name="key" value="' . h($key) . '"><button type="submit">Reprendre</button></form>', 500);
}

// 8) Contrôle du site installé (depuis le serveur lui-même; peut être impossible chez certains hébergeurs)
rrmdir($tmp);
$site = $d['site_url'];
$checks = array();
$cfg = health_get($site . '/api/config');
$cfgData = $cfg !== null ? json_decode($cfg[1], true) : null;
$checks[] = array('API (GET /api/config)', $cfg === null ? null : ($cfg[0] === 200 && is_array($cfgData) && isset($cfgData['isotopes'])), $cfg === null ? 'appel impossible depuis le serveur' : 'HTTP ' . $cfg[0]);
$hl = health_get($site . '/health');
$checks[] = array('Contrôle de santé (GET /health)', $hl === null ? null : $hl[0] === 200, $hl === null ? 'appel impossible depuis le serveur' : 'HTTP ' . $hl[0]);
$checks[] = array('Backend protégé (illisible depuis le web)', $backendUrl === null ? true : $probe, $backendUrl === null ? 'hors de la racine web' : ($probe === true ? 'refus HTTP confirmé' : 'non vérifiable depuis le serveur'));
if ($d['google_id'] !== '') {
    $au = health_get($site . '/auth');
    $okAuth = $au !== null && ($au[0] === 302 || $au[0] === 301);
    $checks[] = array('Administration (/auth → Google)', $au === null ? null : $okAuth, $au === null ? 'appel impossible depuis le serveur' : 'HTTP ' . $au[0]);
}
$bad = false;
foreach ($checks as $c) { if ($c[1] === false) $bad = true; }

$out = '<p class="ok"><strong>Site installé</strong> — version <code>' . h(substr($sha, 0, 7)) . '</code> (' . h($d['ref']) . '), ' . count($files) . ' fichiers.</p><table>';
foreach ($checks as $c) {
    $out .= '<tr><td>' . h($c[0]) . '</td><td class="' . ($c[1] === true ? 'ok' : ($c[1] === false ? 'ko' : '')) . '">' . ($c[1] === true ? 'OK' : ($c[1] === false ? 'Échec' : 'Non vérifié')) . '</td><td>' . h($c[2]) . '</td></tr>';
}
$out .= '</table><p class="small">Backend : <code>' . h($backendDir) . '</code></p>';
if ($bad) {
    // Pas de verrou ni de suppression: l'installation peut être relancée après correction.
    $out .= '<p class="msg">Un contrôle a échoué. Vérifiez la réécriture d\'URL (.htaccess autorisé) et la version PHP du site, puis relancez cette page. Le fichier <code>install.php</code> a été conservé.</p>';
    page('Installation terminée avec un avertissement', $out);
}
file_put_contents($docroot . '/api/.install-done', gmdate('c'));
$removed = @unlink(__FILE__);
$envNote = '';
if ($envPath !== null) {
    if (isInsideDocroot($envPath, $docroot)) {
        $envNote = @unlink($envPath)
            ? '<p class="ok">Le fichier de configuration, qui se trouvait dans le dossier public, a été supprimé (son contenu est dans le backend).</p>'
            : '<p class="msg"><strong>Supprimez le fichier de configuration du dossier public par FTP</strong> (suppression automatique impossible) : il contient des secrets.</p>';
    } else {
        $envNote = '<p class="small">Le fichier de configuration lu pour l\'installation (hors du dossier public) n\'a pas été modifié.</p>';
    }
}
foreach (glob($docroot . '/.install-attempts-*') ?: array() as $f) @unlink($f);
$out .= $envNote;
$out .= $removed
    ? '<p class="ok"><code>install.php</code> a été supprimé.</p>'
    : '<p class="msg"><strong>Supprimez maintenant <code>install.php</code> par FTP</strong> (suppression automatique impossible). Il est déjà inactif.</p>';
$out .= '<h2>Prochaines étapes</h2><ul>'
    . ($d['google_id'] !== '' ? '<li>Dans Google Cloud, vérifiez l\'URI de redirection : <code>' . h($site) . '/auth/callback</code>, puis connectez-vous sur <code>' . h($site) . '/auth</code>.</li>' : '<li>La connexion Google n\'est pas configurée : <code>/auth</code> répond 404. Ajoutez les réglages Google dans <code>config/runtime.env</code> du backend (voir le README).</li>')
    . '<li>Les mises à jour se font désormais depuis <code>/auth</code> (un jeton d\'accès GitHub sera demandé si nécessaire).</li>'
    . '<li>Contrôlez le site : <a href="' . h($site) . '/">' . h($site) . '/</a></li></ul>';
page('Installation terminée', $out);
