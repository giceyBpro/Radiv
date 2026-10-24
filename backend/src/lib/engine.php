<?php
// Passerelle entre le site et le moteur de calcul partagé (core/) : le moteur ne connaît ni les modes de calcul du site
// ni ses champs de réponse propres. Le site ajoute donc ici, à l'identique de ce que reçoivent ses appelants depuis
// toujours, l'indicateur « calculation_mode » (position comprise : juste après « ok ») et sa mention dans la liste
// des champs attendus d'une erreur. Idempotent : un moteur qui fournirait déjà ces éléments reste inchangé.
declare(strict_types=1);

namespace Radiv\Engine;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use RivEngine;

// Réponse du moteur mise à la forme de l'API du site.
function decorate(array $result): array
{
    if (!array_key_exists('calculation_mode', $result)) {
        $ordered = [];
        foreach ($result as $key => $value) {
            $ordered[$key] = $value;
            if ($key === 'ok') $ordered['calculation_mode'] = 'local';
        }
        $result = isset($ordered['calculation_mode']) ? $ordered : ['calculation_mode' => 'local'] + $result;
    }
    $common = $result['error']['expected_payload']['common'] ?? null;
    if (is_array($common) && !in_array('calculation_mode', $common, true)) {
        array_unshift($common, 'calculation_mode');
        $result['error']['expected_payload']['common'] = $common;
    }
    return $result;
}

function calculate(array $payload): array
{
    return decorate(RivEngine\calculate($payload));
}
