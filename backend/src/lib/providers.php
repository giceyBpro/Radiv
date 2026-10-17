<?php
// Fournisseurs de l'API (logiciels et services qui appellent /api/calculate) et leurs clés d'API.
//
// Registre : config/providers.json (privé, droits 600, jamais écrasé par une mise à jour). Les clés ne sont JAMAIS stockées : seule leur
// empreinte SHA-256 l'est (une clé aléatoire de 256 bits n'a pas besoin d'un hachage lent). Une clé s'affiche une seule fois, à sa création.
// Format d'une clé : rdv_<8 hexa>_<43 caractères base64url> ; le préfixe public (8 hexa) retrouve l'entrée sans parcourir les empreintes.
// Appel : en-tête « X-API-Key: <clé> » (ou « Authorization: Bearer <clé> »), jamais dans l'adresse.
declare(strict_types=1);

namespace Radiv\Providers;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Store;

const KEY_PATTERN = '/^rdv_([0-9a-f]{8})_([A-Za-z0-9_-]{43})$/';
const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,31}$/';

function registry_file(): string
{
    return Config\config_dir() . '/providers.json';
}

function load_registry(): array
{
    $raw = @file_get_contents(registry_file());
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function load(): array
{
    $data = load_registry();
    $providers = isset($data['providers']) && is_array($data['providers']) ? $data['providers'] : [];
    return array_values(array_filter($providers, static fn ($p) => is_array($p) && isset($p['id']) && is_string($p['id'])));
}

// Clé d'API obligatoire pour les appels extérieurs ? Le choix enregistré dans l'administration (registre) l'emporte sur
// API_KEY_REQUIRED ; sans choix enregistré, c'est la variable d'environnement (faux par défaut).
function required(): bool
{
    $data = load_registry();
    return isset($data['required']) && is_bool($data['required']) ? $data['required'] : Config\api_key_required();
}

// Vrai si l'obligation vient d'un choix fait dans l'administration (et non de l'environnement).
function required_is_set(): bool
{
    $data = load_registry();
    return isset($data['required']) && is_bool($data['required']);
}

function set_required(bool $required): ?string
{
    $r = transact(static function (array $providers) use ($required): array {
        return [$providers, null, ['required' => $required]];
    });
    return is_string($r) ? $r : null;
}

// Lecture-modification-écriture exclusive du registre. $change reçoit la liste et retourne [nouvelle liste, résultat].
function transact(callable $change): mixed
{
    if (!Config\ensure_private_dir(Config\config_dir())) return 'Dossier de configuration inaccessible.';
    $lock = @fopen(Config\config_dir() . '/.providers.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return 'Registre verrouillé, réessayez.';
    try {
        $changed = $change(load());
        $providers = $changed[0];
        $result = $changed[1];
        if ($providers !== null) {
            $tmp = registry_file() . '.tmp' . bin2hex(random_bytes(3));
            // Les réglages du registre (ex. « required ») sont conservés, ceux fournis par $change (3e élément) les remplacent
            $registry = ['providers' => array_values($providers)] + array_diff_key(load_registry(), ['providers' => 1]);
            $registry = ($changed[2] ?? []) + $registry;
            $json = json_encode($registry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (@file_put_contents($tmp, $json . "\n") === false) return 'Écriture du registre impossible.';
            @chmod($tmp, 0600);
            if (!@rename($tmp, registry_file())) { @unlink($tmp); return 'Écriture du registre impossible.'; }
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function find_index(array $providers, string $id): ?int
{
    foreach ($providers as $i => $p) {
        if ($p['id'] === $id) return $i;
    }
    return null;
}

// Création d'un fournisseur. Retourne null si tout va bien, sinon le message d'erreur.
function create(string $id, string $name, ?int $rateLimit): ?string
{
    $id = strtolower(trim($id));
    $name = trim(mb_substr(trim(preg_replace('/[\x00-\x1f<>"]/u', '', $name) ?? ''), 0, 80));
    if (preg_match(ID_PATTERN, $id) !== 1) return 'Identifiant invalide : 2 à 32 caractères, minuscules, chiffres, « - » et « _ ».';
    if ($name === '') $name = $id;
    if ($rateLimit !== null && ($rateLimit < 1 || $rateLimit > 100000)) return 'Plafond invalide (1 à 100000 requêtes par minute).';
    $r = transact(static function (array $providers) use ($id, $name, $rateLimit): array {
        if (find_index($providers, $id) !== null) return [null, 'Ce fournisseur existe déjà.'];
        $providers[] = ['id' => $id, 'name' => $name, 'status' => 'active', 'rate_limit' => $rateLimit, 'created' => gmdate('Y-m-d\TH:i:s\Z'), 'keys' => []];
        return [$providers, null];
    });
    return is_string($r) ? $r : null;
}

// Nouvelle clé pour un fournisseur. Retourne ['key' => ..., 'prefix' => ...] (la clé n'est plus jamais récupérable) ou un message d'erreur.
function add_key(string $id, ?int $expiresInDays): array|string
{
    if ($expiresInDays !== null && ($expiresInDays < 1 || $expiresInDays > 3650)) return 'Durée de validité invalide (1 à 3650 jours).';
    $prefix = bin2hex(random_bytes(4));
    $key = 'rdv_' . $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $r = transact(static function (array $providers) use ($id, $prefix, $key, $expiresInDays): array {
        $i = find_index($providers, $id);
        if ($i === null) return [null, 'Fournisseur inconnu.'];
        $providers[$i]['keys'][] = [
            'prefix' => $prefix, 'hash' => hash('sha256', $key), 'created' => gmdate('Y-m-d\TH:i:s\Z'),
            'expires' => $expiresInDays === null ? null : gmdate('Y-m-d', time() + $expiresInDays * 86400), 'revoked' => false,
        ];
        return [$providers, null];
    });
    return is_string($r) ? $r : ['key' => $key, 'prefix' => $prefix];
}

function revoke_key(string $id, string $prefix): ?string
{
    $r = transact(static function (array $providers) use ($id, $prefix): array {
        $i = find_index($providers, $id);
        if ($i === null) return [null, 'Fournisseur inconnu.'];
        $found = false;
        foreach ($providers[$i]['keys'] as $k => $key) {
            if (($key['prefix'] ?? '') === $prefix) { $providers[$i]['keys'][$k]['revoked'] = true; $found = true; }
        }
        return $found ? [$providers, null] : [null, 'Clé inconnue.'];
    });
    return is_string($r) ? $r : null;
}

function set_status(string $id, string $status): ?string
{
    if (!in_array($status, ['active', 'suspended'], true)) return 'État invalide.';
    $r = transact(static function (array $providers) use ($id, $status): array {
        $i = find_index($providers, $id);
        if ($i === null) return [null, 'Fournisseur inconnu.'];
        $providers[$i]['status'] = $status;
        return [$providers, null];
    });
    return is_string($r) ? $r : null;
}

// Clé présentée par l'appelant : « X-API-Key », sinon « Authorization: Bearer ». Chaîne vide si absente.
function request_key(): string
{
    $key = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key === '') {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) $key = $m[1];
    }
    return mb_substr($key, 0, 200);
}

// Vérifie une clé. ['ok' => true, 'provider' => [...], 'prefix' => ...] ou ['ok' => false, 'reason' => ...] (la raison n'est que journalisée,
// l'appelant reçoit toujours le même message pour ne rien apprendre sur les clés existantes).
function authenticate(string $key): array
{
    if (preg_match(KEY_PATTERN, $key, $m) !== 1) return ['ok' => false, 'reason' => 'format'];
    $hash = hash('sha256', $key);
    foreach (load() as $provider) {
        foreach ($provider['keys'] ?? [] as $k) {
            if (($k['prefix'] ?? '') !== $m[1] || !hash_equals((string) ($k['hash'] ?? ''), $hash)) continue;
            if (!empty($k['revoked'])) return ['ok' => false, 'reason' => 'revoked', 'prefix' => $m[1]];
            if (!empty($k['expires']) && gmdate('Y-m-d') > $k['expires']) return ['ok' => false, 'reason' => 'expired', 'prefix' => $m[1]];
            if (($provider['status'] ?? 'active') !== 'active') return ['ok' => false, 'reason' => 'suspended', 'prefix' => $m[1]];
            return ['ok' => true, 'provider' => $provider, 'prefix' => $m[1]];
        }
    }
    return ['ok' => false, 'reason' => 'unknown'];
}

// Hôte sans « www. » : le site peut répondre avec ou sans.
function bare_host(string $url): string
{
    return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
}

// Requête venant des pages du site lui-même (navigateur sur le site) : en-tête Origin du navigateur, ou Sec-Fetch-Site same-origin.
// Un navigateur ne permet pas à une page tierce de falsifier ces en-têtes, mais un script (curl...) peut les écrire : cette exemption
// n'identifie donc pas de façon sûre, elle laisse seulement le site fonctionner sans clé (plafond par adresse IP inchangé).
function is_site_request(): bool
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $site = Config\site_public_url();
        return $site !== '' && bare_host($origin) === bare_host($site) && strtolower((string) parse_url($origin, PHP_URL_SCHEME)) === strtolower((string) parse_url($site, PHP_URL_SCHEME));
    }
    return strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'same-origin';
}

// Identifie l'appelant de /api/calculate.
// Retourne ['kind' => 'key'|'site'|'open', 'id' => ?string, 'name' => ?string, 'limit' => ?int] ou ['error' => message, 'status' => 401|429, 'code' => ...].
function identify(string $ip): array
{
    $key = request_key();
    if ($key !== '') {
        $auth = authenticate($key);
        if ($auth['ok']) {
            $p = $auth['provider'];
            $limit = isset($p['rate_limit']) && is_int($p['rate_limit']) ? $p['rate_limit'] : Config\int_env('PROVIDER_RATE_LIMIT', 300);
            return ['kind' => 'key', 'id' => $p['id'], 'name' => $p['name'] ?? $p['id'], 'limit' => $limit];
        }
        error_log('[API-KEY] Clé refusée (' . $auth['reason'] . ', préfixe ' . ($auth['prefix'] ?? '—') . ') depuis ' . $ip);
        if (!Store\rate_limit_hit('apikeyfail', $ip, 20)) {
            return ['error' => 'Trop de clés refusées. Réessayez dans une minute.', 'status' => 429, 'code' => 'API_KEY_THROTTLED'];
        }
        return ['error' => 'Clé d\'API invalide, expirée ou révoquée.', 'status' => 401, 'code' => 'API_KEY_INVALID'];
    }
    if (is_site_request()) return ['kind' => 'site', 'id' => Config\site_name(), 'name' => Config\site_name(), 'limit' => null];
    if (!required()) return ['kind' => 'open', 'id' => null, 'name' => null, 'limit' => null];
    return ['error' => 'Clé d\'API requise : transmettez-la dans l\'en-tête X-API-Key (voir /api-fonctionnement.html).', 'status' => 401, 'code' => 'API_KEY_REQUIRED'];
}
