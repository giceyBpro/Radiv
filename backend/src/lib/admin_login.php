<?php
// Connexion alternative à /auth : identifiant + mot de passe + code à usage unique (TOTP, RFC 6238), à côté de la connexion Google.
// Les deux méthodes sont proposées si elles sont configurées ; l'administrateur choisit à la connexion. Aucun secret en clair :
// le mot de passe est conservé haché (password_hash), le secret TOTP est dans la configuration privée (ADMIN_TOTP_SECRET).
// Garde-fous : limite de tentatives (par adresse IP et globale), même message pour tout échec, comparaison à temps constant,
// code TOTP utilisable une seule fois (pas de rejeu), jamais d'indication sur la partie fausse.
declare(strict_types=1);

namespace Radiv\AdminLogin;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Http;
use Radiv\Store;

const PERIOD = 30;   // durée d'un code (secondes)
const DIGITS = 6;
const WINDOW = 1;    // tolérance d'horloge : un pas avant ou après

function user(): string { return trim(Config\env('ADMIN_LOGIN_USER')); }
function password_hash_value(): string { return trim(Config\env('ADMIN_LOGIN_PASSWORD_HASH')); }
function totp_secret(): string { return strtoupper(preg_replace('/[\s=-]+/', '', Config\env('ADMIN_TOTP_SECRET')) ?? ''); }

// Active seulement si les trois réglages sont présents et valides.
function enabled(): bool
{
    return user() !== '' && preg_match('/^\$(2y|argon2id?)\$/', password_hash_value()) === 1
        && base32_decode(totp_secret()) !== null && strlen(totp_secret()) >= 16;
}

function base32_decode(string $text): ?string
{
    if ($text === '' || preg_match('/^[A-Z2-7]+$/', $text) !== 1) return null;
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($text) as $c) $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function base32_encode(string $raw): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($raw) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    return $out;
}

// Code TOTP du pas `$counter` (HMAC-SHA1, troncature dynamique).
function code_at(string $secretBinary, int $counter): string
{
    $hash = hash_hmac('sha1', pack('J', $counter), $secretBinary, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** DIGITS)), DIGITS, '0', STR_PAD_LEFT);
}

// Pas du code accepté (ou null). Le pas déjà utilisé, ou plus ancien, est refusé : un code ne sert qu'une fois.
function totp_step(string $code, string $secret, int $now, int $lastUsed): ?int
{
    $binary = base32_decode($secret);
    if ($binary === null || preg_match('/^\d{6}$/', $code) !== 1) return null;
    $found = null;
    for ($i = -WINDOW; $i <= WINDOW; $i++) {
        $step = intdiv($now, PERIOD) + $i;
        if (hash_equals(code_at($binary, $step), $code) && $step > $lastUsed) $found = $step;
    }
    return $found;
}

function last_file(): string { return Config\var_dir() . '/admin-totp-last'; }

// Contrôle complet. Même résultat (false) quelle que soit la cause. Le mot de passe est toujours vérifié (temps constant).
function verify(string $login, string $password, string $code, ?int $now = null): bool
{
    if (!enabled()) return false;
    $now = $now ?? time();
    $userOk = hash_equals(strtolower(user()), strtolower(trim($login)));
    $dummy = '$2y$10$usesomesillystringforeuseomesillystringforeuseomesillystri'; // vérification factice : même durée
    $passOk = password_verify($password, $userOk ? password_hash_value() : $dummy);
    if (!Config\ensure_private_dir(dirname(last_file()))) return false;
    $handle = @fopen(last_file(), 'c+');
    if ($handle === false) return false;
    try {
        flock($handle, LOCK_EX);
        $last = (int) trim((string) stream_get_contents($handle));
        $step = totp_step($code, totp_secret(), $now, $last);
        if (!$userOk || !$passOk || $step === null) return false;
        ftruncate($handle, 0); rewind($handle); fwrite($handle, (string) $step); // le code ne servira plus
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// Limite de tentatives : 5 par minute et par adresse, 30 par quart d'heure au total.
function attempt_allowed(): bool
{
    return Store\rate_limit_hit('adminlogin', Http\client_ip(), 5) && Store\rate_limit_hit('adminlogin-all', 'all', 30, 900);
}

// Adresse « otpauth » à saisir ou à scanner dans l'application d'authentification.
function otpauth_uri(string $account, string $secret, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=' . DIGITS . '&period=' . PERIOD;
}
