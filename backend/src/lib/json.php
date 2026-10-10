<?php
// Encodage JSON aligné sur JSON.stringify, pour que les réponses de l'API PHP soient
// stables pour les appelants.
declare(strict_types=1);

namespace Radiv\Json;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

// JSON.stringify remplace NaN/±Infinity par null; json_encode() échoue. On normalise avant.
function normalize(mixed $value): mixed
{
    if (is_float($value)) return is_finite($value) ? $value : null;
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = normalize($v);
    }
    return $value;
}

// Sans échappement des caractères non ASCII ni des "/": c'est ce que produit JSON.stringify.
// Les listes vides restent [] et les tableaux associatifs vides {}: à construire avec
// (object)[] côté appelant quand un objet vide est attendu.
function encode(mixed $data): string
{
    $json = json_encode(normalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    return $json === false ? 'null' : $json;
}
