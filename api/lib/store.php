<?php
// État partagé entre requêtes. Node le gardait dans des Map en mémoire du process; PHP n'a pas
// de process persistant, donc: APCu si disponible (équivalent direct, en mémoire partagée),
// sinon fichiers JSON verrouillés (flock) dans var/.
//
// Attention APCu: sa mémoire est partagée entre les workers d'un même serveur (mod_php,
// PHP-FPM) mais pas forcément avec LiteSpeed/LSAPI selon la configuration. Le backend fichier
// n'a pas cette limite. RATE_LIMIT_BACKEND=auto (défaut) | apcu | file permet de trancher.
declare(strict_types=1);

namespace Radiv\Store;

use Radiv\Config;

const GEO_TTL_SECONDS = 86400;
const GEO_CACHE_MAX = 500;
const RATE_TABLE_PURGE_THRESHOLD = 5000;

function use_apcu(): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $wanted = strtolower(Config\env('RATE_LIMIT_BACKEND', 'auto'));
    $available = function_exists('apcu_add') && function_exists('apcu_inc')
        && (!function_exists('apcu_enabled') || apcu_enabled());
    return $cached = ($wanted !== 'file' && $available);
}

function backend_name(): string
{
    return use_apcu() ? 'apcu' : 'file';
}

// Fenêtre fixe d'une minute à partir de la première requête, comme checkRateLimit de Node:
// les $max premières requêtes passent, les suivantes sont refusées jusqu'à l'expiration.
function rate_limit_hit(string $bucket, string $key, int $max, int $windowSeconds = 60): bool
{
    if (use_apcu()) {
        $k = "radiv:rl:{$bucket}:{$key}";
        if (apcu_add($k, 1, $windowSeconds)) return $max >= 1;
        $count = apcu_inc($k);
        if ($count === false) { // entrée expirée entre add et inc
            apcu_add($k, 1, $windowSeconds);
            return $max >= 1;
        }
        return $count <= $max;
    }
    return with_json_file("ratelimit-{$bucket}.json", static function (array $table) use ($key, $max, $windowSeconds): array {
        $now = time();
        if (count($table) > RATE_TABLE_PURGE_THRESHOLD) {
            // Purge des fenêtres expirées: sans cela la table croît indéfiniment.
            $table = array_filter($table, static fn ($e) => $now <= ($e['reset_at'] ?? 0));
        }
        $entry = $table[$key] ?? null;
        if (!$entry || $now > $entry['reset_at']) {
            $table[$key] = ['count' => 1, 'reset_at' => $now + $windowSeconds];
            return [$table, $max >= 1];
        }
        if ($entry['count'] >= $max) return [$table, false];
        $table[$key]['count']++;
        return [$table, true];
    }, true);
}

function geo_get(string $ip): ?array
{
    if (use_apcu()) {
        $hit = apcu_fetch("radiv:geo:{$ip}", $ok);
        return $ok && is_array($hit) ? $hit : null;
    }
    $result = null;
    with_json_file('geoip-cache.json', static function (array $table) use ($ip, &$result): array {
        $entry = $table[$ip] ?? null;
        if ($entry && time() - ($entry['at'] ?? 0) < GEO_TTL_SECONDS) $result = $entry['geo'];
        return [$table, null];
    }, true);
    return $result;
}

function geo_set(string $ip, array $geo): void
{
    if (use_apcu()) {
        apcu_store("radiv:geo:{$ip}", $geo, GEO_TTL_SECONDS);
        return;
    }
    with_json_file('geoip-cache.json', static function (array $table) use ($ip, $geo): array {
        $table[$ip] = ['at' => time(), 'geo' => $geo];
        while (count($table) > GEO_CACHE_MAX) { // le plus ancien d'abord (ordre d'insertion)
            unset($table[array_key_first($table)]);
        }
        return [$table, null];
    }, null);
}

// Lecture-modification-écriture atomique d'un fichier JSON sous verrou exclusif.
// $fallback: valeur renvoyée si le fichier est inutilisable (volontairement permissif pour le
// rate-limit côté appelant: ne pas bloquer le service si var/ n'est pas inscriptible, c'est
// signalé dans les logs d'erreur).
function with_json_file(string $name, callable $fn, mixed $fallback): mixed
{
    $dir = Config\var_dir();
    if (!Config\ensure_private_dir($dir)) {
        error_log("[STORE] var/ inaccessible: {$dir}");
        return $fallback;
    }
    $handle = @fopen("{$dir}/{$name}", 'c+');
    if (!$handle) {
        error_log("[STORE] ouverture impossible: {$dir}/{$name}");
        return $fallback;
    }
    try {
        if (!flock($handle, LOCK_EX)) return $fallback;
        $raw = stream_get_contents($handle);
        $table = $raw !== '' && $raw !== false ? json_decode($raw, true) : [];
        if (!is_array($table)) $table = [];
        [$updated, $returned] = $fn($table);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($updated));
        fflush($handle);
        return $returned;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
