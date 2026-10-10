<?php
// ============================================================================================
// MISE À JOUR PONCTUELLE DU SITE (à envoyer seul par FTP, à la racine web, puis ouvrir dans un
// navigateur : https://<votre-domaine>/update.php).
//
// Même effet qu'une mise à jour lancée depuis la page /auth (même code, même validation de
// l'archive, sauvegarde, contrôle de fonctionnement, retour arrière automatique en cas d'échec),
// sans connexion Google : l'accès est protégé par la clé ci-dessous. Après une mise à jour
// réussie, ce fichier SE SUPPRIME. En cas d'échec il est conservé pour une nouvelle tentative :
// supprimez-le vous-même par FTP si vous y renoncez.
//
// Ce fichier n'est JAMAIS publié par deploy.sh ni par /auth ; il s'appuie sur le code déjà
// installé dans api/ (le site doit donc avoir été installé une première fois, voir install.php).
//
// AVANT DE L'ENVOYER : remplacez la clé ci-dessous par une valeur secrète d'au moins 24 caractères
// (par exemple le résultat de `openssl rand -hex 24`). Sans cette clé, la page refuse tout.
//
// Écrit en PHP 7.0 volontairement : message clair plutôt qu'erreur blanche sur un PHP trop ancien.
// ============================================================================================

const UPDATE_KEY = 'CHANGEZ-MOI';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function page($title, $body, $status = 200)
{
    http_response_code($status);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . h($title) . '</title><style>'
        . 'body{font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:2rem auto;padding:0 1rem;color:#1b2430}'
        . 'label{display:block;margin:1rem 0 .25rem;font-weight:600}input{width:100%;box-sizing:border-box;padding:.5rem;font:inherit}'
        . 'button{margin-top:1.25rem;padding:.6rem 1.2rem;font:inherit;cursor:pointer}.ok{color:#116329}.ko{color:#a40e26}.small{font-size:.9rem;color:#555}'
        . '</style></head><body><h1>' . h($title) . '</h1>' . $body . '</body></html>';
    exit;
}

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    page('Mise à jour impossible', '<p class="ko">Ce site exige PHP 8.0 ou plus (version actuelle : ' . h(PHP_VERSION) . ').</p>', 500);
}
if (strlen(UPDATE_KEY) < 24 || UPDATE_KEY === 'CHANGEZ-MOI') {
    page('Page non configurée', '<p>Remplacez la clé dans <code>update.php</code> avant de l\'envoyer.</p>', 403);
}

$api = __DIR__ . '/api';
if (!is_file($api . '/lib/updater.php')) {
    page('Site non installé', '<p class="ko">Le dossier <code>api/</code> est introuvable : utilisez <code>install.php</code>.</p>', 500);
}
foreach (array('calculation.php', 'lib/json.php', 'lib/config.php', 'lib/store.php', 'lib/http.php', 'lib/geo.php',
    'lib/measurements.php', 'lib/sfmn.php', 'lib/contact.php', 'lib/updater.php') as $f) {
    require_once $api . '/' . $f;
}

$formTail = function ($needToken, $ref) {
    return '<form method="post" autocomplete="off">'
        . '<label for="key">Clé</label><input id="key" name="key" type="password" required autocomplete="off">'
        . '<label for="ref">Version (tag, branche ou commit)</label><input id="ref" name="ref" value="' . h($ref) . '" required pattern="[A-Za-z0-9][A-Za-z0-9._\\/\\-]*">'
        . ($needToken ? '<label for="token">Jeton d\'accès</label><input id="token" name="token" type="password" autocomplete="off">' : '')
        . '<button type="submit">Mettre à jour</button></form>'
        . '<p class="small">Le fichier <code>update.php</code> se supprime après une mise à jour réussie.</p>';
};

\Radiv\Config\boot(); // charge api/.runtime.env (dépôt, jeton, SMTP...)
$envToken = \Radiv\Config\env('UPDATE_GITHUB_TOKEN');
$needToken = true;
if ($envToken !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $needToken = !\Radiv\Updater\token_valid($envToken);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    page('Mise à jour du site', $formTail($needToken, 'main'));
}

// --- POST ---------------------------------------------------------------------------------
$fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? (string)$_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin' && $fetchSite !== 'none') {
    page('Requête refusée', '<p class="ko">Requête refusée.</p>', 403);
}
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '-';
if (!\Radiv\Store\rate_limit_hit('updatephp', $ip, 10)) {
    page('Trop d\'essais', '<p class="ko">Trop d\'essais. Réessayez dans une minute.</p>', 429);
}
$given = isset($_POST['key']) ? (string)$_POST['key'] : '';
if (!hash_equals(UPDATE_KEY, $given)) {
    page('Clé incorrecte', '<p class="ko">Clé incorrecte.</p>', 403);
}
$given = '';

$ref = trim(isset($_POST['ref']) ? (string)$_POST['ref'] : '');
$posted = trim(isset($_POST['token']) ? (string)$_POST['token'] : '');
$token = ($envToken !== '' && \Radiv\Updater\token_valid($envToken)) ? $envToken : $posted;
if (!\Radiv\Updater\token_valid($token)) {
    page('Jeton invalide', '<p class="ko">Le jeton n\'est pas valide.</p>' . $formTail(true, $ref), 403);
}

$result = \Radiv\Updater\run($ref, $token, 'update.php');
$token = $posted = '';
if (empty($result['ok'])) {
    page('Échec de la mise à jour', '<p class="ko">' . h($result['message']) . '</p>'
        . '<p class="small">Le site n\'a pas été modifié ou a été remis dans son état précédent. Le fichier <code>update.php</code> a été conservé : relancez après correction, ou supprimez-le par FTP.</p>', 500);
}
$removed = @unlink(__FILE__);
page('Mise à jour terminée', '<p class="ok">' . h($result['message']) . '</p>'
    . ($removed ? '<p>Le fichier <code>update.php</code> a été supprimé.</p>'
        : '<p class="ko"><strong>Supprimez <code>update.php</code> par FTP</strong> (suppression automatique impossible).</p>'));
