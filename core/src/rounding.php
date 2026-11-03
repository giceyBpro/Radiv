<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== ARRONDI DES DURÉES DE RESTRICTION =====
// La durée calculée est un nombre de jours décimal ; il faut la convertir en jours entiers. Deux conventions :
//   "round" (défaut du moteur) : arrondi au plus proche, légèrement plus protecteur (jour supérieur dès que la fraction ≥ 0,5) ;
//   "floor"                    : troncature, reproduit les valeurs de la Fig. 1 de l'article de référence (data/SOURCES.md).
// Le défaut est une convention scientifique : il est dans data/settings.json (clé rounding_mode). L'hébergeur du moteur
// PEUT le surcharger avec set_rounding_mode().

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// Surcharge du mode d'arrondi. `$mode` est soit une chaîne ("round" / "floor"), soit une fonction qui renvoie cette chaîne ;
// la fonction est relue à CHAQUE calcul, ce qui permet de la brancher sur une variable d'environnement ou un fichier de
// configuration modifiable à chaud. null supprime la surcharge (retour au défaut du moteur).
function set_rounding_mode(string|callable|null $mode): void
{
    $GLOBALS['riv_engine_rounding'] = $mode;
}

// Mode effectivement appliqué : surcharge de l'hébergeur, sinon défaut des données, sinon "round". Une valeur invalide
// est signalée une fois dans le journal d'erreurs puis remplacée par "round" (jamais d'arrêt du calcul).
function rounding_mode(): string
{
    $setting = $GLOBALS['riv_engine_rounding'] ?? null;
    // Une chaîne est une valeur, jamais un nom de fonction : "round" est aussi une fonction PHP.
    $raw = is_string($setting) ? $setting : (is_callable($setting) ? $setting() : '');
    if (!is_string($raw) || trim($raw) === '') {
        $raw = (string) (load_data('settings')['rounding_mode'] ?? 'round');
    }

    $mode = strtolower(trim($raw));
    if ($mode === 'round' || $mode === 'floor') return $mode;

    static $warned = false;
    if (!$warned) {
        $warned = true;
        error_log("Mode d'arrondi invalide ({$mode}); repli sur \"round\"");
    }
    return 'round';
}

// Équivalent exact de Math.round de JavaScript (arrondi vers +∞ à la demi-valeur). round() de PHP arrondit « half away
// from zero » et, avant PHP 8.4, applique un pré-arrondi qui fait diverger certains cas limites (2.4999999999999996 donne
// 3 en PHP, 2 en JavaScript). La différence x − floor(x) est exacte en virgule flottante : la comparaison à 0,5 l'est aussi.
function js_round(float $x): float
{
    if (!is_finite($x)) return $x;
    $floor = floor($x);
    return ($x - $floor >= 0.5) ? $floor + 1.0 : $floor;
}

// Conversion finale des jours décimaux en jours entiers, selon le mode en vigueur.
function round_restriction_days(float $day): float
{
    return rounding_mode() === 'floor' ? floor($day) : js_round($day);
}
