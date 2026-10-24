<?php
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
    if ($selected['api_code'] === 'iode131_benin') {
        return [
            'common' => $common,
            'isotope_specific_required' => ['benign_activity_mbq', 'benign_fixation_pct (en %, ex. 15)'],
            'isotope_specific_optional' => ['dose_rate (ignore pour iode131_benin)'],
        ];
    }
    if ($selected['api_code'] === 'non_defini') {
        return [
            'common' => $common,
            'isotope_specific_required' => ['dose_rate', 'user_period_days'],
            'isotope_specific_optional' => ['benign_activity_mbq', 'benign_fixation_pct (ignores hors iode131_benin)'],
        ];
    }
    return [
        'common' => $common,
        'isotope_specific_required' => ['dose_rate'],
        'isotope_specific_optional' => ['benign_activity_mbq', 'benign_fixation_pct (ignores hors iode131_benin)'],
    ];
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
    $effectiveDays = $userPeriodDays !== null
        ? $userPeriodDays
        : ($selected['api_code'] === 'non_defini' ? null : $selected['periodHours'] / 24);
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
    if ($selected['api_code'] === 'iode131_benin') {
        if (!($benignActivityMbq > 0)) $errors[] = 'Pour iode131_benin, benign_activity_mbq doit être strictement positif.';
        if (!($benignFixationPct > 0 && $benignFixationPct <= 100)) $errors[] = 'Pour iode131_benin, benign_fixation_pct doit être un pourcentage strictement positif et ≤ 100 (ex. 15).';
        if (($benignActivityMbq > 0) && ($benignFixationPct > 0) && !($doseRate > 0)) $errors[] = 'Le débit calculé automatiquement pour iode131_benin est invalide. Vérifiez benign_activity_mbq et benign_fixation_pct.';
    } elseif (!($doseRate > 0)) {
        $errors[] = 'Pour cet isotope, dose_rate doit être strictement positif.';
    }
    if (!($patientSizeCm > 0)) $errors[] = 'patient_size_cm doit être strictement positif.';
    if ($selected['api_code'] === 'non_defini' && !($effectiveDays > 0)) $errors[] = 'Avec isotope_code=non_defini, user_period_days devient obligatoire et doit être > 0.';
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
        // Cures multiples: la limite dosimétrique de chaque scénario est divisée par le nombre
        // de cures, SAUF transport_commun. Pour les autres scénarios (conjoint, enfant...),
        // c'est la même personne qui cumule l'exposition cure après cure, d'où la division.
        // Pour le transport en commun, chaque cure expose des personnes différentes (autres
        // passagers) : il n'y a pas de cumul à répartir, donc pas de division. Volontaire,
        // conforme à l'article de référence.
        $isTransportCommun = $scenario['audience_code'] === 'transport_commun';
        $limitForScenario = $isTransportCommun ? $scenario['limit'] : $scenario['limit'] / $cure['value'];
        $conditionSuffix = $cure['value'] > 1
            ? ($isTransportCommun ? ' (par cure, non cumulée)' : " (répartie sur {$cure['value']} cures)")
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
                    : ($selected['api_code'] === 'iode131_benin'
                        ? 'Pour iode131_benin, benign_activity_mbq et benign_fixation_pct (en %) sont obligatoires (dose_rate est ignoré).'
                        : ($selected['api_code'] === 'non_defini'
                            ? 'Pour non_defini, dose_rate et user_period_days sont obligatoires et strictement positifs.'
                            : 'Pour cet isotope, dose_rate est obligatoire et doit être strictement positif.')),
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
