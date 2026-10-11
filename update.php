<?php
// ============================================================================================
// MISE À JOUR DE SECOURS DU SITE (à envoyer seul par FTP à la racine web, puis ouvrir dans un navigateur :
// https://<votre-domaine>/update.php).
//
// À utiliser quand la mise à jour depuis /auth n'est pas possible (connexion Google indisponible, code du site
// endommagé ou incomplet...). Ce script est AUTONOME : il ne dépend d'aucun code du site installé, seulement du
// fichier de configuration du backend (config/runtime.env) pour retrouver le dépôt, l'adresse du site et un
// éventuel jeton. Il télécharge la version demandée, la valide AVANT toute écriture (chemins, liens symboliques,
// tailles, liste blanche, syntaxe PHP), sauvegarde ce qu'il remplace (la page /auth peut ensuite rétablir la version
// précédente), remplace les fichiers, REPARE ce qui manque (.htaccess du backend et des dossiers de données, pointeur
// api/backend.php, blocs du .htaccess racine, config.js, sitemap.xml), contrôle que l'API répond et revient en arrière
// sinon.
//
// IDEMPOTENT : le relancer avec la même version réinstalle les mêmes fichiers et redonne le même état ; la
// configuration (config/) et les données (data/) ne sont jamais touchées.
//
// Après un succès, ce fichier SE SUPPRIME. En cas d'échec il est conservé pour un nouvel essai : supprimez-le par
// FTP si vous y renoncez. Il n'est jamais publié par une mise à jour.
//
// AVANT DE L'ENVOYER : remplacez la clé ci-dessous par une valeur secrète d'au moins 24 caractères (par exemple
// le résultat de `openssl rand -hex 24`). Sans cette clé, la page refuse tout.
//
// Écrit en PHP 7.0 volontairement : message clair plutôt qu'erreur blanche sur un PHP trop ancien.
// ============================================================================================

const UPDATE_KEY = 'CHANGEZ-MOI';
const GITHUB_API = 'https://api.github.com';   // modifié uniquement par les tests (faux GitHub local)
const DEFAULT_REPO = 'giceyBpro/Radiv';

// Même liste blanche que backend/src/lib/updater.php et install.php (tests/test-install.js vérifie qu'elles restent
// identiques, ainsi que les fonctions communes). Chemins du DÉPÔT : « public/... » va dans la racine web,
// « backend/... » dans le dossier du backend.
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
const MAX_ZIP_BYTES = 20971520;
const MAX_FILE_BYTES = 10485760;
const MAX_TOTAL_BYTES = 52428800;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
@ini_set('display_errors', '0');

// --- Fonctions communes avec install.php (copie conforme, vérifiée par les tests) ------------------------------------

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

function first_valid_token($repo, $candidates)
{
    foreach ($candidates as $c) {
        if (!preg_match('/^[A-Za-z0-9_\-.]{20,255}$/', $c[0])) continue;
        $r = http_get(GITHUB_API . '/repos/' . $repo, gh_headers($c[0]));
        if ($r !== null && $r[0] === 200) return $c;
    }
    return null;
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

function backend_deny_block($backendUrlPath)
{
    if ($backendUrlPath === null || $backendUrlPath === '') return '';
    $re = preg_quote($backendUrlPath, '#');
    return '# /' . $backendUrlPath . " (backend : accès refusé) v1\nRewriteEngine On\nRewriteRule ^" . $re . "(/|$) - [F,L]\n\n";
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

function backend_url_path($backendDir, $docroot)
{
    return strpos($backendDir, $docroot . '/') === 0 ? substr($backendDir, strlen($docroot) + 1) : null;
}

// --- Garde-fous d'accès ------------------------------------------------------------------------

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    page('PHP trop ancien', '<p class="msg">Cet hébergement utilise PHP ' . h(PHP_VERSION) . '. Le site exige PHP 8.0 ou plus.</p>', 500);
}
if (strlen(UPDATE_KEY) < 24 || UPDATE_KEY === 'CHANGEZ-MOI') {
    page('Page non configurée', '<p class="msg">Remplacez la valeur de <code>UPDATE_KEY</code> dans <code>update.php</code> par une clé secrète d\'au moins 24 caractères, puis renvoyez le fichier par FTP.</p>', 403);
}
$docroot = rtrim(str_replace('\\', '/', __DIR__), '/');
$isLocal = in_array(isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '', array('localhost', '127.0.0.1'), true);
$https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if (!$https && !$isLocal) {
    page('HTTPS requis', '<p class="msg">Une clé et un jeton sont saisis dans cette page : ouvrez-la en <code>https://</code>.</p>', 403);
}

// Limite les essais de clé : 10 échecs par heure et par adresse (fichier temporaire du dossier).
function throttle_file()
{
    return __DIR__ . '/.update-attempts-' . substr(hash('sha256', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 12);
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

// Emplacement du backend : comme la façade api/index.php (RADIV_BACKEND, puis api/backend.php, puis <racine>/backend).
function locate_backend($docroot)
{
    $backend = (string) (getenv('RADIV_BACKEND') ? getenv('RADIV_BACKEND') : '');
    if ($backend === '' && is_file($docroot . '/api/backend.php')) {
        if (function_exists('opcache_invalidate')) @opcache_invalidate($docroot . '/api/backend.php', true);
        $pointer = include $docroot . '/api/backend.php';
        if (is_string($pointer)) $backend = $pointer;
    }
    if ($backend === '') $backend = $docroot . '/backend';
    return rtrim($backend, '/\\');
}

function read_json($file)
{
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : null;
}

$backend = locate_backend($docroot);
$configFile = $backend . '/config/runtime.env';
if (!is_dir($backend) || !is_file($configFile)) {
    page('Site non installé', '<p class="msg">Le backend ou sa configuration est introuvable (<code>' . h($backend) . '</code>). Si le site n\'a pas encore été installé, utilisez <code>install.php</code>.</p>', 500);
}
$cfg = parse_env_file($configFile);
$cfg = $cfg === null ? array() : $cfg;
$repo = isset($cfg['UPDATE_GITHUB_REPO']) && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $cfg['UPDATE_GITHUB_REPO']) ? $cfg['UPDATE_GITHUB_REPO'] : DEFAULT_REPO;
$site = isset($cfg['SITE_PUBLIC_URL']) ? rtrim($cfg['SITE_PUBLIC_URL'], '/') : '';
if ($site !== '' && !preg_match('#^(https://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d+)?|http://(localhost|127\.0\.0\.1)(:\d+)?)$#', $site)) $site = '';
$dataDir = $backend . '/data';
if (isset($cfg['DATA_DIR']) && $cfg['DATA_DIR'] !== '' && strpos($cfg['DATA_DIR'], '..') === false) {
    $dd = $cfg['DATA_DIR'];
    $dataDir = $dd[0] === '/' ? normalize_path($dd) : normalize_path($backend . '/' . $dd);
}
$current = read_json($dataDir . '/var/version.json');
$cfgToken = isset($cfg['UPDATE_GITHUB_TOKEN']) ? $cfg['UPDATE_GITHUB_TOKEN'] : '';

function field($name)
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
}

function update_form($needToken, $ref, $errors)
{
    $out = $errors ? '<p class="msg">' . implode('<br>', array_map('h', $errors)) . '</p>' : '';
    return $out . '<form method="post" autocomplete="off">'
        . '<label for="key">Clé</label><input id="key" name="key" type="password" required autocomplete="off">'
        . '<label for="ref">Version (tag, branche ou commit)</label><input id="ref" name="ref" type="text" value="' . h($ref) . '" required pattern="[A-Za-z0-9][A-Za-z0-9._\\/\\-]*">'
        . ($needToken ? '<label for="token">Jeton d\'accès</label><input id="token" name="token" type="password" autocomplete="off">' : '')
        . '<button type="submit">Mettre à jour / réparer</button></form>'
        . '<p class="small">Le fichier <code>update.php</code> se supprime après un succès.</p>';
}

$defaultRef = $current && !empty($current['ref']) && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $current['ref']) ? $current['ref'] : 'main';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $needToken = true;
    if ($cfgToken !== '' && function_exists('curl_init')) $needToken = first_valid_token($repo, array(array($cfgToken, 'config'))) === null;
    $state = '<table><tr><td>Backend</td><td>' . h($backend) . '</td></tr><tr><td>Dépôt</td><td>' . h($repo) . '</td></tr>'
        . '<tr><td>Version installée</td><td>' . ($current ? h(substr((string) (isset($current['sha']) ? $current['sha'] : ''), 0, 7) . ' (' . (isset($current['ref']) ? $current['ref'] : '?') . ')') : 'inconnue') . '</td></tr></table>'
        . '<p class="small">Relancer avec la version déjà installée réinstalle les mêmes fichiers : c\'est une réparation.</p>';
    page('Mise à jour de secours', $state . update_form($needToken, $defaultRef, array()));
}

// --- Mise à jour -------------------------------------------------------------------------------

if (throttled()) page('Trop d\'essais', '<p class="msg">Trop de clés incorrectes. Réessayez dans une heure.</p>', 429);
$given = isset($_POST['key']) && is_string($_POST['key']) ? $_POST['key'] : '';
if (!hash_equals(UPDATE_KEY, $given)) {
    record_failure();
    page('Clé incorrecte', '<p class="msg">Clé incorrecte.</p>', 403);
}
$given = '';
$fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string) $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin' && $fetchSite !== 'none') {
    page('Requête refusée', '<p class="msg">Requête refusée.</p>', 403);
}
$ref = field('ref');
if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $ref) || strpos($ref, '..') !== false) {
    page('Version invalide', update_form($cfgToken === '', $defaultRef, array('Version invalide.')), 400);
}
$candidates = array();
if (field('token') !== '') {
    if (!preg_match('/^[A-Za-z0-9_\-.]{20,255}$/', field('token'))) page('Jeton invalide', update_form(true, $ref, array('Jeton invalide.')), 400);
    $candidates[] = array(field('token'), 'form');
}
if ($cfgToken !== '') $candidates[] = array($cfgToken, 'config');
if (!$candidates) page('Jeton nécessaire', update_form(true, $ref, array('Un jeton d\'accès est nécessaire.')), 400);
if (!class_exists('ZipArchive') || !function_exists('curl_init')) {
    page('Mise à jour impossible', '<p class="msg">Les extensions PHP zip et cURL sont nécessaires.</p>', 500);
}
@set_time_limit(300);
ignore_user_abort(true);

$varDir = $dataDir . '/var';
$tmp = $docroot . '/.update-tmp-' . bin2hex(random_bytes(4));
$backupDir = $varDir . '/backup/prev';
$backupRoot = $tmp . '/rollback';      // sauvegarde des fichiers hors liste blanche modifiés par la réparation
$fatal = null;
$applied = false;
$backupStarted = false;
$lock = null;
$health = 'unreachable';
$notes = array();
$generated = array();                   // fichiers générés ou complétés (chemin absolu => sauvegarde ou null)
try {
    if (!make_dir($tmp) || !guard_dir($tmp)) throw new RuntimeException('Dossier temporaire inaccessible.');
    foreach (array($backend . '/config', $dataDir, $dataDir . '/logs', $varDir) as $dir) {
        if (!guard_dir($dir)) throw new RuntimeException('Dossier inaccessible : ' . $dir);
    }
    $lock = @fopen($varDir . '/update.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Une opération est déjà en cours.');

    // 1) Jeton valide + commit exact
    $good = first_valid_token($repo, $candidates);
    if ($good === null) throw new RuntimeException('Le jeton n\'est pas valide ou n\'a pas accès au dépôt.');
    $token = $good[0];
    $encoded = str_replace('%2F', '/', rawurlencode($ref));
    $r = http_get(GITHUB_API . '/repos/' . $repo . '/commits/' . $encoded, gh_headers($token, 'application/vnd.github.sha'));
    $sha = ($r !== null && $r[0] === 200) ? strtolower(trim($r[1])) : '';
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new RuntimeException('Version introuvable dans le dépôt.');
    if ($current && isset($current['sha']) && $current['sha'] === $sha) {
        $notes[] = 'Cette version était déjà installée : les fichiers ont été réinstallés (réparation).';
        // Même version : la sauvegarde de la version précédente (retour arrière de /auth) est conservée telle quelle ;
        // celle de cette opération reste dans le dossier temporaire, utilisée seulement en cas d'échec.
        $backupDir = $tmp . '/prev';
    }

    // 2) Téléchargement : la redirection est suivie à la main pour ne JAMAIS envoyer le jeton au second appel
    $r = http_get(GITHUB_API . '/repos/' . $repo . '/zipball/' . $sha, gh_headers($token));
    if ($r === null || $r[0] !== 302 || !preg_match('#^https?://#i', (string) $r[2])) throw new RuntimeException('Téléchargement impossible.');
    $zipFile = $tmp . '/repo.zip';
    if (!download_to($r[2], $zipFile, MAX_ZIP_BYTES)) throw new RuntimeException('Téléchargement impossible.');
    if (@file_get_contents($zipFile, false, null, 0, 2) !== 'PK') throw new RuntimeException('Fichier téléchargé invalide.');
    $token = ''; $good = null; $candidates = array(); $cfgToken = '';

    // 3) Validation complète avant toute écriture sur le site
    $staging = $tmp . '/new';
    $files = extract_archive($zipFile, $staging, $sha);
    $missing = array_diff($REQUIRED_FILES, $files);
    if ($missing) throw new RuntimeException('Version incomplète ou trop ancienne (' . implode(', ', array_slice($missing, 0, 3)) . ').');
    lint_php($staging, $files);
    @unlink($zipFile);

    // 4) Sauvegarde (même format que la mise à jour de /auth : le retour arrière manuel y reste possible)
    $oldManifest = array();
    $mf = read_json($varDir . '/manifest.json');
    if ($mf && isset($mf['files']) && is_array($mf['files'])) {
        foreach ($mf['files'] as $f) { if (is_string($f) && allowed($f)) $oldManifest[] = $f; }
    }
    $backupStarted = true;
    rrmdir($backupDir);
    $existing = array();
    $added = array();
    foreach (array_unique(array_merge($files, $oldManifest)) as $rel) {
        $dest = dest_of($rel, $docroot, $backend);
        if (is_file($dest)) {
            make_dir(dirname($backupDir . '/files/' . $rel));
            if (!@copy($dest, $backupDir . '/files/' . $rel)) throw new RuntimeException('Sauvegarde impossible.');
            $existing[] = $rel;
        } elseif (in_array($rel, $files, true)) {
            $added[] = $rel;
        }
    }
    file_put_contents($backupDir . '/backup.json', json_encode(array('at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'from' => $current, 'to_sha' => $sha, 'existing' => $existing, 'added' => $added, 'manifest' => $oldManifest)));

    // 5) Remplacement : le backend d'abord (bibliothèques, puis application), la façade publique ensuite
    $applied = true;
    $rank = function ($rel) {
        return (strpos($rel, 'backend/src/lib/') === 0 || $rel === 'backend/src/calculation.php') ? 0
            : ($rel === 'public/api/index.php' ? 2 : (strpos($rel, 'backend/') === 0 ? 1 : 3));
    };
    $ordered = $files;
    usort($ordered, function ($a, $b) use ($rank) { $ra = $rank($a); $rb = $rank($b); return $ra === $rb ? strcmp($a, $b) : $ra - $rb; });
    foreach ($ordered as $rel) {
        $dest = dest_of($rel, $docroot, $backend);
        copy_atomic($staging . '/' . $rel, $dest);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
    }
    foreach (array_diff($oldManifest, $files) as $obsolete) {
        $dest = dest_of($obsolete, $docroot, $backend);
        @unlink($dest);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
    }

    // 6) Réparations idempotentes de ce que la liste blanche ne couvre pas (rien n'est modifié s'il est déjà correct)
    $backendUrl = backend_url_path($backend, $docroot);
    $save = function ($dest) use (&$generated, $backupRoot) {
        if (isset($generated[$dest])) return;
        if (is_file($dest)) {
            $bk = $backupRoot . '/' . sha1($dest);
            make_dir(dirname($bk));
            if (!@copy($dest, $bk)) throw new RuntimeException('Sauvegarde impossible.');
            $generated[$dest] = $bk;
        } else {
            $generated[$dest] = null;
        }
    };
    $pointer = $docroot . '/api/backend.php';
    if (!is_file($pointer)) {
        $save($pointer);
        file_put_contents($pointer, "<?php\n// Généré par update.php : emplacement du backend.\nreturn " . var_export($backend, true) . ";\n");
        $notes[] = 'api/backend.php recréé.';
    }
    if ($site !== '') {
        $siteHost = preg_replace('#^https?://#', '', $site);
        $bareHost = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $siteHost));
        $gen = array(
            'config.js' => 'window.RADIOPROTECTION_API_URL = "' . $site . '/api";' . "\n"
                . 'window.RADIOPROTECTION_SITE_URL = "' . $site . '";' . "\n"
                . 'window.RADIOPROTECTION_SITE_NAME = "' . $bareHost . '";' . "\n"
                . 'window.RADIOPROTECTION_COPYRIGHT_OWNER = "' . $bareHost . '";' . "\n"
                . 'window.RADIOPROTECTION_LAST_DEPLOYED_AT = "' . date('Y-m-d') . '";' . "\n",
            'sitemap.xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
                . '  <url>' . "\n" . '    <loc>' . h($site . '/') . '</loc>' . "\n" . '    <changefreq>monthly</changefreq>' . "\n" . '    <priority>1.0</priority>' . "\n" . '  </url>' . "\n" . '</urlset>' . "\n",
        );
        foreach ($gen as $rel => $content) {
            $dest = $docroot . '/' . $rel;
            if (is_file($dest)) continue; // déjà présent : jamais écrasé
            $save($dest);
            file_put_contents($dest, $content);
            $notes[] = $rel . ' recréé.';
        }
        $robots = $docroot . '/robots.txt';
        if (is_file($robots) && !preg_match('/^Sitemap:/mi', (string) file_get_contents($robots))) {
            $save($robots);
            file_put_contents($robots, rtrim((string) file_get_contents($robots)) . "\n\nSitemap: " . $site . "/sitemap.xml\n");
        }
        // .htaccess racine : seuls les blocs manquants sont ajoutés (le contenu existant est conservé)
        $ht = $docroot . '/.htaccess';
        $existingHt = is_file($ht) ? (string) file_get_contents($ht) : '';
        $add = $existingHt === '' ? "Options -Indexes\nRewriteEngine On\n" : '';
        foreach (htaccess_blocks($site, $backendUrl) as $marker => $block) {
            if (strpos($existingHt, $marker) === false) $add .= "\n" . $block;
        }
        $head = '';
        $denyBlock = backend_deny_block($backendUrl);
        if ($denyBlock !== '' && strpos($existingHt, strtok($denyBlock, "\n")) === false) $head .= $denyBlock;
        $httpsMarker = '# /https (redirection HTTP vers HTTPS) v1';
        if (strpos($site, 'https://') === 0 && strpos($existingHt, $httpsMarker) === false) {
            $head .= $httpsMarker . "\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteCond %{HTTP:X-Forwarded-Proto} !https\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=308]\n\n";
        }
        if ($head !== '' || $add !== '') {
            $save($ht);
            if (@file_put_contents($ht, $head . $existingHt . $add) === false) throw new RuntimeException('Écriture impossible : .htaccess');
            $notes[] = '.htaccess complété.';
        }
    }

    // 7) État de référence (version, manifeste, journal) pour /auth
    $now = gmdate('Y-m-d\TH:i:s.000\Z');
    file_put_contents($varDir . '/manifest.json', json_encode(array('files' => $files)));
    file_put_contents($varDir . '/version.json', json_encode(array('sha' => $sha, 'ref' => $ref, 'at' => $now, 'by' => 'update.php')));

    // 8) Contrôle de fonctionnement : l'API doit répondre correctement (3 essais), sinon retour arrière
    if ($site !== '') {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) sleep(1);
            $resp = health_get($site . '/api/config');
            if ($resp === null) continue;
            $data = json_decode($resp[1], true);
            if ($resp[0] === 200 && is_array($data) && isset($data['isotopes'])) { $health = 'ok'; break; }
            $health = 'bad';
        }
    }
    if ($health === 'bad') throw new RuntimeException('La nouvelle version ne répond pas correctement : l\'ancienne a été rétablie automatiquement.');
    @file_put_contents($dataDir . '/logs/updates.jsonl', json_encode(array('at' => $now, 'action' => 'update', 'by' => 'update.php', 'ref' => $ref, 'from' => $current && isset($current['sha']) ? $current['sha'] : null, 'to' => $sha, 'ok' => true, 'health' => $health)) . "\n", FILE_APPEND);
} catch (Exception $e) {
    $fatal = $e->getMessage();
} catch (Throwable $e) {
    $fatal = 'Erreur interne pendant la mise à jour.';
    error_log('[UPDATE] ' . get_class($e) . ': ' . $e->getMessage());
}

if ($fatal !== null) {
    if ($applied) {
        // Retour arrière : d'abord les fichiers générés ou complétés, puis ceux de la liste blanche (depuis la sauvegarde ; elle
        // l'emporte pour un fichier présent dans les deux, comme robots.txt), puis l'état de référence
        foreach ($generated as $dest => $bk) {
            if ($bk !== null) @copy($bk, $dest); else @unlink($dest);
        }
        $info = read_json($backupDir . '/backup.json');
        if ($info) {
            foreach (isset($info['existing']) ? $info['existing'] : array() as $rel) {
                if (allowed($rel)) { @copy($backupDir . '/files/' . $rel, dest_of($rel, $docroot, $backend)); if (function_exists('opcache_invalidate')) @opcache_invalidate(dest_of($rel, $docroot, $backend), true); }
            }
            foreach (isset($info['added']) ? $info['added'] : array() as $rel) {
                if (allowed($rel)) @unlink(dest_of($rel, $docroot, $backend));
            }
            file_put_contents($varDir . '/manifest.json', json_encode(array('files' => isset($info['manifest']) ? $info['manifest'] : array())));
            if (!empty($info['from'])) file_put_contents($varDir . '/version.json', json_encode($info['from'])); else @unlink($varDir . '/version.json');
        }
    }
    if ($backupStarted) rrmdir($backupDir); // une sauvegarde partielle ou déjà rétablie ne doit jamais rester proposée par /auth
    @file_put_contents($dataDir . '/logs/updates.jsonl', json_encode(array('at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'action' => 'update', 'by' => 'update.php', 'ref' => $ref, 'ok' => false, 'error' => $fatal)) . "\n", FILE_APPEND);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    rrmdir($tmp);
    page('Échec de la mise à jour', '<p class="msg">' . h($fatal) . '</p><p class="small">Le site a été remis dans son état précédent. Le fichier <code>update.php</code> est conservé : relancez après correction, ou supprimez-le par FTP.</p>'
        . update_form(false, $ref, array()), 500);
}

if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
rrmdir($tmp);
foreach (glob($docroot . '/.update-attempts-*') ?: array() as $f) @unlink($f);
$removed = @unlink(__FILE__);
$out = '<p class="ok"><strong>' . h($ref) . '</strong> installée : <code>' . h(substr($sha, 0, 7)) . '</code>, ' . count($files) . ' fichiers.</p>'
    . '<p class="small">Contrôle de l\'API : ' . ($health === 'ok' ? 'répond correctement' : 'non vérifiable depuis le serveur, vérifiez le site') . '.</p>';
foreach ($notes as $n) $out .= '<p class="small">' . h($n) . '</p>';
$out .= $removed
    ? '<p class="ok"><code>update.php</code> a été supprimé.</p>'
    : '<p class="msg"><strong>Supprimez <code>update.php</code> par FTP</strong> (suppression automatique impossible).</p>';
$out .= '<p>Le retour à la version précédente reste possible depuis <code>/auth</code>.</p>';
page('Mise à jour terminée', $out);
