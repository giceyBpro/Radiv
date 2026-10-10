<?php
// ============================================================================================
// INSTALLATION INITIALE DU SITE (à envoyer seul par FTP, à la racine web, puis ouvrir dans un
// navigateur : https://<votre-domaine>/install.php).
//
// Ce fichier n'est JAMAIS publié par deploy.sh, deploy-php.sh ni par la page /auth : il ne sert
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
const GITHUB_API = 'https://api.github.com';   // modifié uniquement par les tests (faux GitHub local)
const DEFAULT_REPO = 'giceyBpro/Radiv';
const DEFAULT_SFMN_URL = 'https://www.acoramen.net/index.php?option=com_evictionperiod&Itemid=5142&lang=fr';

// Même liste blanche que api/lib/updater.php et deploy-php.sh (api/tests/test-install.js vérifie
// qu'elles restent identiques). api/ et downloads/ sont traités par préfixe dans allowed().
$FRONTEND_FILES = array(
    'index.html', 'v1.html', 'app.js', 'print.html', 'explain.html', 'contact.html',
    'mentions-legales.html', 'api-fonctionnement.html', 'test-api.html', 'xplore.html',
    'favicon.ico', 'robots.txt',
);
$REQUIRED_FILES = array(
    'index.html', 'api/.htaccess', 'api/index.php', 'api/calculation.php', 'api/lib/config.php',
    'api/lib/updater.php', 'api/lib/admin_site.php', 'api/lib/google.php', 'api/lib/session.php',
);
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
if (is_file($docroot . '/api/var/install.done')) {
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

// Seuls ces chemins (relatifs à la racine web) peuvent être écrits par l'installation.
function allowed($rel)
{
    global $FRONTEND_FILES;
    if ($rel === '' || $rel[0] === '/' || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false) return false;
    $parts = explode('/', $rel);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
    }
    if (in_array($rel, $FRONTEND_FILES, true)) return true;
    if ($parts[0] === 'downloads') {
        return count($parts) === 2 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $parts[1]) === 1;
    }
    if ($parts[0] === 'api' && count($parts) >= 2) {
        if (in_array($parts[1], array('tests', 'logs', 'var'), true)) return false;
        if ($rel === 'api/.env' || $rel === 'api/.runtime.env') return false;
        foreach (array_slice($parts, 1) as $part) {
            if ($part[0] === '.' && $rel !== 'api/.htaccess') return false;
            if (preg_match('/^[A-Za-z0-9._-]+$/', $part) !== 1) return false;
        }
        return true;
    }
    return false;
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
        . '<label>Adresse du site (sans chemin)</label><input type="text" name="site_url" value="' . $v('site_url', $guess) . '" required>';
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
        . '<label>Conservation des journaux (mois, vide = illimitée)</label><input type="text" name="retention" value="' . $v('retention', '3') . '">'
        . '<label>Taille maximale d\'un journal mensuel (Mo)</label><input type="text" name="logs_mb" value="' . $v('logs_mb', '5') . '">'
        . '<label class="inline"><input type="checkbox" name="sfmn" value="1"' . (isset($values['sfmn']) ? ' checked' : '') . '> Activer le mode « calcul SFMN » (appelle un site distant)</label>';
    $out .= '<button type="submit">Installer</button></form>';
    return $out;
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
    page('Installation du site', $out . render_form($key, array(), array()));
}

// --- Étape 2 : installation --------------------------------------------------------------------

function field($name)
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
}

function validate_inputs()
{
    $e = array();
    $d = array();
    foreach (array('repo', 'ref', 'token', 'site_url', 'google_id', 'google_secret', 'admin_emails', 'recaptcha_site', 'recaptcha_secret', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'contact_dest', 'rounding', 'logging', 'retention', 'logs_mb') as $name) {
        $d[$name] = field($name);
        if (strpbrk($d[$name], "\r\n\0") !== false) $e[] = 'Le champ « ' . $name . ' » contient un caractère interdit.';
    }
    foreach (array('update_enabled', 'smtp_secure', 'sfmn') as $name) $d[$name] = isset($_POST[$name]) && $_POST[$name] === '1';
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $d['repo'])) $e[] = 'Dépôt invalide (attendu : propriétaire/nom).';
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $d['ref']) || strpos($d['ref'], '..') !== false) $e[] = 'Version invalide.';
    if (!preg_match('/^[A-Za-z0-9_\-.]{20,255}$/', $d['token'])) $e[] = 'Jeton invalide.';
    $local = preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $d['site_url']) === 1;
    if (!$local && !preg_match('#^https://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d+)?$#', $d['site_url'])) $e[] = 'Adresse du site invalide (https://exemple.fr, sans chemin).';
    $d['local'] = $local;
    $d['site_url'] = rtrim($d['site_url'], '/');
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
    if (!in_array($d['rounding'], array('round', 'floor'), true)) $e[] = 'Arrondi invalide.';
    if (!in_array($d['logging'], array('full', 'user', 'none'), true)) $e[] = 'Niveau de journalisation invalide.';
    if ($d['retention'] !== '' && !preg_match('/^[1-9]\d{0,2}$/', $d['retention'])) $e[] = 'Conservation des journaux invalide.';
    if (!preg_match('/^[1-9]\d{0,2}$/', $d['logs_mb'])) $e[] = 'Taille de journal invalide.';
    return array($d, $e);
}

function htaccess_blocks($siteUrl)
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
    $host = preg_replace('#^https?://#', '', $siteUrl);
    if (strpos($host, 'www.') === 0 && strpos($siteUrl, 'https://') === 0) {
        $re = str_replace('.', '\\.', preg_replace('/:\d+$/', '', $host));
        $marker = '# /www-redirect (' . $host . ') v2';
        $blocks[$marker] = $marker . "\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n"
            . 'RewriteCond %{HTTP_HOST} !^' . $re . "$ [NC]\nRewriteCond %{REQUEST_URI} !^/api(/|$)\nRewriteRule ^ https://" . $host . "%{REQUEST_URI} [L,R=301]\n";
    }
    return $blocks;
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
list($d, $errors) = validate_inputs();
if ($errors) page('Installation du site', render_form($key, $d, $errors), 400);
if (!class_exists('ZipArchive') || !function_exists('curl_init')) page('Installation du site', render_form($key, $d, array('Extensions zip et cURL requises.')), 400);

$tmp = $docroot . '/.install-tmp-' . bin2hex(random_bytes(4));
$written = array();    // fichiers créés ou remplacés (pour retour arrière en cas d'échec)
$backups = array();    // rel => chemin de la copie de ce qui existait avant
$fatal = null;
try {
    // 1) Jeton valide + commit exact
    $r = http_get(GITHUB_API . '/repos/' . $d['repo'], gh_headers($d['token']));
    if ($r === null || $r[0] !== 200) throw new RuntimeException('Le jeton n\'est pas valide ou n\'a pas accès à ce dépôt.');
    $encoded = str_replace('%2F', '/', rawurlencode($d['ref']));
    $r = http_get(GITHUB_API . '/repos/' . $d['repo'] . '/commits/' . $encoded, gh_headers($d['token'], 'application/vnd.github.sha'));
    $sha = ($r !== null && $r[0] === 200) ? strtolower(trim($r[1])) : '';
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new RuntimeException('Version introuvable dans le dépôt.');

    // 2) Téléchargement: la redirection est suivie à la main pour ne JAMAIS envoyer le jeton au second appel
    if (!make_dir($tmp) || !guard_dir($tmp)) throw new RuntimeException('Dossier temporaire inaccessible.');
    $r = http_get(GITHUB_API . '/repos/' . $d['repo'] . '/zipball/' . $sha, gh_headers($d['token']));
    if ($r === null || $r[0] !== 302 || !preg_match('#^https?://#i', (string) $r[2])) throw new RuntimeException('Téléchargement impossible.');
    $zipFile = $tmp . '/repo.zip';
    if (!download_to($r[2], $zipFile, MAX_ZIP_BYTES)) throw new RuntimeException('Téléchargement impossible.');
    if (@file_get_contents($zipFile, false, null, 0, 2) !== 'PK') throw new RuntimeException('Fichier téléchargé invalide.');
    $d['token'] = ''; // plus utile: jamais conservé

    // 3) Validation complète avant toute écriture sur le site
    $staging = $tmp . '/new';
    $files = extract_archive($zipFile, $staging, $sha);
    $missing = array_diff($REQUIRED_FILES, $files);
    if ($missing) throw new RuntimeException('Version incomplète ou trop ancienne (' . implode(', ', array_slice($missing, 0, 3)) . ').');
    lint_php($staging, $files);
    @unlink($zipFile);

    // 4) Installation des fichiers (ce qui existait déjà, ex. une page d'attente de l'hébergeur, est sauvegardé)
    if (is_file($docroot . '/api/.runtime.env') && is_file($docroot . '/api/index.php') && !is_file($docroot . '/api/var/install.done')) {
        // installation précédente restée incomplète: reprise autorisée, les fichiers sont remplacés
    }
    foreach ($files as $rel) {
        $dest = $docroot . '/' . $rel;
        if (is_file($dest)) {
            $bk = $tmp . '/backup/' . $rel;
            make_dir(dirname($bk));
            if (!@copy($dest, $bk)) throw new RuntimeException('Sauvegarde impossible.');
            $backups[$rel] = $bk;
        }
        copy_atomic($staging . '/' . $rel, $dest);
        $written[] = $rel;
    }

    // 5) Fichiers générés
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
        if (is_file($dest)) { $bk = $tmp . '/backup/' . $rel; make_dir(dirname($bk)); @copy($dest, $bk); $backups[$rel] = $bk; }
        if (@file_put_contents($dest, $content) === false) throw new RuntimeException('Écriture impossible : ' . $rel);
        $written[] = $rel;
    }
    $robots = $docroot . '/robots.txt';
    if (is_file($robots) && !preg_match('/^Sitemap:/mi', (string) file_get_contents($robots))) {
        file_put_contents($robots, rtrim((string) file_get_contents($robots)) . "\n\nSitemap: " . $site . "/sitemap.xml\n");
    }
    // .htaccess: ajout des seuls blocs manquants (le contenu existant de l'hébergeur est conservé)
    $ht = $docroot . '/.htaccess';
    $existing = is_file($ht) ? (string) file_get_contents($ht) : '';
    if (is_file($ht)) { $bk = $tmp . '/backup/.htaccess'; make_dir(dirname($bk)); @copy($ht, $bk); $backups['.htaccess'] = $bk; }
    $add = $existing === '' ? "Options -Indexes\nRewriteEngine On\n" : '';
    foreach (htaccess_blocks($site) as $marker => $block) {
        if (strpos($existing, $marker) === false) $add .= "\n" . $block;
    }
    if (@file_put_contents($ht, $existing . $add) === false) throw new RuntimeException('Écriture impossible : .htaccess');
    $written[] = '.htaccess';

    // 6) Configuration de l'API (jamais écrasée par les mises à jour) et dossiers de données protégés
    $apiDir = $docroot . '/api';
    if (!guard_dir($apiDir . '/logs') || !guard_dir($apiDir . '/var')) throw new RuntimeException('Dossiers de données inaccessibles.');
    $env = "# Généré par install.php — les mises à jour de /auth ne modifient jamais ce fichier.\n";
    $vars = array(
        'SITE_PUBLIC_URL' => $site, 'API_PUBLIC_URL' => $site . '/api', 'API_CORS_ORIGIN' => $site,
        'GOOGLE_CLIENT_ID' => $d['google_id'], 'GOOGLE_CLIENT_SECRET' => $d['google_secret'], 'ADMIN_GOOGLE_EMAILS' => $d['admin_emails'],
        'ADMIN_UPDATE_ENABLED' => $d['update_enabled'] ? 'true' : 'false',
        'UPDATE_GITHUB_REPO' => $d['repo'],
        'RECAPTCHA_SITE_KEY' => $d['recaptcha_site'], 'RECAPTCHA_SECRET_KEY' => $d['recaptcha_secret'],
        'SMTP_HOST' => $d['smtp_host'], 'SMTP_PORT' => $d['smtp_host'] !== '' ? $d['smtp_port'] : '', 'SMTP_SECURE' => $d['smtp_host'] !== '' ? ($d['smtp_secure'] ? 'true' : 'false') : '',
        'SMTP_USER' => $d['smtp_user'], 'SMTP_PASS' => $d['smtp_pass'], 'SMTP_FROM' => $d['smtp_from'], 'CONTACT_DEST' => $d['contact_dest'],
        'SFMN_MODE_ENABLED' => $d['sfmn'] ? 'true' : 'false', 'SFMN_CALCULATOR_URL' => $d['sfmn'] ? DEFAULT_SFMN_URL : '',
        'RESTRICTION_ROUNDING_MODE' => $d['rounding'], 'MEASUREMENT_LOGGING_LEVEL' => $d['logging'],
        'LOGS_RETENTION_MONTHS' => $d['retention'], 'LOGS_MAX_BYTES' => (string) ((int) $d['logs_mb'] * 1048576),
    );
    if ($d['local']) $vars['ADMIN_ALLOW_INSECURE_HTTP'] = 'true'; // uniquement pour un essai en local (http://127.0.0.1)
    foreach ($vars as $name => $value) $env .= env_line($name, $value);
    $envFile = $apiDir . '/.runtime.env';
    if (is_file($envFile)) { $bk = $tmp . '/backup/api/.runtime.env'; make_dir(dirname($bk)); @copy($envFile, $bk); $backups['api/.runtime.env'] = $bk; }
    if (@file_put_contents($envFile, $env) === false) throw new RuntimeException('Écriture impossible : api/.runtime.env');
    @chmod($envFile, 0600);
    $written[] = 'api/.runtime.env';
    if (!is_readable($envFile)) throw new RuntimeException('api/.runtime.env illisible par le serveur web.');

    // 7) État de référence pour les mises à jour de /auth
    $now = gmdate('Y-m-d\TH:i:s.000\Z');
    file_put_contents($apiDir . '/var/version.json', json_encode(array('sha' => $sha, 'ref' => $d['ref'], 'at' => $now, 'by' => 'install.php')));
    file_put_contents($apiDir . '/var/manifest.json', json_encode(array('files' => $files)));
    file_put_contents($apiDir . '/logs/updates.jsonl', json_encode(array('at' => $now, 'action' => 'install', 'by' => 'install.php', 'ref' => $d['ref'], 'to' => $sha, 'ok' => true)) . "\n", FILE_APPEND);
} catch (Exception $e) {
    $fatal = $e->getMessage();
} catch (Throwable $e) {
    $fatal = 'Erreur interne pendant l\'installation.';
    error_log('[INSTALL] ' . get_class($e) . ': ' . $e->getMessage());
}

if ($fatal !== null) {
    // Retour arrière: ce qui a été écrit est retiré, ce qui existait avant est rétabli.
    foreach (array_reverse($written) as $rel) {
        $dest = $docroot . '/' . $rel;
        if (isset($backups[$rel])) @copy($backups[$rel], $dest); else @unlink($dest);
    }
    foreach (array('api/var/version.json', 'api/var/manifest.json', 'api/logs/updates.jsonl') as $rel) @unlink($docroot . '/' . $rel);
    // Dossiers créés par cette tentative: retirés s'ils sont vides (les dossiers de données ne contiennent plus que leur .htaccess).
    foreach (array('api/logs', 'api/var') as $dataDir) {
        if (is_dir($docroot . '/' . $dataDir) && array_diff(scandir($docroot . '/' . $dataDir), array('.', '..', '.htaccess')) === array()) rrmdir($docroot . '/' . $dataDir);
    }
    foreach (array_reverse($written) as $rel) {
        for ($dir = dirname($docroot . '/' . $rel); $dir !== $docroot && strlen($dir) > strlen($docroot); $dir = dirname($dir)) {
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
$out .= '</table>';
if ($bad) {
    // Pas de verrou ni de suppression: l'installation peut être relancée après correction.
    $out .= '<p class="msg">Un contrôle a échoué. Vérifiez la réécriture d\'URL (.htaccess autorisé) et la version PHP du site, puis relancez cette page. Le fichier <code>install.php</code> a été conservé.</p>';
    page('Installation terminée avec un avertissement', $out);
}
file_put_contents($docroot . '/api/var/install.done', gmdate('c'));
$removed = @unlink(__FILE__);
foreach (glob($docroot . '/.install-attempts-*') ?: array() as $f) @unlink($f);
$out .= $removed
    ? '<p class="ok"><code>install.php</code> a été supprimé.</p>'
    : '<p class="msg"><strong>Supprimez maintenant <code>install.php</code> par FTP</strong> (suppression automatique impossible). Il est déjà inactif.</p>';
$out .= '<h2>Prochaines étapes</h2><ul>'
    . ($d['google_id'] !== '' ? '<li>Dans Google Cloud, vérifiez l\'URI de redirection : <code>' . h($site) . '/auth/callback</code>, puis connectez-vous sur <code>' . h($site) . '/auth</code>.</li>' : '<li>La connexion Google n\'est pas configurée : <code>/auth</code> répond 404. Ajoutez les réglages Google dans <code>api/.runtime.env</code> (voir le README).</li>')
    . '<li>Les mises à jour se font désormais depuis <code>/auth</code> (un jeton d\'accès GitHub sera demandé si nécessaire).</li>'
    . '<li>Contrôlez le site : <a href="' . h($site) . '/">' . h($site) . '/</a></li></ul>';
page('Installation terminée', $out);
