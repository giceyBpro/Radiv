<?php
// Anti-robots du formulaire de contact : preuve de travail locale, compatible avec le widget ALTCHA (logiciel libre, licence MIT, éditeur
// européen), sans service tiers, sans cookie et sans transfert de données personnelles.
//
// Principe : le serveur remet au navigateur un défi (GET /api/captcha) ; le navigateur doit retrouver un nombre N tel que
// SHA-256(sel + N) = défi, ce qui demande un court calcul (une à deux secondes) qu'un humain ne remarque pas, mais qui coûte cher à un
// robot qui enverrait des milliers de messages. Le défi est signé (HMAC-SHA-256, clé propre au site) : le navigateur ne peut pas le
// forger. La solution est vérifiée à l'envoi du message, et ne vaut qu'UNE fois.
//
// Protocole (format ALTCHA) :
//   défi      {"algorithm":"SHA-256","challenge":"<sha256(sel+N) en hexadécimal>","salt":"<sel>?expires=<t>&issued=<t>","signature":"<hmac>","maxnumber":<max>}
//   solution  charge base64 de {"algorithm","challenge","number","salt","signature"} (envoyée dans le champ « captcha » du formulaire)
// Le sel contient la date d'émission et d'expiration : elles sont protégées par la signature (le défi dépend du sel).
//
// Garde-fous à la vérification : signature valide, solution exacte, défi non expiré, au moins MIN_SECONDS entre l'émission et l'envoi
// (un robot qui valide aussitôt est refusé), défi jamais utilisé auparavant (fichier créé en exclusivité dans var/captcha/).
declare(strict_types=1);

namespace Radiv\Captcha;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;

const ALGORITHM = 'SHA-256';
const MAX_NUMBER = 50000;       // le navigateur essaie de 0 à ce nombre : quelques secondes au plus, une seconde en moyenne
const TTL_SECONDS = 900;        // durée de validité d'un défi (15 minutes)
const MIN_SECONDS = 2;          // délai minimal entre l'émission du défi et son utilisation

function secret_file(): string { return Config\var_dir() . '/captcha-secret.key'; }
function used_dir(): string { return Config\var_dir() . '/captcha'; }

// Clé de signature des défis, propre au site : créée au premier usage (32 octets aléatoires, droits 0600) et conservée dans le dossier
// privé de données. Renvoie '' si elle ne peut être ni lue ni créée (le formulaire de contact reste alors fermé).
function secret(): string
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $file = secret_file();
    $raw = is_file($file) ? trim((string) @file_get_contents($file)) : '';
    if (preg_match('/^[0-9a-f]{64}$/', $raw) !== 1) {
        if (!Config\ensure_private_dir(dirname($file))) return $cache = '';
        $new = bin2hex(random_bytes(32));
        // Création exclusive : si deux requêtes se croisent, une seule clé est retenue et lue par les deux.
        $handle = @fopen($file, 'x');
        if ($handle !== false) {
            fwrite($handle, $new . "\n");
            fclose($handle);
            @chmod($file, 0600);
            $raw = $new;
        } else {
            $raw = trim((string) @file_get_contents($file));
        }
    }
    return $cache = (preg_match('/^[0-9a-f]{64}$/', $raw) === 1 ? $raw : '');
}

function sign(string $challenge, string $key): string { return hash_hmac('sha256', $challenge, $key); }

// Nouveau défi à remettre au navigateur. Null si la clé de signature est indisponible.
function challenge(?int $now = null): ?array
{
    $key = secret();
    if ($key === '') return null;
    $now = $now ?? time();
    purge_used($now);
    $salt = bin2hex(random_bytes(12)) . '?expires=' . ($now + TTL_SECONDS) . '&issued=' . $now;
    $number = random_int(1, MAX_NUMBER);
    $challenge = hash('sha256', $salt . $number);
    return [
        'algorithm' => ALGORITHM,
        'challenge' => $challenge,
        'maxnumber' => MAX_NUMBER,
        'salt' => $salt,
        'signature' => sign($challenge, $key),
    ];
}

// Dates « expires » et « issued » lues dans le sel (null si absentes ou illisibles).
function salt_times(string $salt): ?array
{
    $mark = strpos($salt, '?');
    if ($mark === false) return null;
    parse_str(substr($salt, $mark + 1), $params);
    $expires = $params['expires'] ?? null;
    $issued = $params['issued'] ?? null;
    if (!is_string($expires) || !ctype_digit($expires) || !is_string($issued) || !ctype_digit($issued)) return null;
    return ['expires' => (int) $expires, 'issued' => (int) $issued];
}

// Vérifie la solution reçue (charge base64 produite par le widget) et la marque comme utilisée. True seulement si tout est correct.
function verify(string $payload, ?int $now = null): bool
{
    $key = secret();
    if ($key === '' || $payload === '' || strlen($payload) > 2048) return false;
    $json = base64_decode($payload, true);
    $data = $json === false ? null : json_decode($json, true);
    if (!is_array($data)) return false;
    foreach (['algorithm', 'challenge', 'salt', 'signature'] as $field) {
        if (!isset($data[$field]) || !is_string($data[$field])) return false;
    }
    $number = $data['number'] ?? null;
    if ($data['algorithm'] !== ALGORITHM || !is_int($number) || $number < 0 || $number > MAX_NUMBER) return false;
    if (preg_match('/^[0-9a-f]{64}$/', $data['challenge']) !== 1) return false;

    // Le défi est bien celui du serveur (signature) et la solution est exacte.
    if (!hash_equals(sign($data['challenge'], $key), $data['signature'])) return false;
    if (!hash_equals($data['challenge'], hash('sha256', $data['salt'] . $number))) return false;

    // Délais : ni expiré, ni validé trop vite.
    $times = salt_times($data['salt']);
    $now = $now ?? time();
    if ($times === null || $now > $times['expires'] || $now - $times['issued'] < MIN_SECONDS) return false;

    // Usage unique : le fichier n'existe pas encore (création exclusive, donc sûre même avec deux requêtes simultanées).
    if (!Config\ensure_private_dir(used_dir())) return false;
    $mark = @fopen(used_dir() . '/' . substr($data['challenge'], 0, 32), 'x');
    if ($mark === false) return false;
    fclose($mark);
    return true;
}

// Supprime les marques d'usage de plus d'une validité : elles ne servent plus (le défi correspondant a expiré).
function purge_used(int $now): void
{
    if (!is_dir(used_dir())) return;
    foreach (@scandir(used_dir()) ?: [] as $name) {
        if (preg_match('/^[0-9a-f]{32}$/', $name) === 1 && $now - (int) @filemtime(used_dir() . '/' . $name) > 2 * TTL_SECONDS) @unlink(used_dir() . '/' . $name);
    }
}
