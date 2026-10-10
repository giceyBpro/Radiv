<?php
// Formulaire de contact: vérification reCAPTCHA et envoi par SMTP authentifié. Port de
// Envoi du formulaire de contact: variables (SMTP_*, CONTACT_DEST,
// RECAPTCHA_SECRET_KEY), mêmes garde-fous (STARTTLS obligatoire, certificat vérifié, mode
// fermé sans clé reCAPTCHA), sans dépendance externe.
declare(strict_types=1);

namespace Radiv\Contact;

use Radiv\Config;
use Radiv\Http;
use Radiv\Measurements;

// String(value || '').trim() côté JS: les valeurs "fausses" donnent '' ; trim couvre aussi
// les espaces Unicode et le BOM.
function clean_field(mixed $value): string
{
    if ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0) return '';
    $text = is_string($value) ? $value : (is_scalar($value) ? Measurements\js_str($value) : '');
    return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $text) ?? $text;
}

function escape_html(string $value): string
{
    return strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
}

function valid_email_shape(string $email): bool
{
    // \z et non $: en PCRE, $ accepterait aussi un saut de ligne final.
    return preg_match('/^[^\s@\r\n]{1,64}@[^\s@\r\n]{1,255}\z/u', $email) === 1;
}

// Mode fermé: une clé absente ou mal orthographiée doit bloquer l'envoi, sinon le formulaire
// de contact devient un relais d'envoi ouvert.
function verify_recaptcha(string $token, string $ip): bool
{
    $secret = Config\env('RECAPTCHA_SECRET_KEY');
    if ($secret === '') {
        error_log('[CONTACT] RECAPTCHA_SECRET_KEY absente: envoi refusé.');
        return false;
    }
    $params = ['secret' => $secret, 'response' => $token];
    if ($ip !== '') $params['remoteip'] = $ip;
    $response = Http\fetch('https://www.google.com/recaptcha/api/siteverify', [
        'method' => 'POST',
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query($params, '', '&', PHP_QUERY_RFC1738),
        'timeout_ms' => 10000,
    ]);
    if (!$response || $response['status'] < 200 || $response['status'] > 299) return false;
    $data = json_decode($response['body'], true);
    return is_array($data) && ($data['success'] ?? null) === true;
}

// Lit une réponse SMTP complète (lignes "250-..." puis "250 ..."). Lève une exception sur
// délai dépassé ou connexion fermée.
function read_response($socket): array
{
    $lines = [];
    while (true) {
        $line = fgets($socket, 2048);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            throw new \RuntimeException(!empty($meta['timed_out']) ? 'Délai SMTP dépassé' : 'Connexion SMTP fermée');
        }
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        $lines[] = $line;
        if (preg_match('/^\d{3} /', $line) || preg_match('/^\d{3}$/', $line)) return $lines;
    }
}

function command($socket, string $cmd, string $expectedPrefix = '2'): array
{
    fwrite($socket, $cmd . "\r\n");
    $lines = read_response($socket);
    if (substr(end($lines), 0, 1) !== $expectedPrefix) {
        // Ne jamais journaliser la commande elle-même: AUTH LOGIN y fait passer identifiants/mot de passe.
        throw new \RuntimeException('SMTP commande échouée: ' . implode(' | ', $lines));
    }
    return $lines;
}

function smtp_send(string $replyTo, string $subject, string $html): array
{
    $host = Config\env('SMTP_HOST');
    $port = Config\int_env('SMTP_PORT', 587);
    $secure = strtolower(Config\env('SMTP_SECURE', 'false')) === 'true';
    $user = Config\env('SMTP_USER');
    $pass = Config\env('SMTP_PASS');
    $from = Config\env('SMTP_FROM');
    $dest = Config\env('CONTACT_DEST');
    if ($host === '' || !$port || $user === '' || $pass === '' || $from === '' || $dest === '') {
        return ['ok' => false, 'error' => 'Configuration SMTP incomplète (SMTP_* / CONTACT_DEST).'];
    }
    $envelopeFrom = trim(preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $user);
    $timeout = max(1, (int) ceil(Config\int_env('SMTP_TIMEOUT_MS', 15000) / 1000));
    $messageId = sprintf('<%d.%s@radioprotection-riv.local>', (int) (microtime(true) * 1000), bin2hex(random_bytes(6)));

    // Certificat et nom d'hôte vérifiés : sans cela, un intermédiaire
    // pourrait présenter un faux certificat et lire AUTH LOGIN.
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true,
    ]]);
    $socket = null;
    try {
        $socket = @stream_socket_client(($secure ? 'ssl' : 'tcp') . "://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) throw new \RuntimeException("Connexion SMTP impossible ({$errno}) {$errstr}");
        stream_set_timeout($socket, $timeout); // appliqué à chaque lecture: serveur muet = exception

        $greet = read_response($socket);
        if (substr(end($greet), 0, 1) !== '2') throw new \RuntimeException('SMTP greeting invalide: ' . implode(' | ', $greet));
        $ehlo = command($socket, 'EHLO radioprotection-riv.local');

        if (!$secure) {
            // TLS obligatoire: sans cette vérification, un attaquant en position d'intermédiaire
            // supprime l'annonce STARTTLS de la réponse EHLO et le mot de passe part en clair.
            if (!str_contains(implode("\n", $ehlo), 'STARTTLS')) {
                throw new \RuntimeException('Le serveur SMTP n’annonce pas STARTTLS: envoi refusé (SMTP_SECURE=true requis pour une connexion TLS directe).');
            }
            command($socket, 'STARTTLS');
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new \RuntimeException('Échec de la négociation TLS (STARTTLS)');
            }
            command($socket, 'EHLO radioprotection-riv.local');
        }

        command($socket, 'AUTH LOGIN', '3');
        command($socket, base64_encode($user), '3');
        command($socket, base64_encode($pass), '2');
        command($socket, "MAIL FROM:<{$envelopeFrom}>");
        command($socket, "RCPT TO:<{$dest}>");
        command($socket, 'DATA', '3');

        // Corps normalisé en CRLF puis "dot-stuffing" (une ligne commençant par "." est doublée),
        // faute de quoi une ligne "." isolée terminerait le message prématurément.
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $html);
        $body = preg_replace('/^\./m', '..', $body);
        $message = implode("\r\n", [
            "From: {$from}",
            "To: <{$dest}>",
            "Reply-To: {$replyTo}",
            "Subject: [Radioprotection RIV] {$subject}",
            'Date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
            "Message-ID: {$messageId}",
            "Return-Path: <{$envelopeFrom}>",
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $body,
            '.',
            '',
        ]);
        fwrite($socket, $message);
        $data = read_response($socket);
        if (substr(end($data), 0, 1) !== '2') throw new \RuntimeException('SMTP DATA échoué: ' . implode(' | ', $data));
        command($socket, 'QUIT');
        return ['ok' => true];
    } catch (\Throwable $e) {
        error_log('[SMTP] Erreur: ' . $e->getMessage());
        return ['ok' => false, 'error' => "Erreur lors de l'envoi."];
    } finally {
        if (is_resource($socket)) @fclose($socket);
    }
}

function send_contact_email(string $email, string $message, string $ip, string $timestamp): array
{
    $safeEmail = escape_html($email);
    $safeMessage = str_replace("\n", '<br>', escape_html($message));
    $safeIp = escape_html($ip);
    $html = <<<HTML

  <div style="font-family:Arial,Helvetica,sans-serif;background:#eef1f3;padding:20px;">
    <div style="max-width:700px;margin:0 auto;background:#ffffff;border-radius:10px;padding:20px;border:1px solid #d5dde1;">
      <h2 style="margin-top:0;color:#111;">Nouveau message - Dosimétrie RIV</h2>
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="padding:8px;border:1px solid #e3e7ea;"><strong>Date</strong></td><td style="padding:8px;border:1px solid #e3e7ea;">{$timestamp}</td></tr>
        <tr><td style="padding:8px;border:1px solid #e3e7ea;"><strong>IP</strong></td><td style="padding:8px;border:1px solid #e3e7ea;">{$safeIp}</td></tr>
        <tr><td style="padding:8px;border:1px solid #e3e7ea;"><strong>Email</strong></td><td style="padding:8px;border:1px solid #e3e7ea;">{$safeEmail}</td></tr>
      </table>
      <div style="margin-top:14px;padding:12px;background:#f4f7f8;border:1px solid #d5dde1;border-radius:6px;">{$safeMessage}</div>
    </div>
  </div>
HTML;
    return smtp_send(preg_replace('/[\r\n]/', '', $email) ?? $email, 'Contact formulaire web', $html);
}
