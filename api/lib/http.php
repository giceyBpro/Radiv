<?php
// Briques HTTP communes: IP client, en-têtes, réponses JSON/HTML, corps de requête, client cURL.
declare(strict_types=1);

namespace Radiv\Http;

use Radiv\Config;
use Radiv\Json;

function normalize_ip(string $ip): string
{
    if ($ip === '') return '';
    if (str_starts_with($ip, '::ffff:')) return substr($ip, 7);
    if ($ip === '::1') return '127.0.0.1';
    return $ip;
}

function is_private_or_local_ip(string $ip): bool
{
    return $ip === '127.0.0.1'
        || $ip === '0.0.0.0'
        || str_starts_with($ip, '10.')
        || str_starts_with($ip, '192.168.')
        || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip) === 1
        || str_starts_with($ip, 'fc')
        || str_starts_with($ip, 'fd')
        || $ip === '::1';
}

function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // X-Forwarded-For n'est cru que si la connexion vient réellement d'un proxy déclaré:
    // sinon n'importe qui peut usurper son IP (contournement du rate limit, logs empoisonnés).
    $trusted = Config\trusted_proxies();
    if (!$trusted || !in_array(normalize_ip($remote), $trusted, true)) return $remote;
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') return trim(explode(',', $forwarded)[0]);
    return $remote;
}

function set_cors_headers(): void
{
    header('Access-Control-Allow-Origin: ' . Config\cors_origin());
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function send_json(int $status, mixed $data): void
{
    $body = Json\encode($data);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Réponses de données uniquement: rien ne doit y être chargé ni exécuté.
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Content-Length: ' . strlen($body));
    echo $body;
}

function wants_html(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

// L'API est appelée par des scripts (fetch/XHR: send_json) mais aussi parfois ouverte
// directement dans un navigateur (lien copié, faute de frappe): une page lisible vaut mieux
// que le JSON brut.
function send_html_error(int $status, string $label, string $message): void
{
    $body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>{$status} - API Dosimétrie RIV</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#eef1f3;color:#111;display:flex;min-height:100vh;align-items:center;justify-content:center}
main{max-width:520px;margin:24px;background:#fff;padding:28px;border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);text-align:center}
h1{margin:0 0 8px;font-size:2rem;color:#c00000}
p{margin:8px 0}
.links{margin-top:20px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap}
a{color:#1637b8}
</style>
</head>
<body>
<main>
<h1>{$status}</h1>
<p><strong>{$label}</strong></p>
<p>{$message}</p>
<div class="links">
<a href="/api-fonctionnement.html">Documentation de l'API</a>
<a href="/">Retour à l'accueil</a>
</div>
</main>
</body>
</html>
HTML;
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    header('Content-Length: ' . strlen($body));
    echo $body;
}

// Corps brut, limité comme côté Node (64 Ko). null = trop gros.
function read_body(int $maxBytes = 65536): ?string
{
    $body = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    return strlen($body) > $maxBytes ? null : $body;
}

// Termine la réponse côté client puis laisse le script continuer (journalisation, géoIP):
// ces étapes peuvent prendre plusieurs secondes et ne doivent pas retarder l'appelant — en
// Node, la réponse attendait la résolution géoIP. Exige que send_json ait posé Content-Length.
function finish_response(): void
{
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

// Requête HTTP(S) via cURL. Retourne ['status','body','headers' (liste "Nom: valeur" de la
// réponse finale),'final_url','redirected'] ou null en cas d'échec réseau/timeout.
function fetch(string $url, array $options = []): ?array
{
    $ch = curl_init($url);
    if ($ch === false) return null;
    $headers = [];
    $currentHeaders = [];
    $redirects = 0;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT_MS => (int) ($options['connect_timeout_ms'] ?? 5000),
        CURLOPT_TIMEOUT_MS => (int) ($options['timeout_ms'] ?? 10000),
        CURLOPT_HTTPHEADER => $options['headers'] ?? [],
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$currentHeaders, &$redirects): int {
            if (str_starts_with($line, 'HTTP/')) { // nouvelle réponse (redirection ou finale)
                if ($currentHeaders) $redirects++;
                $currentHeaders = [];
            } elseif (trim($line) !== '') {
                $currentHeaders[] = rtrim($line, "\r\n");
            }
            return strlen($line);
        },
    ]);
    if (($options['method'] ?? 'GET') === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) ($options['body'] ?? ''));
    }
    $body = curl_exec($ch);
    if ($body === false) {
        curl_close($ch);
        return null;
    }
    $result = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body' => (string) $body,
        'headers' => $currentHeaders,
        'final_url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'redirected' => $redirects > 0,
    ];
    curl_close($ch);
    return $result;
}
