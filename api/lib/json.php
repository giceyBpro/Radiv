<?php
// Encodage JSON aligné sur JSON.stringify de Node, pour que les réponses de l'API PHP soient
// identiques octet pour octet à celles de l'API Node (contrat inchangé pour les appelants).
declare(strict_types=1);

namespace Radiv\Json;

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
