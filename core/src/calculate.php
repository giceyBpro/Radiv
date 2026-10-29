<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== VALIDATION DES ENTRÉES ET ASSEMBLAGE DU RÉSULTAT =====
// calculate($payload) est l'unique fonction à appeler : elle valide les champs, calcule une durée par scénario et
// renvoie un tableau prêt à être encodé en JSON (ok, rows, recommendations_days, errors...).

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

function normalize_cure_count(array $selected, mixed $rawValue): array
{
    $options = cure_options_by_isotope();
    $hasSpecificOptions = array_key_exists($selected['api_code'], $options);
    $allowed = $hasSpecificOptions ? $options[$selected['api_code']] : [1];
    if (!$hasSpecificOptions) return ['value' => 1, 'allowed' => $allowed, 'valid' => true];
    $parsed = to_number_or_null($rawValue);
    if ($parsed === null) return ['value' => 1, 'allowed' => $allowed, 'valid' => true];
    // Number.isInteger + includes: un entier exprimé en flottant (4.0) est accepté.
    if (floor($parsed) != $parsed || !in_array($parsed, $allowed, false)) {
        return ['value' => 1, 'allowed' => $allowed, 'valid' => false];
    }
    return ['value' => (int) $parsed, 'allowed' => $allowed, 'valid' => true];
}

function expected_payload_by_isotope(array $selected): array
{
    $common = ['isotope_code', 'patient_size_cm', 'user_period_days', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit', 'cure_count'];
    return profile_of($selected)['expected_payload']((string) $selected['api_code'], $common);
}

// $payload: tableau associatif issu de json_decode($body, true) (ou tableau vide).
function calculate(array $payload): array
{
    $isotopeCode = $payload['isotope_code'] ?? null;
    $selected = get_isotope($isotopeCode);
    // get_isotope() retombe silencieusement sur isotopes[0] si isotope_code ne correspond à
    // rien (faute de frappe, casse, valeur absente) — sans ce contrôle, le calcul se
    // poursuivrait normalement pour le mauvais isotope et renverrait ok:true avec un
    // résultat correct en apparence mais faux, sans aucun signal d'erreur.
    $validCodes = array_column(isotopes(), 'api_code');
    $isotopeCodeRecognized = is_string($isotopeCode) && in_array($isotopeCode, $validCodes, true);
    $cure = normalize_cure_count($selected, $payload['cure_count'] ?? null);
    $userPeriodDays = to_number_or_null($payload['user_period_days'] ?? null);
    $profile = profile_of($selected);
    $effectiveDays = $userPeriodDays !== null ? $userPeriodDays : $profile['default_period_days']($selected);
    $benignActivityMbq = to_number_or_null($payload['benign_activity_mbq'] ?? null);
    $benignFixationPct = to_number_or_null($payload['benign_fixation_pct'] ?? null);
    $doseRate = compute_dose_rate($selected, $benignActivityMbq, $benignFixationPct, $payload['dose_rate'] ?? null);
    $patientSizeCm = to_number_or_null($payload['patient_size_cm'] ?? null);
    $user = [
        'hours1' => to_number_or_null($payload['user_hours_1'] ?? null),
        'distance1' => to_number_or_null($payload['user_distance_1'] ?? null),
        'hours2' => to_number_or_null($payload['user_hours_2'] ?? null),
        'limit' => to_number_or_null($payload['user_limit'] ?? null),
    ];
    $filledCount = count(array_filter($user, static fn ($v) => $v !== null));
    $userComplete = $filledCount === count($user);
    $userEmpty = $filledCount === 0;

    $errors = [];
    if (!$isotopeCodeRecognized) {
        $errors[] = 'isotope_code inconnu ou manquant : ' . js_json_stringify($isotopeCode) . '. Codes valides : ' . implode(', ', $validCodes) . '.';
    }
    array_push($errors, ...$profile['dose_rate_errors']((string) $selected['api_code'], $benignActivityMbq, $benignFixationPct, $doseRate));
    if (!($patientSizeCm > 0)) $errors[] = 'patient_size_cm doit être strictement positif.';
    array_push($errors, ...$profile['period_errors']((string) $selected['api_code'], $effectiveDays));
    if (!$cure['valid']) $errors[] = "Pour {$selected['api_code']}, cure_count doit être l'une des valeurs suivantes : " . implode(', ', $cure['allowed']) . '.';
    if ($effectiveDays !== null && !($effectiveDays > 0)) $errors[] = 'La période effective retenue doit être strictement positive.';
    if (!$userEmpty && !$userComplete) $errors[] = 'Pour calculer le scénario utilisateur, renseignez les 4 champs bleus du scénario personnalisé, ou laissez-les tous vides.';
    if ($userComplete) {
        if (!($user['hours1'] >= 0) || !($user['hours2'] > 0)) $errors[] = 'Les durées du scénario utilisateur doivent être valides et la durée n°2 doit être strictement positive.';
        if (!($user['distance1'] > 0)) $errors[] = 'La distance X du scénario utilisateur doit être strictement positive.';
        if (!($user['limit'] > 0)) $errors[] = 'La limite dosimétrique du scénario utilisateur doit être strictement positive.';
    }

    $rows = [];
    foreach (scenarios() as $scenario) {
        // Cures multiples : si le scénario déclare shares_limit_across_cures (vrai par défaut), la même personne cumule
        // l'exposition cure après cure (conjoint, enfant...) : la limite dosimétrique est divisée par le nombre de cures.
        // Sinon (ex. transports en commun : autres passagers à chaque cure), il n'y a pas de cumul à répartir et la limite
        // reste entière. Ce choix est une donnée du scénario (data/scenarios.json), conforme à l'article de référence.
        $sharesLimit = $scenario['shares_limit_across_cures'];
        $limitForScenario = $sharesLimit ? $scenario['limit'] / $cure['value'] : $scenario['limit'];
        $conditionSuffix = $cure['value'] > 1
            ? ($sharesLimit ? " (répartie sur {$cure['value']} cures)" : ' (par cure, non cumulée)')
            : '';
        $rows[] = [
            'audience_code' => $scenario['audience_code'],
            'label' => $scenario['label'],
            'condition' => $scenario['condition'] . $conditionSuffix,
            'value' => $errors ? null : restriction_days($effectiveDays, $doseRate, $patientSizeCm, $scenario['exposures'], (float) $limitForScenario),
        ];
    }

    $userRow = ['audience_code' => 'scenario_utilisateur', 'label' => 'Scénario utilisateur', 'condition' => '-', 'value' => null];
    if ($userComplete) {
        $userRow = [
            'audience_code' => 'scenario_utilisateur',
            'label' => 'Scénario utilisateur',
            'condition' => 'Exposition : ' . js_number_to_string($user['hours1']) . ' h à ' . js_number_to_string($user['distance1']) . ' m + '
                . js_number_to_string($user['hours2']) . ' h à 1 m · limite de dose : ' . js_number_to_string($user['limit']) . ' mSv',
            'value' => $errors ? null : restriction_days($effectiveDays, $doseRate, $patientSizeCm, [
                ['hours' => $user['hours1'], 'distance' => $user['distance1'], 'unit_factor_at_1m' => false],
                ['hours' => $user['hours2'], 'distance' => 1.0, 'unit_factor_at_1m' => true],
            ], $user['limit']),
        ];
    }

    $expectedPayload = expected_payload_by_isotope($selected);
    $allRows = [...$rows, $userRow];
    $recommendationsDays = [];
    foreach ($allRows as $row) $recommendationsDays[$row['audience_code']] = $row['value'];

    $common = [
        'selected' => $selected,
        'cure_count' => $cure['value'],
        'cure_count_allowed' => $cure['allowed'],
        'computed_dose_rate' => $doseRate,
        'effective_days' => $effectiveDays,
        'effective_hours' => $effectiveDays === null ? null : $effectiveDays * 24,
    ];
    if ($errors) {
        return [
            'ok' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => $isotopeCodeRecognized
                    ? "Échec du calcul pour isotope_code={$selected['api_code']}."
                    : 'isotope_code inconnu ou manquant : ' . js_json_stringify($isotopeCode) . '.',
                'reason' => !$isotopeCodeRecognized
                    ? 'Codes valides : ' . implode(', ', $validCodes) . '.'
                    : $profile['failure_reason']((string) $selected['api_code']),
                'expected_payload' => $expectedPayload,
            ],
        ] + $common + [
            'errors' => $errors,
            'recommendations_days' => $recommendationsDays,
            'rows' => $allRows,
        ];
    }
    return ['ok' => true] + $common + [
        'errors' => [],
        'recommendations_days' => $recommendationsDays,
        'rows' => $allRows,
    ];
}
