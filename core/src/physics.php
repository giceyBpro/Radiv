<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== PHYSIQUE : DÉCROISSANCE, GÉOMÉTRIE, DURÉE DE RESTRICTION =====
// Modèle de Carlier et al. (références : data/SOURCES.md). Ce fichier ne contient que des formules : aucune lecture de
// données, aucune validation (voir calculate.php, qui vérifie les entrées avant d'appeler ces fonctions).
//
// Idée générale. Le patient émet un débit de dose D0 (µSv/h, mesuré à 1 m à sa sortie) qui décroît avec la période
// effective T (jours). Une personne de l'entourage reçoit, par jour de contact, une dose proportionnelle à
//     (fraction de l'activité qui décroît pendant le contact) × (facteur géométrique de la distance).
// La durée de restriction est le nombre de jours à attendre avant que le cumul de dose, sur toute la période de
// contact restante, passe sous la limite du scénario. Notations utilisées ci-dessous :
//     λ = ln 2 / T          constante de décroissance (par jour)

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// fdiv() est utilisé à la place de « / » partout où le diviseur peut être nul ou négatif : en JavaScript (dont le moteur
// reproduit les résultats) une division par zéro donne Infinity ou NaN, repérés ensuite par les contrôles de finitude,
// alors que « / » lèverait DivisionByZeroError en PHP 8.

// Fraction de l'activité qui décroît pendant `$hours` heures de contact par jour :  1 − exp(−λ · heures/24).
function decay_fraction(float $hours, float $effectiveDays): float
{
    return 1 - exp(-(fdiv(M_LN2, $effectiveDays) * ($hours / 24)));
}

// Facteur géométrique de Siegel : correction du débit de dose mesuré à 1 m pour une distance `$distance` (m), en tenant
// compte de la taille du patient (source étendue, assimilée à un segment) :
//     atan(taille / (2 · distance · 100))  /  (taille · distance / 200)       (taille en cm, distance en m)
function geometry_factor(float $distance, float $patientSizeCm): float
{
    return fdiv(atan(fdiv($patientSizeCm, 2 * $distance * 100)), $patientSizeCm * $distance / 200);
}

// Facteur commun à tous les scénarios (« facteur commun » du modèle publié, voir data/SOURCES.md), qui ramène le
// débit de dose du patient à une dose cumulée :  (T · 24 / ln 2) / (1 − exp(−λ))
function common_factor(float $effectiveDays): float
{
    return fdiv(fdiv($effectiveDays * 24, M_LN2), 1 - exp(-fdiv(M_LN2, $effectiveDays)));
}

// Contribution d'UNE période d'exposition (heures par jour, distance) au cumul de dose.
// `unit_factor_at_1m` (donnée du scénario) : à exactement 1 m, le facteur géométrique de référence vaut 1.
function exposure_contribution(array $exposure, float $effectiveDays, float $patientSizeCm): float
{
    $geometry = ($exposure['unit_factor_at_1m'] && $exposure['distance'] == 1)
        ? 1.0
        : geometry_factor($exposure['distance'], $patientSizeCm);
    return decay_fraction($exposure['hours'], $effectiveDays) * $geometry;
}

// Durée de restriction (en jours entiers, ≥ 0) pour un scénario :
//   1. somme des contributions de toutes les périodes d'exposition du scénario, multipliée par le facteur commun ;
//   2. rapport = limite de dose (µSv) / cette somme : le débit de dose à partir duquel la limite est respectée ;
//   3. jours = −(T / ln 2) · ln(rapport / D0) : temps de décroissance pour passer du débit mesuré D0 à ce seuil ;
//   4. arrondi selon le mode choisi (rounding.php), jamais négatif.
// Renvoie null si le résultat n'existe pas (dénominateur nul ou résultat non fini).
function restriction_days(float $effectiveDays, float $doseRate, float $patientSizeCm, array $exposures, float $limit): ?float
{
    $sum = 0.0;
    foreach ($exposures as $exposure) {
        $sum += exposure_contribution($exposure, $effectiveDays, $patientSizeCm);
    }
    $denominator = $sum * common_factor($effectiveDays);
    if (!($denominator > 0)) return null;

    $threshold = fdiv($limit * 1000, $denominator);           // limite en µSv ÷ dose cumulée par µSv/h de débit
    $days = -fdiv($effectiveDays, M_LN2) * log(fdiv($threshold, $doseRate));
    if (!is_finite($days)) return null;

    return max(0.0, round_restriction_days($days));
}
