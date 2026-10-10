<?php
// Connexion Google (OpenID Connect, flux "code" avec PKCE). Le site ne retient que l'adresse
// e-mail vérifiée de l'administrateur: aucun jeton Google n'est conservé, aucun accès hors
// ligne n'est demandé (scope "openid email" uniquement).
//
// La signature de l'id_token n'est pas revérifiée: le jeton est reçu directement de l'endpoint
// de Google en HTTPS, avec le secret du client (OpenID Connect Core §3.1.3.7, point 6, autorise
// ce cas). Sont en revanche contrôlés: émetteur, audience, expiration, nonce, e-mail vérifié
// et appartenance à la liste ADMIN_GOOGLE_EMAILS.
declare(strict_types=1);

namespace Radiv\Google;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Http;
use Radiv\Session;

// GOOGLE_AUTH_URL / GOOGLE_TOKEN_URL n'existent que pour les essais (faux Google local).
function auth_url(): string { return Config\env('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth'); }
function token_url(): string { return Config\env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'); }
function redirect_uri(): string { return Config\site_public_url() . '/auth/callback'; }

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function begin_login(bool $forceReauth = false): string
{
    Session\start();
    $verifier = b64url(random_bytes(32));
    $_SESSION['oauth'] = [
        'state' => bin2hex(random_bytes(16)),
        'nonce' => bin2hex(random_bytes(16)),
        'verifier' => $verifier,
        'at' => time(),
    ];
    return auth_url() . '?' . http_build_query([
        'client_id' => Config\env('GOOGLE_CLIENT_ID'),
        'redirect_uri' => redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => $_SESSION['oauth']['state'],
        'nonce' => $_SESSION['oauth']['nonce'],
        'code_challenge' => b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => $forceReauth ? 'login' : 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

// Retourne l'e-mail autorisé, ou null (toute cause d'échec donne la même réponse à l'appelant).
function complete_login(array $query): ?string
{
    Session\start();
    $oauth = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']); // usage unique
    if (!is_array($oauth) || time() - (int) $oauth['at'] > 600) return null;
    if (isset($query['error']) || !isset($query['code'], $query['state']) || !is_string($query['code']) || !is_string($query['state'])) return null;
    if (!hash_equals($oauth['state'], $query['state'])) return null;

    $response = Http\fetch(token_url(), [
        'method' => 'POST',
        'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        'body' => http_build_query([
            'code' => $query['code'],
            'client_id' => Config\env('GOOGLE_CLIENT_ID'),
            'client_secret' => Config\env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => redirect_uri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $oauth['verifier'],
        ], '', '&', PHP_QUERY_RFC1738),
        'timeout_ms' => 10000,
    ]);
    if (!$response || $response['status'] !== 200) return null;
    $data = json_decode($response['body'], true);
    $idToken = is_array($data) ? ($data['id_token'] ?? null) : null;
    if (!is_string($idToken) || substr_count($idToken, '.') !== 2) return null;
    $claims = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/')), true);
    if (!is_array($claims)) return null;

    if (!in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true) && Config\env('GOOGLE_TOKEN_URL') === '') return null;
    $aud = $claims['aud'] ?? null;
    if ($aud !== Config\env('GOOGLE_CLIENT_ID')) return null;
    if ((int) ($claims['exp'] ?? 0) < time()) return null;
    if (!hash_equals($oauth['nonce'], (string) ($claims['nonce'] ?? ''))) return null;
    if (($claims['email_verified'] ?? false) !== true) return null;
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    return in_array($email, Config\admin_google_emails(), true) ? $email : null;
}
