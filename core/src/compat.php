<?php
// ===== COMPATIBILITÉ AVEC JAVASCRIPT =====
// Le moteur reproduit à l'identique l'ancienne implémentation JavaScript (mêmes conversions, mêmes affichages de
// nombres) pour qu'aucun client existant ne voie de différence.

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// Équivalent de Number(value) de JS suivi d'un contrôle isFinite, pour les valeurs que peut
// produire un JSON: '' / null / absent → null; booléens → 0/1; chaînes → syntaxe numérique
// JS (espaces tolérés, "  " = 0, hexa/octal/binaire préfixés, "Infinity" rejeté car non
// fini); tableaux/objets → null (cas pathologique sans usage réel, non reproduit).
function to_number_or_null(mixed $value): ?float
{
    if ($value === '' || $value === null) return null;
    if (is_bool($value)) return $value ? 1.0 : 0.0;
    if (is_int($value)) return (float) $value;
    if (is_float($value)) return is_finite($value) ? $value : null;
    if (!is_string($value)) return null;
    $s = trim($value, " \t\n\r\v\f\u{00A0}\u{FEFF}\u{2028}\u{2029}");
    if ($s === '') return 0.0;
    if (preg_match('/^0[xX][0-9a-fA-F]+$/', $s)) return (float) hexdec(substr($s, 2));
    if (preg_match('/^0[oO][0-7]+$/', $s)) return (float) octdec(substr($s, 2));
    if (preg_match('/^0[bB][01]+$/', $s)) return (float) bindec(substr($s, 2));
    if (!preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $s)) return null;
    $number = (float) $s;
    return is_finite($number) ? $number : null;
}

// Équivalent de la conversion implicite d'un nombre en chaîne dans un gabarit JS (`${x}`).
// Le formatage natif de PHP diffère (précision 14, exposants "1.0E-5"): on repart des
// chiffres significatifs les plus courts (ceux de json_encode, identiques à ceux de JS) et on
// applique l'algorithme Number::toString de la spec ECMAScript.
function js_number_to_string(float $x): string
{
    if (is_nan($x)) return 'NaN';
    if ($x === 0.0) return '0';
    if (is_infinite($x)) return $x > 0 ? 'Infinity' : '-Infinity';
    if ($x < 0) return '-' . js_number_to_string(-$x);
    $repr = (string) json_encode($x); // plus courte représentation qui se relit à l'identique
    $mantissa = $repr;
    $exp10 = 0;
    if (($ePos = stripos($repr, 'e')) !== false) {
        $mantissa = substr($repr, 0, $ePos);
        $exp10 = (int) substr($repr, $ePos + 1);
    }
    $dot = strpos($mantissa, '.');
    $intPart = $dot === false ? $mantissa : substr($mantissa, 0, $dot);
    $fracPart = $dot === false ? '' : substr($mantissa, $dot + 1);
    $digits = $intPart . $fracPart;
    $n = strlen($intPart) + $exp10; // position de la virgule par rapport au début des chiffres
    $stripped = ltrim($digits, '0');
    $n -= strlen($digits) - strlen($stripped);
    $digits = rtrim($stripped, '0');
    $k = strlen($digits);
    if ($k <= $n && $n <= 21) return $digits . str_repeat('0', $n - $k);
    if (0 < $n && $n <= 21) return substr($digits, 0, $n) . '.' . substr($digits, $n);
    if (-6 < $n && $n <= 0) return '0.' . str_repeat('0', -$n) . $digits;
    $e = $n - 1;
    $sign = $e < 0 ? '-' : '+';
    $expStr = $sign . abs($e);
    return $k === 1 ? $digits . 'e' . $expStr : $digits[0] . '.' . substr($digits, 1) . 'e' . $expStr;
}

// Équivalent de JSON.stringify(valeur ?? null) pour le message d'erreur isotope_code.
function js_json_stringify(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
