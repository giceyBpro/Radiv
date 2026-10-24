<?php
// ===== INTERFACE D'API MINIMALE DU MOTEUR =====
// Traduit une requête HTTP (méthode, route, corps) en réponse (code, tableau). Sans état, sans accès au serveur web :
// l'hébergeur branche son propre routage (voir index.php pour un exemple autonome) et ajoute ce qu'il veut autour
// (authentification, journalisation, limitation de débit, en-têtes CORS...).
//
//   GET  config     isotopes, nombres de cures autorisés, isotope par défaut
//   POST calculate  corps JSON = entrées du calcul (voir calculate.php) → durées de restriction par scénario
declare(strict_types=1);

namespace RivEngine\Api;

require_once __DIR__ . '/../src/bootstrap.php';

use RivEngine as Engine;

const MAX_BODY_BYTES = 65536;

// Retourne [code HTTP, données à encoder en JSON].
function handle(string $method, string $route, string $body = ''): array
{
    $route = trim($route, '/');
    if ($route === 'config') {
        if ($method !== 'GET') return [405, ['ok' => false, 'error' => 'Méthode non autorisée.']];
        return [200, [
            'isotopes' => Engine\isotopes(),
            'default_isotope_code' => 'iode131_25_fixation',
            'cure_options_by_isotope' => Engine\cure_options_by_isotope(),
        ]];
    }
    if ($route === 'calculate') {
        if ($method !== 'POST') return [405, ['ok' => false, 'error' => 'Méthode non autorisée.']];
        if (strlen($body) > MAX_BODY_BYTES) return [413, ['ok' => false, 'error' => 'Corps trop volumineux.']];
        $payload = $body === '' ? [] : json_decode($body, true);
        if ($body !== '' && !is_array($payload) && json_last_error() !== JSON_ERROR_NONE) {
            return [400, ['ok' => false, 'error' => 'JSON invalide.']];
        }
        // Un JSON scalaire (5, "x") n'a pas de champs : entrées vides.
        $result = Engine\calculate(is_array($payload) ? $payload : []);
        return [$result['ok'] ? 200 : 400, $result];
    }
    return [404, ['ok' => false, 'error' => 'Route inconnue.']];
}

// Encodage aligné sur JSON.stringify (NaN/Infini → null, pas d'échappement des caractères non ASCII ni des « / »).
function encode(mixed $data): string
{
    $normalize = static function ($v) use (&$normalize) {
        if (is_float($v)) return is_finite($v) ? $v : null;
        if (is_array($v)) foreach ($v as $k => $x) $v[$k] = $normalize($x);
        return $v;
    };
    $json = json_encode($normalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    return $json === false ? 'null' : $json;
}
