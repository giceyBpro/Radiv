<?php
// ===== ARRONDI DES DURÉES DE RESTRICTION =====
// Deux conventions possibles, au choix de l'hébergeur du moteur (voir set_rounding_mode) :
//   "round" (défaut du moteur) : arrondi au plus proche, légèrement plus protecteur (jour supérieur dès que la fraction dépasse 0,5) ;
//   "floor"          : troncature, reproduit les valeurs de l'outil de référence du groupe Radioprotection de la SFMN.

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// Le mode par défaut est défini par le moteur lui-même (data/settings.json, clé rounding_mode) : c'est un choix de
// convention scientifique, pas de l'hébergeur. L'hébergeur PEUT le surcharger avec set_rounding_mode().
// Réglage de surcharge : une chaîne ("round" / "floor"), ou une fonction renvoyant cette chaîne (lue à CHAQUE calcul,
// ce qui permet de la brancher sur une variable d'environnement ou un fichier de configuration modifiable à chaud).
function set_rounding_mode(string|callable|null $mode): void
{
    $GLOBALS['riv_engine_rounding'] = $mode;
}

function rounding_mode(): string
{
    $setting = $GLOBALS['riv_engine_rounding'] ?? null;
    // Une chaîne est une valeur, jamais un nom de fonction ("round" est aussi une fonction PHP).
    $raw = is_string($setting) ? $setting : (is_callable($setting) ? $setting() : '');
    $raw = is_string($raw) ? $raw : '';
    if (trim($raw) === '') $raw = (string) (load_data('settings')['rounding_mode'] ?? 'round'); // pas de surcharge : valeur du moteur
    $mode = strtolower(trim($raw));
    if ($mode !== 'round' && $mode !== 'floor') {
        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log("Mode d'arrondi invalide ({$mode}); repli sur \"round\"");
        }
        return 'round';
    }
    return $mode;
}

// Équivalent exact de Math.round (arrondi vers +∞ à la demi-valeur). round() de PHP arrondit
// "half away from zero" et, avant PHP 8.4, applique un pré-arrondi qui fait diverger certains
// cas limites (ex. 2.4999999999999996 → 3 en PHP, 2 en JS). La différence x - floor(x) est
// exacte en virgule flottante, donc la comparaison à 0,5 l'est aussi.
function js_round(float $x): float
{
    if (!is_finite($x)) return $x;
    $floor = floor($x);
    return ($x - $floor >= 0.5) ? $floor + 1.0 : $floor;
}

function round_restriction_days(float $day): float
{
    return rounding_mode() === 'floor' ? floor($day) : js_round($day);
}
