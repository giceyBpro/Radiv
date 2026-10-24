<?php
// ===== PHYSIQUE : DÉCROISSANCE, GÉOMÉTRIE, DURÉE DE RESTRICTION =====
// Modèle de Carlier et al. (voir data/SOURCES.md). Aucune validation ici : voir calculate.php.

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// 3) formules de calcul: décroissance, géométrie et calcul de durée de restriction
// fdiv() plutôt que "/": en JS une division par zéro donne Infinity/NaN (traités ensuite par
// les contrôles de finitude), alors que "/" lève DivisionByZeroError en PHP 8.
function decay_fraction(float $hours, float $effectiveDays): float
{
    return 1 - exp(-(fdiv(M_LN2, $effectiveDays) * ($hours / 24)));
}

function geometry_factor(float $distance, float $patientSizeCm): float
{
    return fdiv(atan(fdiv($patientSizeCm, 2 * $distance * 100)), $patientSizeCm * $distance / 200);
}

function common_factor(float $effectiveDays): float
{
    return fdiv(fdiv($effectiveDays * 24, M_LN2), 1 - exp(-fdiv(M_LN2, $effectiveDays)));
}

function exposure_contribution(array $exposure, float $effectiveDays, float $patientSizeCm): float
{
    $geom = ($exposure['unit_factor_at_1m'] && $exposure['distance'] == 1)
        ? 1.0
        : geometry_factor($exposure['distance'], $patientSizeCm);
    return decay_fraction($exposure['hours'], $effectiveDays) * $geom;
}

function restriction_days(float $effectiveDays, float $doseRate, float $patientSizeCm, array $exposures, float $limit): ?float
{
    $sum = 0.0;
    foreach ($exposures as $exposure) {
        $sum += exposure_contribution($exposure, $effectiveDays, $patientSizeCm);
    }
    $denominator = $sum * common_factor($effectiveDays);
    if (!($denominator > 0)) return null;
    $ratio = fdiv($limit * 1000, $denominator);
    $day = -fdiv($effectiveDays, M_LN2) * log(fdiv($ratio, $doseRate));
    if (!is_finite($day)) return null;
    return max(0.0, round_restriction_days($day));
}

function compute_dose_rate(array $selected, ?float $benignActivityMbq, ?float $benignFixationPct, mixed $doseRate): ?float
{
    if ($selected['api_code'] !== 'iode131_benin') return to_number_or_null($doseRate);
    if ($benignActivityMbq === null || $benignFixationPct === null) return null;
    return 2.2 * $benignActivityMbq * ($benignFixationPct / 100) / 37;
}
