<?php
// Mise à jour du site depuis GitHub (dépôt privé), déclenchée depuis la page d'administration.
//
// Principe: télécharger l'archive ZIP d'un commit précis, tout valider dans un dossier de
// préparation (aucune écriture sur le site tant que ce n'est pas bon), sauvegarder la version
// en place, remplacer fichier par fichier (renommage atomique), contrôler que l'API répond,
// et revenir en arrière automatiquement sinon. Rien d'autre que la liste blanche ci-dessous
// n'est jamais écrit; la configuration (api/.env, .runtime.env) et les données (logs/, var/) ne
// sont jamais touchées.
declare(strict_types=1);

namespace Radiv\Updater;

use Radiv\Config;
use Radiv\Contact;
use Radiv\Http;
use Radiv\Json;
use Radiv\Measurements;

const MAX_ZIP_BYTES = 20971520;        // 20 Mo: l'archive réelle fait moins de 1 Mo
const MAX_FILE_BYTES = 10485760;       // 10 Mo par fichier extrait
const MAX_TOTAL_BYTES = 52428800;      // 50 Mo au total (protection contre les "bombes" ZIP)

// Même liste blanche que deploy-php.sh. api/ et downloads/ sont traités par préfixe (voir allowed()).
const FRONTEND_FILES = [
    'index.html', 'v1.html', 'app.js', 'print.html', 'explain.html', 'contact.html',
    'mentions-legales.html', 'api-fonctionnement.html', 'test-api.html', 'tox.html',
    '.tox-complet.html', 'xplore.html', 'favicon.ico', 'robots.txt',
];

// Sans ces fichiers dans la version cible, le site ne saurait plus se mettre à jour lui-même
// (cible trop ancienne, archive tronquée): refusé avant toute écriture.
const REQUIRED_FILES = [
    'index.html', 'api/.htaccess', 'api/index.php', 'api/calculation.php', 'api/lib/config.php',
    'api/lib/updater.php', 'api/lib/admin_site.php', 'api/lib/google.php', 'api/lib/session.php',
];

function repo(): string
{
    $repo = Config\env('UPDATE_GITHUB_REPO', 'giceyBpro/Radiv');
    return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : 'giceyBpro/Radiv';
}

// UPDATE_GITHUB_API_URL n'existe que pour les essais (faux GitHub local).
function api_base(): string
{
    return rtrim(Config\env('UPDATE_GITHUB_API_URL', 'https://api.github.com'), '/');
}

function gh_headers(string $token, string $accept = 'application/vnd.github+json'): array
{
    return [
        "Authorization: Bearer {$token}",
        "Accept: {$accept}",
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: radiv-site-updater',
    ];
}

// Seuls ces chemins (relatifs à la racine web) peuvent être écrits, remplacés ou supprimés.
function allowed(string $rel): bool
{
    if ($rel === '' || $rel[0] === '/' || str_contains($rel, "\0") || str_contains($rel, '\\')) return false;
    $parts = explode('/', $rel);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') return false;
    }
    if (in_array($rel, FRONTEND_FILES, true)) return true;
    if ($parts[0] === 'downloads') {
        return count($parts) === 2 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $parts[1]) === 1;
    }
    if ($parts[0] === 'api' && count($parts) >= 2) {
        if (in_array($parts[1], ['tests', 'logs', 'var'], true)) return false;
        if ($rel === 'api/.env' || $rel === 'api/.runtime.env') return false;
        foreach (array_slice($parts, 1) as $part) {
            if ($part[0] === '.' && $rel !== 'api/.htaccess') return false;
            if (preg_match('/^[A-Za-z0-9._-]+$/', $part) !== 1) return false;
        }
        return true;
    }
    return false;
}

// --- État persistant (dans var/, jamais écrasé par une mise à jour) -------------------------

function state_dir(): string { return Config\var_dir(); }

function read_json_file(string $file): ?array
{
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function write_json_file(string $file, array $data): void
{
    Config\ensure_private_dir(dirname($file));
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function current_version(): ?array { return read_json_file(state_dir() . '/version.json'); }
function manifest(): array { return read_json_file(state_dir() . '/manifest.json')['files'] ?? []; }
function backup_dir(): string { return state_dir() . '/backup/prev'; }
function backup_info(): ?array { return read_json_file(backup_dir() . '/backup.json'); }

function journal(array $entry): void
{
    $dir = Config\logs_dir();
    if (!Config\ensure_private_dir($dir)) return;
    file_put_contents($dir . '/updates.jsonl', Json\encode(['at' => Measurements\now_iso()] + $entry) . "\n", FILE_APPEND | LOCK_EX);
}

function recent_journal(int $limit = 8): array
{
    $file = Config\logs_dir() . '/updates.jsonl';
    $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $rows = [];
    foreach (array_slice(array_reverse($lines), 0, $limit) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = "{$dir}/{$name}";
        is_dir($path) && !is_link($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

// --- GitHub ---------------------------------------------------------------------------------

function github_get(string $path, string $token, string $accept = 'application/vnd.github+json'): ?array
{
    return Http\fetch(api_base() . $path, ['headers' => gh_headers($token, $accept), 'timeout_ms' => 15000]);
}

function token_valid(string $token): bool
{
    if ($token === '' || !preg_match('/^[A-Za-z0-9_\-.]{20,255}$/', $token)) return false;
    $response = github_get('/repos/' . repo(), $token);
    return $response !== null && $response['status'] === 200;
}

function recent_tags(string $token, int $limit = 10): array
{
    $response = github_get('/repos/' . repo() . '/tags?per_page=' . $limit, $token);
    $data = $response && $response['status'] === 200 ? json_decode($response['body'], true) : null;
    return is_array($data) ? array_values(array_filter(array_map(static fn ($t) => $t['name'] ?? null, $data), 'is_string')) : [];
}

function resolve_sha(string $ref, string $token): ?string
{
    $encoded = str_replace('%2F', '/', rawurlencode($ref));
    $response = github_get('/repos/' . repo() . '/commits/' . $encoded, $token, 'application/vnd.github.sha');
    if (!$response || $response['status'] !== 200) return null;
    $sha = strtolower(trim($response['body']));
    return preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
}

// --- Archive --------------------------------------------------------------------------------

// Extrait dans $staging les seuls fichiers de la liste blanche. Lève RuntimeException au moindre
// doute (chemin piégé, lien symbolique, taille excessive, racines multiples...).
function extract_archive(string $zipFile, string $staging, string $sha): array
{
    $zip = new \ZipArchive();
    if ($zip->open($zipFile) !== true) throw new \RuntimeException('Archive illisible.');
    if ($zip->numFiles > 2000) throw new \RuntimeException('Archive refusée (trop de fichiers).');
    $files = [];
    $root = null;
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $segments = explode('/', $name, 2);
        if ($root === null) $root = $segments[0];
        if ($segments[0] !== $root) throw new \RuntimeException('Archive invalide (plusieurs dossiers racines).');
        // Le dossier racine d'une archive GitHub embarque le SHA court du commit: contrôle croisé
        // avec le commit demandé (écarte une archive qui ne correspond pas à la référence).
        if (!str_contains($root, substr($sha, 0, 7))) throw new \RuntimeException('Archive ne correspondant pas au commit demandé.');
        if (str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
            throw new \RuntimeException('Archive refusée (chemin invalide).');
        }
        if (str_ends_with($name, '/') || !isset($segments[1])) continue;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
            throw new \RuntimeException('Archive refusée (lien symbolique).');
        }
        $rel = $segments[1];
        if (!allowed($rel)) continue; // hors liste blanche: ignoré, jamais écrit
        $stat = $zip->statIndex($i);
        $size = (int) ($stat['size'] ?? -1);
        $total += $size;
        if ($size < 0 || $size > MAX_FILE_BYTES || $total > MAX_TOTAL_BYTES) throw new \RuntimeException('Archive refusée (taille).');
        $dest = $staging . '/' . $rel;
        Config\ensure_dir(dirname($dest));
        $in = $zip->getStream($name);
        $out = @fopen($dest, 'wb');
        if (!$in || !$out) throw new \RuntimeException('Extraction impossible.');
        $written = stream_copy_to_stream($in, $out, MAX_FILE_BYTES + 1);
        fclose($in);
        fclose($out);
        if ($written !== $size) throw new \RuntimeException('Archive corrompue.');
        $files[] = $rel;
    }
    $zip->close();
    sort($files);
    return $files;
}

// Contrôle syntaxique de chaque fichier PHP avec l'analyseur de la version PHP du serveur
// (aucun binaire php en ligne de commande requis sur un hébergement mutualisé).
function lint_php(string $staging, array $files): void
{
    foreach ($files as $rel) {
        if (!str_ends_with($rel, '.php')) continue;
        try {
            token_get_all((string) file_get_contents($staging . '/' . $rel), TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \RuntimeException("Erreur de syntaxe PHP dans {$rel} (ligne {$e->getLine()}).");
        }
    }
}

// --- Remplacement ---------------------------------------------------------------------------

function install_file(string $src, string $dest): void
{
    Config\ensure_dir(dirname($dest));
    $tmp = $dest . '.upd' . bin2hex(random_bytes(3));
    if (!@copy($src, $tmp)) throw new \RuntimeException('Écriture impossible: ' . basename($dest));
    @chmod($tmp, 0644);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        throw new \RuntimeException('Remplacement impossible: ' . basename($dest));
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
}

// Ordre d'installation: bibliothèques d'abord, point d'entrée en dernier, pour qu'une requête
// arrivant pendant le remplacement ne voie pas un index.php neuf avec d'anciennes bibliothèques.
function install_order(array $files): array
{
    $rank = static fn (string $rel): int => str_starts_with($rel, 'api/lib/') || $rel === 'api/calculation.php' ? 0
        : ($rel === 'api/index.php' ? 2 : (str_starts_with($rel, 'api/') ? 1 : 3));
    usort($files, static fn ($a, $b) => [$rank($a), $a] <=> [$rank($b), $b]);
    return $files;
}

// Sauvegarde (une seule génération) de tout ce que la mise à jour va remplacer ou supprimer.
function make_backup(array $newFiles, array $oldManifest, array $versionBefore, ?string $toSha): void
{
    $dir = backup_dir();
    rrmdir($dir);
    $root = Config\web_root();
    $existing = [];
    $added = [];
    foreach (array_unique(array_merge($newFiles, $oldManifest)) as $rel) {
        if (!allowed($rel)) continue;
        if (is_file("{$root}/{$rel}")) {
            Config\ensure_dir(dirname("{$dir}/files/{$rel}"));
            if (!copy("{$root}/{$rel}", "{$dir}/files/{$rel}")) throw new \RuntimeException('Sauvegarde impossible.');
            $existing[] = $rel;
        } elseif (in_array($rel, $newFiles, true)) {
            $added[] = $rel;
        }
    }
    write_json_file($dir . '/backup.json', [
        'at' => Measurements\now_iso(), 'from' => $versionBefore, 'to_sha' => $toSha,
        'existing' => $existing, 'added' => $added, 'manifest' => $oldManifest,
    ]);
}

// Restaure la sauvegarde (fichiers, version, manifeste) puis la supprime.
function restore_backup(): bool
{
    $info = backup_info();
    if (!$info) return false;
    $dir = backup_dir();
    $root = Config\web_root();
    foreach (install_order($info['existing'] ?? []) as $rel) {
        if (allowed($rel)) install_file("{$dir}/files/{$rel}", "{$root}/{$rel}");
    }
    foreach ($info['added'] ?? [] as $rel) {
        if (allowed($rel)) { @unlink("{$root}/{$rel}"); if (function_exists('opcache_invalidate')) @opcache_invalidate("{$root}/{$rel}", true); }
    }
    write_json_file(state_dir() . '/manifest.json', ['files' => $info['manifest'] ?? []]);
    if (!empty($info['from'])) write_json_file(state_dir() . '/version.json', $info['from']);
    else @unlink(state_dir() . '/version.json');
    rrmdir($dir);
    return true;
}

// 'ok' | 'bad' (le site répond mais pas correctement) | 'unreachable' (appel impossible d'ici)
function health_check(): string
{
    $url = Config\api_public_url() . '/config';
    $verdict = 'unreachable';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) sleep(1);
        $response = Http\fetch($url, ['timeout_ms' => 8000, 'connect_timeout_ms' => 4000]);
        if (!$response) continue;
        $data = json_decode($response['body'], true);
        if ($response['status'] === 200 && is_array($data) && isset($data['isotopes'])) return 'ok';
        $verdict = 'bad';
    }
    return $verdict;
}

function notify(string $by, string $subject, string $detail): void
{
    $html = '<p>' . Contact\escape_html($detail) . '</p><p>Par : ' . Contact\escape_html($by) . '</p>';
    Contact\smtp_send($by, $subject, $html); // échec silencieux: l'alerte ne doit pas bloquer l'opération
}

function acquire_lock()
{
    Config\ensure_private_dir(state_dir());
    $handle = @fopen(state_dir() . '/update.lock', 'c');
    return ($handle && flock($handle, LOCK_EX | LOCK_NB)) ? $handle : null;
}

// --- Opérations -----------------------------------------------------------------------------

function run(string $ref, string $token, string $by): array
{
    @set_time_limit(180);
    ignore_user_abort(true); // une page fermée en cours de route ne doit pas laisser un état à moitié remplacé
    if (!class_exists('ZipArchive')) return ['ok' => false, 'message' => "L'extension PHP zip n'est pas disponible sur cet hébergement."];
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $ref) || str_contains($ref, '..')) {
        return ['ok' => false, 'message' => 'Référence invalide.'];
    }
    $lock = acquire_lock();
    if (!$lock) return ['ok' => false, 'message' => 'Une opération est déjà en cours.'];
    $tmp = state_dir() . '/tmp/update-' . bin2hex(random_bytes(4));
    $before = current_version();
    $applied = false;
    try {
        $sha = resolve_sha($ref, $token);
        if ($sha === null) throw new \RuntimeException('Référence introuvable ou accès refusé.');
        if (($before['sha'] ?? null) === $sha) {
            return ['ok' => true, 'message' => 'Cette version est déjà installée (' . substr($sha, 0, 7) . ').'];
        }
        Config\ensure_dir($tmp);
        $zipFile = "{$tmp}/repo.zip";
        // L'API GitHub répond par une redirection vers une URL de téléchargement signée. Elle est suivie
        // à la main pour que le jeton ne soit JAMAIS envoyé au second appel (on ne s'en remet pas au
        // comportement de cURL, qui varie selon les versions et selon que l'hôte change ou non).
        $redirect = Http\fetch(api_base() . '/repos/' . repo() . '/zipball/' . $sha, ['headers' => gh_headers($token), 'follow' => false, 'timeout_ms' => 15000]);
        $target = $redirect['location'] ?? null;
        if (!$redirect || $redirect['status'] !== 302 || !is_string($target) || !preg_match('#^https?://#i', $target)) {
            throw new \RuntimeException('Téléchargement impossible.');
        }
        $download = Http\download($target, $zipFile, ['User-Agent: radiv-site-updater'], MAX_ZIP_BYTES);
        if (!$download || $download['status'] !== 200) throw new \RuntimeException('Téléchargement impossible.');
        if (@file_get_contents($zipFile, false, null, 0, 2) !== 'PK') throw new \RuntimeException('Fichier téléchargé invalide.');

        $staging = "{$tmp}/new";
        $files = extract_archive($zipFile, $staging, $sha);
        $missing = array_diff(REQUIRED_FILES, $files);
        if ($missing) throw new \RuntimeException('Version cible incomplète ou trop ancienne (' . implode(', ', array_slice($missing, 0, 3)) . ').');
        lint_php($staging, $files);
        if (in_array('robots.txt', $files, true)) merge_robots("{$staging}/robots.txt", Config\web_root() . '/robots.txt');
        unlink($zipFile); // plus utile: libère l'espace avant le remplacement

        // À partir d'ici, le site est modifié: toute erreur déclenche un retour arrière.
        $oldManifest = manifest();
        make_backup($files, $oldManifest, $before ?? [], $sha);
        $root = Config\web_root();
        $applied = true;
        foreach (install_order($files) as $rel) install_file("{$staging}/{$rel}", "{$root}/{$rel}");
        foreach (array_diff($oldManifest, $files) as $obsolete) {
            if (allowed($obsolete)) { @unlink("{$root}/{$obsolete}"); if (function_exists('opcache_invalidate')) @opcache_invalidate("{$root}/{$obsolete}", true); }
        }
        write_json_file(state_dir() . '/manifest.json', ['files' => $files]);
        write_json_file(state_dir() . '/version.json', ['sha' => $sha, 'ref' => $ref, 'at' => Measurements\now_iso(), 'by' => $by]);

        $health = health_check();
        if ($health === 'bad') {
            restore_backup();
            throw new \RuntimeException("La nouvelle version ne répond pas correctement : l'ancienne a été rétablie automatiquement.");
        }
        $note = $health === 'unreachable' ? ' (contrôle de fonctionnement impossible depuis le serveur : vérifiez le site)' : '';
        $message = 'Mise à jour installée : ' . substr($sha, 0, 7) . " ({$ref}).{$note}";
        journal(['action' => 'update', 'by' => $by, 'ref' => $ref, 'from' => $before['sha'] ?? null, 'to' => $sha, 'ok' => true, 'health' => $health]);
        notify($by, '[Radioprotection RIV] Mise à jour du site', $message);
        return ['ok' => true, 'message' => $message];
    } catch (\Throwable $e) {
        if ($applied && backup_info()) {
            try { restore_backup(); } catch (\Throwable $inner) { error_log('[UPDATER] retour arrière échoué: ' . $inner->getMessage()); }
        }
        $message = $e instanceof \RuntimeException ? $e->getMessage() : 'Erreur interne pendant la mise à jour.';
        if (!$e instanceof \RuntimeException) error_log('[UPDATER] ' . get_class($e) . ': ' . $e->getMessage());
        journal(['action' => 'update', 'by' => $by, 'ref' => $ref, 'from' => $before['sha'] ?? null, 'ok' => false, 'error' => $message]);
        return ['ok' => false, 'message' => $message];
    } finally {
        rrmdir($tmp); // l'archive et les fichiers de préparation ne survivent jamais à l'opération
        @rmdir(state_dir() . '/tmp');
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function rollback(string $by): array
{
    $lock = acquire_lock();
    if (!$lock) return ['ok' => false, 'message' => 'Une opération est déjà en cours.'];
    try {
        $info = backup_info();
        if (!$info) return ['ok' => false, 'message' => 'Aucune version précédente à rétablir.'];
        $from = current_version();
        restore_backup();
        $health = health_check();
        $message = 'Version précédente rétablie' . (isset($info['from']['sha']) ? ' (' . substr((string) $info['from']['sha'], 0, 7) . ')' : '') . '.';
        journal(['action' => 'rollback', 'by' => $by, 'from' => $from['sha'] ?? null, 'to' => $info['from']['sha'] ?? null, 'ok' => true, 'health' => $health]);
        notify($by, '[Radioprotection RIV] Retour à la version précédente', $message);
        return ['ok' => true, 'message' => $message . ($health === 'bad' ? ' Attention : le site ne répond pas correctement.' : '')];
    } catch (\Throwable $e) {
        error_log('[UPDATER] rollback: ' . $e->getMessage());
        journal(['action' => 'rollback', 'by' => $by, 'ok' => false]);
        return ['ok' => false, 'message' => 'Retour arrière impossible.'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// robots.txt est remplacé par la version du dépôt, mais la ligne "Sitemap:" ajoutée au déploiement
// (URL absolue propre au domaine) est conservée.
function merge_robots(string $staged, string $existing): void
{
    $old = is_file($existing) ? (string) file_get_contents($existing) : '';
    $new = (string) file_get_contents($staged);
    if (preg_match_all('/^Sitemap:.*$/mi', $old, $m) && !preg_match('/^Sitemap:/mi', $new)) {
        file_put_contents($staged, rtrim($new) . "\n\n" . implode("\n", $m[0]) . "\n");
    }
}

function status(): array
{
    return [
        'version' => current_version(),
        'backup' => backup_info(),
        'zip' => class_exists('ZipArchive'),
        'repo' => repo(),
        'update_enabled' => Config\admin_update_enabled(),
        'journal' => recent_journal(),
        'php' => PHP_VERSION,
    ];
}
