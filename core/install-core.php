<?php
// ============================================================================================
// INSTALLATION DU « MINISITE » RIV (moteur de calcul + API + page de saisie)
//
// 1. Remplacez INSTALL_KEY ci-dessous par une valeur secrète d'au moins 24 caractères.
// 2. Envoyez CE SEUL fichier par FTP dans le dossier web de votre hébergement, puis ouvrez-le dans un navigateur :
//      https://<votre-domaine>/install-core.php
// 3. Saisissez la clé : le script télécharge la version demandée, ne retient que les fichiers attendus, les installe
//    dans le sous-dossier choisi (« riv » par défaut), vérifie que le moteur calcule, puis SE SUPPRIME.
//
// Résultat :  <dossier>/index.html, config.js (vos textes), app.js, style.css   → la page de saisie
//             <dossier>/api/                                                     → l'API (config, calculate)
//             <dossier>/engine/                                                  → le moteur (inaccessible depuis le web)
//
// Relancer le script met à jour le moteur et la page SANS écraser config.js (vos textes) ni data/settings.json
// (vos réglages). L'ancienne version du moteur est conservée dans engine.prev/ pendant l'opération et rétablie en cas
// d'échec.
//
// Nécessite : PHP ≥ 8.0 (moteur), extensions zip et curl.
// ============================================================================================

const INSTALL_KEY = 'CHANGEZ-MOI';
const REPO = 'giceyBpro/Radiv';        // dépôt GitHub « propriétaire/nom »
const REF = 'main';                    // branche, ÉTIQUETTE (recommandé : une version figée, ex. « core-v1.0.0 ») ou commit
const CORE_PREFIX = 'core/';           // dossier du moteur dans le dépôt ('' quand le dépôt ne contient que le moteur)
const GITHUB_TOKEN = '';               // seulement si le dépôt est privé (jeton en lecture) ; sinon laisser vide
const DEFAULT_DIR = 'riv';

const MAX_ZIP_BYTES = 20971520;
const MAX_FILE_BYTES = 2097152;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
@ini_set('display_errors', '0');

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function page($title, $body)
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title)
        . '</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:720px;margin:24px auto;padding:0 16px}input{padding:8px;width:100%;box-sizing:border-box;margin:6px 0 14px}'
        . 'button{padding:10px 20px;font:inherit;font-weight:600}.ok{color:#14632b}.ko{color:#a4262c}code{background:#eef;padding:1px 4px}</style></head><body><h1>' . h($title) . '</h1>' . $body . '</body></html>';
    exit;
}

if (PHP_VERSION_ID < 80000) {
    page('PHP trop ancien', '<p class="ko">Ce moteur demande PHP 8.0 ou plus (version actuelle : ' . h(PHP_VERSION) . '). Changez la version de PHP dans le panneau de votre hébergeur.</p>');
}
if (INSTALL_KEY === 'CHANGEZ-MOI' || strlen(INSTALL_KEY) < 24) {
    page('Clé à définir', '<p class="ko">Modifiez <code>INSTALL_KEY</code> au début de ce fichier (24 caractères minimum, par ex. le résultat de <code>openssl rand -hex 24</code>), renvoyez-le, puis rechargez cette page.</p>');
}
if (!class_exists('ZipArchive') || !function_exists('curl_init')) {
    page('Extensions manquantes', '<p class="ko">Les extensions PHP <code>zip</code> et <code>curl</code> sont nécessaires.</p>');
}

// Fichiers acceptés (chemin dans le dépôt, sans CORE_PREFIX) → destination relative au dossier d'installation.
// Tout le reste de l'archive est ignoré.
function destination($rel)
{
    $fixed = array(
        'VERSION' => 'engine/VERSION',
        'api/handler.php' => 'engine/api/handler.php',
        'api/index.php' => 'api/index.php',
        'web/index.html' => 'index.html', 'web/config.js' => 'config.js', 'web/app.js' => 'app.js', 'web/style.css' => 'style.css',
    );
    if (isset($fixed[$rel])) return $fixed[$rel];
    if (preg_match('#^src/[A-Za-z0-9_-]+\.php$#', $rel)) return 'engine/' . $rel;
    if (preg_match('#^data/[A-Za-z0-9_-]+\.(?:json|md)$#', $rel)) return 'engine/' . $rel;
    return null;
}
// Fichiers que l'utilisateur personnalise : jamais écrasés s'ils existent.
function keep_if_exists($dest) { return $dest === 'config.js' || $dest === 'engine/data/settings.json'; }

function rrmdir($dir)
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function download($url, $file)
{
    $headers = array('User-Agent: riv-core-installer', 'Accept: application/vnd.github+json');
    if (GITHUB_TOKEN !== '') $headers[] = 'Authorization: Bearer ' . GITHUB_TOKEN;
    $out = fopen($file, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_FILE => $out, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($r, $total, $now) { return $now > MAX_ZIP_BYTES ? 1 : 0; }));
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($out);
    return $ok !== false && $status === 200;
}

// Lit l'archive et retourne [chemin de destination => contenu] pour les seuls fichiers attendus.
function read_archive($zipFile)
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) throw new RuntimeException('Archive illisible.');
    if ($zip->numFiles > 5000) throw new RuntimeException('Archive refusée (trop de fichiers).');
    $files = array();
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (strpos($name, "\0") !== false || strpos($name, '\\') !== false || in_array('..', explode('/', $name), true) || $name[0] === '/') {
            throw new RuntimeException('Archive refusée (chemin invalide).');
        }
        $parts = explode('/', $name, 2);
        if (!isset($parts[1]) || substr($name, -1) === '/') continue;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
            throw new RuntimeException('Archive refusée (lien symbolique).');
        }
        $rel = $parts[1];
        if (CORE_PREFIX !== '') {
            if (strpos($rel, CORE_PREFIX) !== 0) continue;
            $rel = substr($rel, strlen(CORE_PREFIX));
        }
        $dest = destination($rel);
        if ($dest === null) continue;
        $stat = $zip->statIndex($i);
        if ($stat === false || $stat['size'] > MAX_FILE_BYTES) throw new RuntimeException('Fichier trop volumineux : ' . $rel);
        $total += $stat['size'];
        if ($total > MAX_ZIP_BYTES) throw new RuntimeException('Archive trop volumineuse.');
        $content = $zip->getFromIndex($i);
        if ($content === false) throw new RuntimeException('Lecture impossible : ' . $rel);
        $files[$dest] = $content;
    }
    $zip->close();
    foreach (array('engine/src/bootstrap.php', 'engine/src/calculate.php', 'engine/data/isotopes.json', 'engine/api/handler.php', 'api/index.php', 'index.html', 'app.js') as $needed) {
        if (!isset($files[$needed])) throw new RuntimeException('Version incomplète : ' . $needed . ' manque dans l\'archive.');
    }
    return $files;
}

function write_atomic($path, $content)
{
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) throw new RuntimeException('Dossier non créable : ' . dirname($path));
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Écriture impossible : ' . $path);
    }
}

function install($dirName)
{
    $root = __DIR__ . '/' . $dirName;
    $tmp = __DIR__ . '/.riv-install-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $log = array();
    try {
        $zipFile = $tmp . '/repo.zip';
        if (!download('https://api.github.com/repos/' . REPO . '/zipball/' . rawurlencode(REF), $zipFile)) throw new RuntimeException('Téléchargement impossible (dépôt, version ou jeton incorrects ?).');
        if (@file_get_contents($zipFile, false, null, 0, 2) !== 'PK') throw new RuntimeException('Fichier téléchargé invalide.');
        $files = read_archive($zipFile);

        // L'ancien moteur est mis de côté : rétabli si quoi que ce soit échoue.
        $prev = $root . '/engine.prev';
        rrmdir($prev);
        $hadEngine = is_dir($root . '/engine');
        if ($hadEngine) {
            // settings.json (vos réglages) est conservé : on le recopie dans la nouvelle version.
            if (!@rename($root . '/engine', $prev)) throw new RuntimeException('Ancien moteur non déplaçable.');
        }
        try {
            foreach ($files as $dest => $content) {
                $target = $root . '/' . $dest;
                if (keep_if_exists($dest)) {
                    $old = $dest === 'engine/data/settings.json' ? $prev . '/data/settings.json' : $target;
                    if (is_file($old)) {
                        if ($old !== $target) write_atomic($target, (string) file_get_contents($old));
                        $log[] = 'conservé : ' . $dest;
                        continue;
                    }
                }
                if ($dest === 'config.js' && !is_file($target)) {
                    // Première installation : l'API est dans api/ (et non à la racine comme avec le serveur de développement).
                    $content = str_replace("apiBase: ''", "apiBase: 'api'", $content);
                }
                write_atomic($target, $content);
            }
            write_atomic($root . '/engine/.htaccess', "Require all denied\n");
            write_atomic($root . '/api/.htaccess', "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^ index.php [L]\n");

            // Contrôle : le moteur installé calcule bien (valeurs de référence : Radium-223, 100 µSv/h, 150 cm).
            // Exécuté dans un processus séparé pour ne pas mélanger le moteur installé et ce script.
            $code = "require " . var_export($root . '/engine/api/handler.php', true) . ";"
                . "\$r = RivEngine\\Api\\handle('POST', 'calculate', '{\"isotope_code\":\"radium223\",\"dose_rate\":100,\"patient_size_cm\":150}');"
                . "echo json_encode([\$r[0], \$r[1]['rows'][6]['value'] ?? null]);";
            $res = null;
            if (function_exists('shell_exec') && PHP_BINARY !== '' && strpos((string) ini_get('disable_functions'), 'shell_exec') === false) {
                $res = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'), true);
                if ($res !== array(200, 38) && $res !== array(200, 37)) throw new RuntimeException('Le moteur installé ne donne pas le résultat attendu.');
                $log[] = 'contrôle du calcul : réussi';
            } else {
                $log[] = 'contrôle du calcul : non exécuté (shell_exec indisponible) — essayez la page pour vérifier';
            }
        } catch (Exception $e) {
            rrmdir($root . '/engine');
            if ($hadEngine) @rename($prev, $root . '/engine');
            throw $e;
        }
        rrmdir($prev);
        $log[] = count($files) . ' fichiers installés dans ' . $dirName . '/';
    } finally {
        rrmdir($tmp);
    }
    return $log;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    page('Installation du minisite RIV', '<form method="post"><label>Clé d\'installation<input type="password" name="key" autocomplete="off" required></label>'
        . '<label>Dossier d\'installation (lettres, chiffres, tirets)<input name="dir" value="' . h(DEFAULT_DIR) . '" pattern="[A-Za-z0-9_-]+"></label>'
        . '<p>Source : <code>' . h(REPO) . '</code> @ <code>' . h(REF) . '</code></p><button>Installer</button></form>');
}

if (!hash_equals(INSTALL_KEY, (string) ($_POST['key'] ?? ''))) {
    usleep(500000);
    http_response_code(403);
    page('Clé incorrecte', '<p class="ko">Clé incorrecte.</p>');
}
$dir = (string) ($_POST['dir'] ?? DEFAULT_DIR);
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $dir)) page('Dossier invalide', '<p class="ko">Nom de dossier invalide.</p>');

try {
    $log = install($dir);
} catch (Exception $e) {
    http_response_code(500);
    page('Échec', '<p class="ko">' . h($e->getMessage()) . '</p><p>Rien n\'a été modifié de façon durable (ancienne version rétablie). Ce script est conservé pour un nouvel essai.</p>');
}
@unlink(__FILE__);
page('Installation terminée', '<p class="ok">Le minisite est installé : <a href="' . h($dir) . '/">' . h($dir) . '/</a></p><ul><li>' . implode('</li><li>', array_map('h', $log))
    . '</li></ul><p>Personnalisez titre et textes dans <code>' . h($dir) . '/config.js</code>. Ce script s\'est supprimé' . (file_exists(__FILE__) ? ' — <strong>supprimez-le à la main (FTP)</strong>' : '') . '.</p>');
