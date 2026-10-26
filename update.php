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
    'favicon.ico', 'og-image.png', 'robots.txt',
);
$REQUIRED_FILES = array(
    'public/index.html', 'public/api/.htaccess', 'public/api/index.php',
    'backend/src/app.php', 'core/src/bootstrap.php', 'core/src/calculate.php', 'core/data/isotopes.json', 'backend/src/lib/config.php',
    'backend/src/lib/updater.php', 'backend/src/lib/admin_site.php', 'backend/src/lib/google.php', 'backend/src/lib/session.php',
);
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
== Emplacement du backend et des données
BACKEND_DIR|Dossier du backend (code, configuration, données), de préférence HORS de la racine web (ex. ~/radiv-backend ou ../backend).
|Chemin absolu, « ~/... » ou relatif à la racine du site. Non défini = emplacement actuel (par défaut backend/ dans la racine web).
|POUR LE DÉPLACER : modifiez cette ligne puis lancez update.php : il déplace le dossier (sans rien supprimer) et met à jour le site.
DATA_DIR|Dossier des journaux et de l'état du site (sessions, version installée, sauvegarde). Facultatif :
|par défaut le dossier data/ du backend. Chemin absolu, ou relatif au backend ; jamais lisible depuis le web.
|Modifié, il est déplacé de la même façon par update.php (journaux et sessions conservés).
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
CORE_GITHUB_REPO|Dépôt GitHub du moteur de calcul (propriétaire/nom), proposé par défaut dans /auth/moteur. Défaut : UPDATE_GITHUB_REPO.
CORE_GITHUB_TOKEN|Jeton GitHub en lecture seule (confidentiel) pour télécharger le moteur de calcul depuis un dépôt privé distinct
|(zone de test /auth/moteur). Non défini = UPDATE_GITHUB_TOKEN, puis la saisie dans la page (non conservée).
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
SFMN_CALCULATOR_URL|Adresse de l'outil en ligne de la SFMN : proposée en lien sur les pages du site (accueil, mentions légales, version
|imprimable) et interrogée par la seule page de test de l'administration. Absente : le lien garde l'adresse par défaut des pages.
SFMN_DEBUG|true ou false. Diagnostic détaillé de la page de test SFMN de l'administration : expose des données distantes dans les réponses,
|à laisser sur false. Défaut : false.
== Mesures et journaux (RGPD)
MEASUREMENT_LOGGING_LEVEL|full = tout (IP, géolocalisation, données saisies, résultat) ; user = date, IP et lieu
|seulement ; none = rien. Défaut : full.
LOGS_RETENTION_MONTHS|Supprime les journaux plus vieux que N mois. Non défini = 12 mois. 0 = conservation illimitée (à justifier).
LOGS_MAX_BYTES|Taille maximale d'un journal mensuel, en octets. Défaut : 52428800 (50 Mo).
== Espace disque
DISK_QUOTA_MB|Espace disque de l'hébergement, en Mo (ex. 100). Sert à mesurer l'occupation du site. Vide = mesure du disque du serveur
|(peu utile en hébergement mutualisé, où ce disque est partagé).
DISK_MIN_FREE_MB|Espace libre minimal, en Mo : en dessous, les journaux de mesures les plus anciens sont supprimés. Défaut : 30. 0 = jamais.
== Protection contre les abus
CALCULATE_RATE_LIMIT|Nombre maximal de calculs par minute et par adresse IP. Défaut : 60.
API_KEY_REQUIRED|true ou false. true = les appels extérieurs à /api/calculate exigent une clé d'API (en-tête X-API-Key), créée dans
|l'onglet Fournisseurs de /auth ; les pages du site en sont dispensées. false = appels sans clé acceptés. Défaut : false.
|Le choix fait dans l'onglet Fournisseurs de /auth (bouton « Rendre la clé obligatoire / facultative ») l'emporte sur cette ligne.
PROVIDER_RATE_LIMIT|Nombre maximal de calculs par minute et par fournisseur (clé d'API), sauf plafond propre au fournisseur. Défaut : 300.
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
    if ($parts[0] === 'core') {
        // Moteur de calcul partagé (copié tel quel dans <backend>/core/) : src/*.php, data/*.json et VERSION.
        // Jamais api/ ni tests/ (démonstration autonome et outillage de développement).
        if ($rel === 'core/VERSION') return true;
        if ($count === 3 && $parts[1] === 'src') return preg_match('/^[A-Za-z0-9_-]+\.php$/', $parts[2]) === 1;
        if ($count === 3 && $parts[1] === 'data') return preg_match('/^[A-Za-z0-9_-]+\.json$/', $parts[2]) === 1;
        return false;
    }
    return false;
}

function dest_of($rel, $docroot, $backendDir)
{
    if (strpos($rel, 'public/') === 0) return $docroot . '/' . substr($rel, 7);
    if (strpos($rel, 'backend/') === 0) return $backendDir . '/' . substr($rel, 8);
    if (strpos($rel, 'core/') === 0) return $backendDir . '/' . $rel;
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
    // Pages indexables, mentions légales et plan du site : servis par l'API PHP, qui complète le HTML des balises propres à l'adresse du
    // site (canonique absolue, Open Graph, données structurées). « mentions-legales.html » est aussi la cible de l'ancienne règle /legal.
    $seo = '# /seo (accueil, mentions légales et plan du site par l\'API PHP) v1';
    $blocks[$seo] = $seo . "\nRewriteEngine On\nRewriteRule ^(index\\.html)?$ api/index.php [L]\nRewriteRule ^mentions-legales\\.html$ api/index.php [L]\nRewriteRule ^sitemap\\.xml$ api/index.php [L]\n";
    // Compression et cache navigateur (vitesse de chargement) ; sans effet si les modules Apache correspondants manquent.
    $perf = '# Compression et cache v1';
    $blocks[$perf] = $perf . "\n<IfModule mod_deflate.c>\nAddOutputFilterByType DEFLATE text/html text/css text/plain text/xml application/javascript application/json application/xml image/svg+xml\n</IfModule>\n"
        . "<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType text/css \"access plus 1 hour\"\nExpiresByType application/javascript \"access plus 1 hour\"\n"
        . "ExpiresByType text/javascript \"access plus 1 hour\"\nExpiresByType image/png \"access plus 1 month\"\nExpiresByType image/x-icon \"access plus 1 month\"\n"
        . "ExpiresByType image/vnd.microsoft.icon \"access plus 1 month\"\nExpiresByType font/woff2 \"access plus 1 year\"\nExpiresByType font/woff \"access plus 1 year\"\n"
        . "ExpiresByType application/font-woff2 \"access plus 1 year\"\n</IfModule>\n";
    // Les pages HTML sont revalidées à chaque visite (sinon les navigateurs en gardent une copie « fraîche » plusieurs jours).
    $nocache = '# Pages HTML toujours revalidées v1';
    $blocks[$nocache] = $nocache . "\n<IfModule mod_headers.c>\n<FilesMatch \"\\.html$\">\nHeader set Cache-Control \"no-cache\"\n</FilesMatch>\n</IfModule>\n";
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

function htaccess_head($existing, $siteUrl, $backendUrlPath)
{
    $head = '';
    $deny = backend_deny_block($backendUrlPath);
    if ($deny !== '' && strpos($existing, strtok($deny, "\n")) === false) $head .= $deny;
    $toxMarker = '# /tox (redirection vers tox.radiv.fr) v1';
    if (strpos($existing, $toxMarker) === false) {
        $head .= $toxMarker . "\nRewriteEngine On\nRewriteRule ^tox(?:\\.html)?/?$ https://tox.radiv.fr/ [R=301,L]\nRewriteRule ^tox/(.+)$ https://tox.radiv.fr/$1 [R=301,L]\n\n";
    }
    $httpsMarker = '# /https (redirection HTTP vers HTTPS) v1';
    if (strpos($siteUrl, 'https://') === 0 && strpos($existing, $httpsMarker) === false) {
        $head .= $httpsMarker . "\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteCond %{HTTP:X-Forwarded-Proto} !https\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=308]\n\n";
    }
    return $head;
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

function env_line($name, $value)
{
    $value = (string) $value;
    if ($value === '') return '';
    if (strpbrk($value, "\r\n\0") !== false) throw new RuntimeException($name . ' contient un caractère interdit.');
    if (strpos($value, '"') === false) return $name . '="' . $value . '"' . "\n";
    if (strpos($value, "'") === false) return $name . "='" . $value . "'\n";
    throw new RuntimeException($name . ' ne peut pas contenir à la fois des guillemets simples et doubles.');
}

function render_runtime_env($vars, $by = 'install.php')
{
    global $RUNTIME_LAYOUT;
    $out = "# Configuration du site. Lue à CHAQUE requête : toute modification est prise en compte immédiatement.\n"
        . "# Généré par " . $by . " le " . date('Y-m-d H:i') . " — les mises à jour de /auth ne modifient jamais ce fichier.\n"
        . "# Pour réinstaller ou régénérer, voir le README ; config/local.env reste pour les réglages purement locaux.\n"
        . "# Format du fichier : 2\n"
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

// Déplace un dossier : renommage, ou à défaut (autre volume) copie vérifiée dont la source est conservée jusqu'au succès final.
function copy_tree($from, $to, &$count, &$bytes)
{
    if (!make_dir($to)) throw new RuntimeException('Copie impossible : ' . $to);
    foreach (scandir($from) as $n) {
        if ($n === '.' || $n === '..') continue;
        $a = $from . '/' . $n;
        $b = $to . '/' . $n;
        if (is_link($a)) throw new RuntimeException('Lien symbolique non déplaçable : ' . $a);
        if (is_dir($a)) { copy_tree($a, $b, $count, $bytes); continue; }
        if (!@copy($a, $b)) throw new RuntimeException('Copie impossible : ' . $a);
        @chmod($b, fileperms($a) & 0777);
        $count++;
        $bytes += filesize($b);
    }
}
function tree_stats($dir, &$count, &$bytes)
{
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') continue;
        $a = $dir . '/' . $n;
        if (is_dir($a) && !is_link($a)) { tree_stats($a, $count, $bytes); continue; }
        $count++;
        $bytes += is_link($a) ? 0 : filesize($a);
    }
}
function move_path($from, $to, &$moved)
{
    if (!make_dir(dirname($to))) throw new RuntimeException('Dossier inaccessible : ' . dirname($to));
    if (is_dir($to)) @rmdir($to);
    if (@rename($from, $to)) { $moved[] = array($from, $to, 'rename'); return; }
    $c1 = $b1 = $c2 = $b2 = 0;
    try {
        copy_tree($from, $to, $c1, $b1);
        tree_stats($to, $c2, $b2);
        if ($c1 !== $c2 || $b1 !== $b2) throw new RuntimeException('Copie incomplète : ' . $to);
    } catch (RuntimeException $e) {
        rrmdir($to);
        throw $e;
    }
    $moved[] = array($from, $to, 'copy');
}

$backend = locate_backend($docroot);
$configFile = $backend . '/config/runtime.env';
// Ancienne structure (tout dans api/ : .runtime.env, lib/, logs/, var/) : migrée vers public/ + backend/ par ce script.
$oldApi = $docroot . '/api';
$legacy = !is_file($configFile) && is_file($oldApi . '/.runtime.env') && is_file($oldApi . '/lib/config.php');
if ($legacy) $configFile = $oldApi . '/.runtime.env';
if (!$legacy && (!is_dir($backend) || !is_file($configFile))) {
    page('Site non installé', '<p class="msg">Le backend ou sa configuration est introuvable (<code>' . h($backend) . '</code>). Si le site n\'a pas encore été installé, utilisez <code>install.php</code>.</p>', 500);
}
$cfg = parse_env_file($configFile);
$cfg = $cfg === null ? array() : $cfg;
$repo = isset($cfg['UPDATE_GITHUB_REPO']) && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $cfg['UPDATE_GITHUB_REPO']) ? $cfg['UPDATE_GITHUB_REPO'] : DEFAULT_REPO;
$site = isset($cfg['SITE_PUBLIC_URL']) ? rtrim($cfg['SITE_PUBLIC_URL'], '/') : '';
if ($site !== '' && !preg_match('#^(https://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d+)?|http://(localhost|127\.0\.0\.1)(:\d+)?)$#', $site)) $site = '';
// Dossier de données d'après DATA_DIR (absolu, ou relatif au backend donné), sinon <backend>/data
function data_path_for($cfg, $backend)
{
    if (isset($cfg['DATA_DIR']) && $cfg['DATA_DIR'] !== '' && strpos($cfg['DATA_DIR'], '..') === false) {
        $dd = $cfg['DATA_DIR'];
        return $dd[0] === '/' ? normalize_path($dd) : normalize_path($backend . '/' . $dd);
    }
    return $backend . '/data';
}
// Emplacements SOUHAITÉS (BACKEND_DIR et DATA_DIR de runtime.env) et ACTUELS (pointeur api/backend.php ; dossier de données
// mémorisé dans config/.data-dir, à défaut celui qui existe). S'ils diffèrent, la mise à jour déplace les dossiers.
$wantedBackend = $backend;
$layoutError = null;
if (!$legacy && !empty($cfg['BACKEND_DIR'])) {
    list($wb, $layoutError) = resolve_backend_dir($cfg['BACKEND_DIR'], $docroot);
    if ($layoutError === null) $wantedBackend = $wb;
}
$wantedData = data_path_for($cfg, $wantedBackend);
$recorded = !$legacy && is_file($backend . '/config/.data-dir') ? trim((string) @file_get_contents($backend . '/config/.data-dir')) : '';
$dataDir = data_path_for($cfg, $backend);
if ($recorded !== '' && $recorded[0] === '/') $dataDir = normalize_path($recorded);
elseif (!is_dir($dataDir) && is_dir($backend . '/data')) $dataDir = $backend . '/data';
if ($legacy) { $dataDir = $backend . '/data'; $wantedData = $dataDir; }
$origBackend = $backend;
$origData = $dataDir;
$current = read_json(($legacy ? $oldApi : $dataDir) . '/var/version.json');
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
    $plan = '';
    if ($layoutError !== null) $plan = '<p class="msg">' . h($layoutError) . '</p>';
    elseif (!$legacy && ($wantedBackend !== $backend || $wantedData !== $dataDir)) {
        $plan = '<p class="msg">Déplacement prévu par <code>runtime.env</code> (rien n\'est supprimé) :'
            . ($wantedBackend !== $backend ? '<br>backend : <code>' . h($backend) . '</code> → <code>' . h($wantedBackend) . '</code>' : '')
            . ($wantedData !== $dataDir && !($wantedBackend !== $backend && $dataDir === $backend . '/data' && $wantedData === $wantedBackend . '/data') ? '<br>données : <code>' . h($dataDir) . '</code> → <code>' . h($wantedData) . '</code>' : '') . '</p>';
    }
    $state = $plan . ($legacy ? '<p class="msg">Ancienne structure détectée (code et données dans <code>api/</code>). Ce script la migre vers <code>public/</code> + <code>backend/</code> : configuration, journaux et sessions sont conservés (déplacés vers <code>backend/</code>), l\'ancien code de <code>api/</code> est supprimé après succès, et tout est rétabli en cas d\'échec.</p>' : '')
        . '<table><tr><td>Backend</td><td>' . h($backend) . '</td></tr><tr><td>Dépôt</td><td>' . h($repo) . '</td></tr>'
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
$sameVersion = false;
$applied = false;
$backupStarted = false;
$lock = null;
$health = 'unreachable';
$notes = array();
$generated = array();                   // fichiers générés ou complétés (chemin absolu => sauvegarde ou null)
$moved = array();                       // migration : éléments déplacés [origine, destination]
$createdBackend = false;
$oldState = $tmp . '/oldstate';         // migration : état de l'ancienne structure, rétabli en cas d'échec
try {
    if (!make_dir($tmp) || !guard_dir($tmp)) throw new RuntimeException('Dossier temporaire inaccessible.');
    // Ancienne structure : le verrou reste dans api/var (partagé avec l'ancien /auth) jusqu'à la migration
    if ($layoutError !== null) throw new RuntimeException($layoutError);
    $lockDir = $legacy ? $oldApi . '/var' : $varDir;
    foreach ($legacy ? array($lockDir) : array($backend . '/config', $dataDir, $dataDir . '/logs', $varDir) as $dir) {
        if (!guard_dir($dir)) throw new RuntimeException('Dossier inaccessible : ' . $dir);
    }
    $lock = @fopen($lockDir . '/update.lock', 'c');
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
        $sameVersion = true;
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
    // Moteur épinglé depuis la zone de test de /auth (core-pin.json) : la mise à jour n'y touche pas, et l'archive n'a pas à le contenir.
    $corePinned = is_file($varDir . '/core-pin.json');
    $required = array();
    foreach ($REQUIRED_FILES as $f) { if (!($corePinned && strpos($f, 'core/') === 0)) $required[] = $f; }
    $missing = array_diff($required, $files);
    if ($missing) throw new RuntimeException('Version incomplète ou trop ancienne (' . implode(', ', array_slice($missing, 0, 3)) . ').');
    lint_php($staging, $files);
    if ($corePinned) {
        $kept = array();
        foreach ($files as $f) { if (strpos($f, 'core/') !== 0) $kept[] = $f; }
        $files = $kept;
    }
    @unlink($zipFile);

    // 3 bis) Migration de l'ancienne structure : la configuration et les données sont DÉPLACÉES (renommées, donc conservées)
    if ($legacy) {
        $createdBackend = !is_dir($backend);
        foreach (array($backend, $backend . '/config', $dataDir) as $dir) {
            if (!make_dir($dir)) throw new RuntimeException('Dossier inaccessible : ' . $dir);
        }
        make_dir($oldState);
        foreach (array('version.json', 'manifest.json') as $f) {
            if (is_file($oldApi . '/var/' . $f)) @copy($oldApi . '/var/' . $f, $oldState . '/' . $f);
        }
        // La sauvegarde de l'ancien format ne sert plus (chemins différents) : mise de côté, rétablie si la migration échoue
        if (is_dir($oldApi . '/var/backup/prev') && !@rename($oldApi . '/var/backup/prev', $oldState . '/prev')) throw new RuntimeException('Migration impossible : sauvegarde précédente.');
        $moves = array(
            array($oldApi . '/.runtime.env', $backend . '/config/runtime.env'),
            array($oldApi . '/.env', $backend . '/config/local.env'),
            array($oldApi . '/logs', $dataDir . '/logs'),
            array($oldApi . '/var', $dataDir . '/var'),
        );
        foreach ($moves as $m) {
            if (!file_exists($m[0])) continue;
            if (file_exists($m[1])) throw new RuntimeException('Migration impossible : ' . $m[1] . ' existe déjà.');
            if (!@rename($m[0], $m[1])) throw new RuntimeException('Migration impossible : déplacement de ' . basename($m[0]) . '.');
            $moved[] = array($m[0], $m[1], 'rename');
        }
        foreach (array($backend . '/config', $dataDir, $dataDir . '/logs', $varDir) as $dir) {
            if (!guard_dir($dir)) throw new RuntimeException('Dossier inaccessible : ' . $dir);
        }
        $notes[] = 'Ancienne structure migrée : configuration, journaux et sessions déplacés vers ' . $backend . '.';
    }

    // 3 ter) Redistribution des dossiers selon BACKEND_DIR / DATA_DIR : déplacés (renommés, ou copiés puis vérifiés), jamais supprimés
    if (!$legacy && ($wantedBackend !== $backend || $wantedData !== $dataDir)) {
        $inside = function ($p, $dir) { return $p === $dir || strpos($p . '/', $dir . '/') === 0; };
        $freeTarget = function ($p) {
            if (file_exists($p) && !(is_dir($p) && array_diff(scandir($p), array('.', '..')) === array())) throw new RuntimeException('Le nouvel emplacement existe déjà et n\'est pas vide : ' . $p);
        };
        $dataAfter = $dataDir;
        if ($wantedBackend !== $backend) {
            if ($inside($wantedBackend, $backend) || $inside($backend, $wantedBackend)) throw new RuntimeException('Le nouvel emplacement du backend ne peut pas être dans l\'ancien (ni l\'inverse).');
            $freeTarget($wantedBackend);
            move_path($backend, $wantedBackend, $moved);
            if ($inside($dataDir, $backend)) $dataAfter = $wantedBackend . substr($dataDir, strlen($backend));
            $backend = $wantedBackend;
            $configFile = $backend . '/config/runtime.env';
            $notes[] = 'Backend déplacé vers ' . $backend . '.';
        }
        if ($wantedData !== $dataAfter && is_dir($dataAfter)) {
            if ($inside($wantedData, $dataAfter) || $inside($dataAfter, $wantedData)) throw new RuntimeException('Le nouvel emplacement des données ne peut pas être dans l\'ancien (ni l\'inverse).');
            $freeTarget($wantedData);
            move_path($dataAfter, $wantedData, $moved);
            $notes[] = 'Données déplacées vers ' . $wantedData . '.';
        }
        $dataDir = $wantedData;
        $varDir = $dataDir . '/var';
        $backupDir = $sameVersion ? $tmp . '/prev' : $varDir . '/backup/prev';
        foreach (array($backend . '/config', $dataDir, $dataDir . '/logs', $varDir) as $dir) {
            if (!guard_dir($dir)) throw new RuntimeException('Dossier inaccessible : ' . $dir);
        }
    }

    // 4) Sauvegarde (même format que la mise à jour de /auth : le retour arrière manuel y reste possible)
    $oldManifest = array();
    $mf = read_json($varDir . '/manifest.json');
    if ($mf && isset($mf['files']) && is_array($mf['files'])) {
        foreach ($mf['files'] as $f) { if (is_string($f) && allowed($f) && !($corePinned && strpos($f, 'core/') === 0)) $oldManifest[] = $f; }
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
        return (strpos($rel, 'backend/src/lib/') === 0 || strpos($rel, 'core/') === 0) ? 0
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
    $pointed = null;
    if (is_file($pointer)) {
        if (function_exists('opcache_invalidate')) @opcache_invalidate($pointer, true);
        $pointed = include $pointer;
    }
    if ($pointed !== $backend) {
        $save($pointer);
        file_put_contents($pointer, "<?php\n// Généré par update.php : emplacement du backend.\nreturn " . var_export($backend, true) . ";\n");
        if (function_exists('opcache_invalidate')) @opcache_invalidate($pointer, true);
        $notes[] = $pointed === null ? 'api/backend.php recréé.' : 'api/backend.php mis à jour (nouvel emplacement du backend).';
    }
    // Dossier de données en service (mémorisé pour détecter un changement de DATA_DIR à la prochaine mise à jour)
    $record = $backend . '/config/.data-dir';
    if (!is_file($record) || trim((string) file_get_contents($record)) !== $dataDir) {
        $save($record);
        file_put_contents($record, $dataDir . "\n");
    }
    // runtime.env au format lisible (sections et commentaires) : réécrit une fois, valeurs conservées, ancienne version gardée en .bak
    $envNow = (string) @file_get_contents($configFile);
    if ($envNow !== '' && strpos($envNow, '# Format du fichier : 2') === false) {
        $vars = parse_env_file($configFile);
        if (is_array($vars)) {
            $names = array();
            foreach (explode("\n", $RUNTIME_LAYOUT) as $row) {
                if ($row !== '' && $row[0] !== '|' && strpos($row, '== ') !== 0) $names[] = explode('|', $row, 2)[0];
            }
            $RUNTIME_LAYOUT_BASE = $RUNTIME_LAYOUT;
            $extra = array_diff(array_keys($vars), $names);
            if ($extra) {
                $RUNTIME_LAYOUT .= "\n== Autres réglages (conservés tels quels)\n";
                foreach ($extra as $n) $RUNTIME_LAYOUT .= $n . "|Réglage non documenté, conservé.\n";
                $RUNTIME_LAYOUT = rtrim($RUNTIME_LAYOUT, "\n");
            }
            try {
                $pretty = render_runtime_env($vars, 'update.php');
                $save($configFile . '.bak');
                file_put_contents($configFile . '.bak', $envNow);
                @chmod($configFile . '.bak', 0600);
                $save($configFile);
                if (file_put_contents($configFile, $pretty) === false) throw new RuntimeException('Écriture impossible : config/runtime.env');
                @chmod($configFile, 0600);
                $notes[] = 'config/runtime.env remis en forme (ancienne version : config/runtime.env.bak).';
            } catch (RuntimeException $e) {
                $notes[] = 'config/runtime.env laissé tel quel : ' . $e->getMessage();
            }
            $RUNTIME_LAYOUT = $RUNTIME_LAYOUT_BASE;
        }
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
        $head = htaccess_head($existingHt, $site, $backendUrl);
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
    foreach ($moved as $m) {
        if ($m[2] === 'copy') rrmdir($m[0]); // déplacement entre volumes : la source n'est supprimée qu'après le succès de tout
    }
    if ($legacy) {
        // Ancien code d'api/ devenu inutile (la façade api/index.php n'en utilise plus rien)
        rrmdir($oldApi . '/lib');
        rrmdir($oldApi . '/assets');
        @unlink($oldApi . '/calculation.php');
    }
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
    if ($moved) {
        // Déplacements annulés, dans l'ordre inverse (une copie entre volumes est simplement effacée : la source est intacte)
        $reverted = true;
        foreach (array_reverse($moved) as $m) {
            if ($m[2] === 'copy') rrmdir($m[1]);
            elseif (!@rename($m[1], $m[0])) $reverted = false;
        }
        if ($reverted) {
            $backend = $origBackend;
            $dataDir = $legacy ? $oldApi : $origData;
            if ($legacy) {
                foreach (array('version.json', 'manifest.json') as $f) {
                    if (is_file($oldState . '/' . $f)) @copy($oldState . '/' . $f, $oldApi . '/var/' . $f);
                }
                if (is_dir($oldState . '/prev') && is_dir($oldApi . '/var')) @rename($oldState . '/prev', $oldApi . '/var/backup/prev');
                if ($createdBackend) rrmdir($backend);
            }
        } else {
            $fatal .= ' ATTENTION : des dossiers n\'ont pas pu être remis à leur place d\'origine, vérifiez ' . $origBackend . ' et ' . $backend . '.';
        }
    }
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
