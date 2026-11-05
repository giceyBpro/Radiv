<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== INTERFACE D'API MINIMALE DU MOTEUR =====
// Traduit une requête HTTP (méthode, route, corps) en réponse (code, tableau). Sans état, sans accès au serveur web :
// l'hébergeur branche son propre routage (voir index.php pour un exemple autonome) et ajoute ce qu'il veut autour
// (authentification, journalisation, limitation de débit, en-têtes CORS...).
//
//   GET  config     isotopes, nombres de cures autorisés, champs de saisie et saisie attendue par isotope, scénarios d'exposition
//   POST calculate  corps JSON = entrées du calcul (voir src/calculate.php) → durées de restriction par scénario
//
// Utilisation :
//     require 'api/handler.php';
//     [$statut, $donnees] = RivEngine\Api\handle('POST', 'calculate', $corpsJson);
//     http_response_code($statut);
//     echo RivEngine\Api\encode($donnees);

declare(strict_types=1);

namespace RivEngine\Api;

require_once __DIR__ . '/../src/bootstrap.php';

use RivEngine as Engine;

const MAX_BODY_BYTES = 65536;   // un calcul tient en quelques centaines d'octets : au-delà, la requête est refusée (413)

// Point d'entrée : [code HTTP, données à encoder en JSON].
function handle(string $method, string $route, string $body = ''): array
{
    switch (trim($route, '/')) {
        case 'config':    return $method === 'GET'  ? config_response()           : method_not_allowed();
        case 'calculate': return $method === 'POST' ? calculate_response($body)   : method_not_allowed();
        default:          return [404, ['ok' => false, 'error' => 'Route inconnue.']];
    }
}

function method_not_allowed(): array
{
    return [405, ['ok' => false, 'error' => 'Méthode non autorisée.']];
}

// GET config : tout ce dont une interface a besoin pour construire sa saisie. Rien n'est écrit en dur côté interface :
// un isotope ou un scénario ajouté dans data/ apparaît ici, donc dans les interfaces, sans autre modification.
function config_response(): array
{
    $scenarios = array_map(
        static fn (array $s): array => ['audience_code' => $s['audience_code'], 'label' => $s['label'], 'condition' => $s['condition']],
        Engine\scenarios()
    );
    return [200, [
        'isotopes' => Engine\isotopes(),
        'default_isotope_code' => 'iode131_25_fixation',
        'cure_options_by_isotope' => Engine\cure_options_by_isotope(),
        'fields' => Engine\input_fields(),             // description des champs de saisie (libellé, unité, type, bornes, aide)
        'isotope_inputs' => Engine\isotope_inputs(),   // champ → required / optional / ignored, pour chaque isotope
        'scenarios' => $scenarios,                      // code, libellé et condition : de quoi documenter l'API sans liste à maintenir
    ]];
}

// POST calculate : décode le corps JSON, calcule, et traduit le résultat en code HTTP (200 si ok, 400 sinon).
function calculate_response(string $body): array
{
    if (strlen($body) > MAX_BODY_BYTES) return [413, ['ok' => false, 'error' => 'Corps trop volumineux.']];

    $payload = $body === '' ? [] : json_decode($body, true);
    if ($body !== '' && json_last_error() !== JSON_ERROR_NONE) return [400, ['ok' => false, 'error' => 'JSON invalide.']];

    // Un JSON scalaire (5, "x") n'a pas de champs : c'est une saisie vide, que le calcul signalera comme telle.
    $result = Engine\calculate(is_array($payload) ? $payload : []);
    return [$result['ok'] ? 200 : 400, $result];
}

// Encodage JSON aligné sur JSON.stringify de JavaScript : NaN et Infini deviennent null ; ni les caractères non ASCII
// ni les « / » ne sont échappés.
function encode(mixed $data): string
{
    $finite = static function ($value) use (&$finite) {
        if (is_float($value)) return is_finite($value) ? $value : null;
        if (is_array($value)) return array_map($finite, $value);
        return $value;
    };
    $json = json_encode($finite($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    return $json === false ? 'null' : $json;
}
