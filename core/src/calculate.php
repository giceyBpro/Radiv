<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== VALIDATION DES ENTRÉES ET ASSEMBLAGE DU RÉSULTAT =====
// calculate($payload) est l'unique fonction à appeler. Elle fait quatre choses, chacune dans sa fonction ci-dessous :
//   1. read_entries()    lit et convertit les champs reçus (tout ce qui est illisible devient null), dont les champs de data/fields.json
//   2. entry_errors()    liste les erreurs de saisie, en phrases lisibles
//   3. scenario_rows()   calcule une durée de restriction par scénario (data/scenarios.json), plus le scénario utilisateur
//   4. calculate()       assemble la réponse : « ok », les durées (rows, recommendations_days) ou les erreurs
// Le résultat est un tableau prêt à être encodé en JSON. Ses clés sont décrites dans README.md (section « Contrat »).

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// Nombre de cures retenu pour un isotope : ['value' => nombre retenu, 'allowed' => nombres autorisés, 'valid' => saisie valide ?].
// Un isotope sans champ « cures » dans les données n'admet qu'une cure ; une saisie absente vaut 1 ; une saisie non
// autorisée vaut 1 avec valid = false (erreur signalée par entry_errors()).
function normalize_cure_count(array $isotope, mixed $enteredCount): array
{
    $options = cure_options_by_isotope();
    if (!array_key_exists($isotope['api_code'], $options)) return ['value' => 1, 'allowed' => [1], 'valid' => true];

    $allowed = $options[$isotope['api_code']];
    $count = to_number_or_null($enteredCount);
    if ($count === null) return ['value' => 1, 'allowed' => $allowed, 'valid' => true];

    // Comme JavaScript (Number.isInteger + includes) : un entier écrit en flottant (4.0) est accepté.
    $isAllowed = floor($count) == $count && in_array($count, $allowed, false);
    return $isAllowed ? ['value' => (int) $count, 'allowed' => $allowed, 'valid' => true]
                      : ['value' => 1, 'allowed' => $allowed, 'valid' => false];
}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Lecture des entrées
// ---------------------------------------------------------------------------------------------------------------------

// Champs de saisie déclarés dans data/fields.json (voir catalog.php), lus dans le corps de la requête : code → valeur.
//   type "number" : un nombre (float), ou null si le champ est absent ou illisible ;
//   type "choice" : le texte d'une des options, ou null si absent ; une valeur qui n'est pas une option est conservée telle quelle
//                   (texte) pour que entry_errors() la signale.
function read_fields(array $payload): array
{
    $fields = [];
    foreach (input_fields() as $field) {
        $entered = $payload[$field['code']] ?? null;
        if ($field['type'] === 'number') {
            $fields[$field['code']] = to_number_or_null($entered);
        } else {
            $fields[$field['code']] = is_string($entered) && $entered !== '' ? $entered : (is_int($entered) || is_float($entered) ? (string) $entered : null);
        }
    }
    return $fields;
}

// Entrées du calcul, converties. Les champs numériques absents ou illisibles valent null (voir to_number_or_null).
function read_entries(array $payload): array
{
    $isotopeCode = $payload['isotope_code'] ?? null;
    $isotope = get_isotope($isotopeCode);   // retombe sur le premier isotope si le code est inconnu : voir « recognized »
    $profile = profile_of($isotope);
    $fields = read_fields($payload);

    // Scénario utilisateur : durée 1 à la distance X, durée 2 à 1 m, limite de dose. Tout ou rien.
    $user = [
        'hours1' => to_number_or_null($payload['user_hours_1'] ?? null),
        'distance1' => to_number_or_null($payload['user_distance_1'] ?? null),
        'hours2' => to_number_or_null($payload['user_hours_2'] ?? null),
        'limit' => to_number_or_null($payload['user_limit'] ?? null),
    ];
    $filledCount = count(array_filter($user, static fn ($value) => $value !== null));

    return [
        'isotopeCode' => $isotopeCode,
        'isotope' => $isotope,
        'profile' => $profile,
        // get_isotope() ne signale pas un code inconnu (faute de frappe, casse, absent) : sans ce contrôle, le calcul se
        // ferait pour le mauvais isotope et renverrait ok:true avec un résultat d'apparence correcte, mais faux.
        'recognized' => is_string($isotopeCode) && in_array($isotopeCode, array_column(isotopes(), 'api_code'), true),
        'cure' => normalize_cure_count($isotope, $payload['cure_count'] ?? null),
        'fields' => $fields,
        'doseRate' => compute_dose_rate($isotope, $fields),
        'patientSizeCm' => to_number_or_null($payload['patient_size_cm'] ?? null),
        // Période retenue : celle saisie par l'appelant, sinon celle de l'isotope (null si l'appelant doit la fournir).
        'effectiveDays' => $fields['user_period_days'] ?? $profile['default_period_days']($isotope, $fields),
        'user' => $user,
        'userComplete' => $filledCount === count($user),
        'userEmpty' => $filledCount === 0,
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Erreurs de saisie
// ---------------------------------------------------------------------------------------------------------------------

// Liste des erreurs de saisie (phrases lisibles) ; liste vide = calcul possible. L'ordre des messages est stable.
function entry_errors(array $in): array
{
    $code = (string) $in['isotope']['api_code'];
    $profile = $in['profile'];
    $user = $in['user'];
    $errors = [];

    if (!$in['recognized']) {
        $validCodes = implode(', ', array_column(isotopes(), 'api_code'));
        $errors[] = 'isotope_code inconnu ou manquant : ' . js_json_stringify($in['isotopeCode']) . ". Codes valides : {$validCodes}.";
    }
    array_push($errors, ...$profile['dose_rate_errors']($code, $in['fields'], $in['doseRate']));
    if (!($in['patientSizeCm'] > 0)) $errors[] = 'patient_size_cm doit être strictement positif.';
    array_push($errors, ...$profile['period_errors']($code, $in['effectiveDays'], $in['fields']));
    array_push($errors, ...choice_errors($in));
    if (!$in['cure']['valid']) $errors[] = "Pour {$code}, cure_count doit être l'une des valeurs suivantes : " . implode(', ', $in['cure']['allowed']) . '.';
    if ($in['effectiveDays'] !== null && !($in['effectiveDays'] > 0)) $errors[] = 'La période effective retenue doit être strictement positive.';

    if (!$in['userEmpty'] && !$in['userComplete']) {
        $errors[] = 'Pour calculer le scénario utilisateur, renseignez les 4 champs bleus du scénario personnalisé, ou laissez-les tous vides.';
    }
    if ($in['userComplete']) {
        if (!($user['hours1'] >= 0) || !($user['hours2'] > 0)) $errors[] = 'Les durées du scénario utilisateur doivent être valides et la durée n°2 doit être strictement positive.';
        if (!($user['distance1'] > 0)) $errors[] = 'La distance X du scénario utilisateur doit être strictement positive.';
        if (!($user['limit'] > 0)) $errors[] = 'La limite dosimétrique du scénario utilisateur doit être strictement positive.';
    }
    return $errors;
}

// Contrôle des champs « choice » (listes de choix) de data/fields.json : obligatoire si le profil l'exige, et valeur parmi les options.
// Les champs « number » sont contrôlés par le profil (profiles.php), qui sait quelles bornes s'appliquent.
function choice_errors(array $in): array
{
    $code = (string) $in['isotope']['api_code'];
    $inputs = profile_inputs($in['isotope']);
    $errors = [];
    foreach (input_fields() as $field) {
        if ($field['type'] !== 'choice' || $inputs[$field['code']] === 'ignored') continue;
        $value = $in['fields'][$field['code']];
        $allowed = array_column($field['options'], 'value');
        if ($value === null) {
            if ($inputs[$field['code']] === 'required') $errors[] = "Pour {$code}, {$field['code']} est obligatoire (valeurs possibles : " . implode(', ', $allowed) . ').';
        } elseif (!in_array($value, $allowed, true)) {
            $errors[] = "Pour {$code}, {$field['code']} doit être l'une des valeurs suivantes : " . implode(', ', $allowed) . '.';
        }
    }
    return $errors;
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Une ligne de résultat par scénario
// ---------------------------------------------------------------------------------------------------------------------

// Lignes de résultat : les scénarios de data/scenarios.json, dans l'ordre du fichier, puis le scénario utilisateur.
// Chaque ligne : audience_code, label, condition (texte des hypothèses), value (jours entiers, ou null).
// `$calculable` vaut false s'il y a des erreurs de saisie : toutes les durées sont alors null.
function scenario_rows(array $in, bool $calculable): array
{
    $cures = $in['cure']['value'];
    $duration = static fn (array $exposures, float $limit): ?float =>
        $calculable ? restriction_days($in['effectiveDays'], $in['doseRate'], $in['patientSizeCm'], $exposures, $limit) : null;

    $rows = [];
    foreach (scenarios() as $scenario) {
        // Cures multiples. Si le scénario partage sa limite entre les cures (cas par défaut : la même personne cumule la
        // dose cure après cure, ex. conjoint, enfant), la limite est divisée par le nombre de cures. Sinon (ex. transports
        // en commun : d'autres passagers à chaque cure), il n'y a rien à répartir et la limite reste entière. C'est une
        // donnée du scénario (data/scenarios.json), conforme à l'article de référence.
        $shared = $scenario['shares_limit_across_cures'];
        $limit = $shared ? $scenario['limit'] / $cures : $scenario['limit'];
        $note = $cures > 1 ? ($shared ? " (répartie sur {$cures} cures)" : ' (par cure, non cumulée)') : '';

        $rows[] = [
            'audience_code' => $scenario['audience_code'],
            'label' => $scenario['label'],
            'condition' => $scenario['condition'] . $note,
            'value' => $duration($scenario['exposures'], (float) $limit),
        ];
    }

    $rows[] = user_scenario_row($in, $duration);
    return $rows;
}

// Ligne du scénario utilisateur (« scenario_utilisateur »). Si les 4 champs ne sont pas tous fournis, la ligne existe mais
// reste vide (condition « - », durée null) : les interfaces peuvent la masquer.
function user_scenario_row(array $in, callable $duration): array
{
    $row = ['audience_code' => 'scenario_utilisateur', 'label' => 'Scénario utilisateur', 'condition' => '-', 'value' => null];
    if (!$in['userComplete']) return $row;

    $u = $in['user'];
    $row['condition'] = 'Exposition : ' . js_number_to_string($u['hours1']) . ' h à ' . js_number_to_string($u['distance1']) . ' m + '
        . js_number_to_string($u['hours2']) . ' h à 1 m · limite de dose : ' . js_number_to_string($u['limit']) . ' mSv';
    $row['value'] = $duration([
        ['hours' => $u['hours1'], 'distance' => $u['distance1'], 'unit_factor_at_1m' => false],
        ['hours' => $u['hours2'], 'distance' => 1.0, 'unit_factor_at_1m' => true],   // la durée n°2 est toujours à 1 m
    ], $u['limit']);
    return $row;
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Point d'entrée
// ---------------------------------------------------------------------------------------------------------------------

// $payload : tableau associatif issu de json_decode($corps, true), ou tableau vide.
// Réponse de succès : ['ok' => true, 'selected' => isotope, 'cure_count', 'effective_days', 'rows' => [...], 'recommendations_days' => [code => jours], ...].
// Réponse d'erreur  : mêmes clés, 'ok' => false, 'error' => {code, message, reason, expected_payload}, 'errors' => [phrases], durées à null.
function calculate(array $payload): array
{
    $in = read_entries($payload);
    $errors = entry_errors($in);
    $rows = scenario_rows($in, !$errors);

    $recommendations = [];
    foreach ($rows as $row) $recommendations[$row['audience_code']] = $row['value'];

    // Clés communes aux réponses de succès et d'erreur.
    $common = [
        'selected' => $in['isotope'],
        'cure_count' => $in['cure']['value'],
        'cure_count_allowed' => $in['cure']['allowed'],
        'computed_dose_rate' => $in['doseRate'],
        'effective_days' => $in['effectiveDays'],
        'effective_hours' => $in['effectiveDays'] === null ? null : $in['effectiveDays'] * 24,
        'errors' => $errors,
        'recommendations_days' => $recommendations,
        'rows' => $rows,
    ];
    if (!$errors) return ['ok' => true] + $common;

    return ['ok' => false, 'error' => error_summary($in)] + $common;
}

// Bloc « error » d'une réponse en échec : code, message court, explication, et champs attendus.
function error_summary(array $in): array
{
    $unknownCode = 'isotope_code inconnu ou manquant : ' . js_json_stringify($in['isotopeCode']) . '.';
    $validCodes = 'Codes valides : ' . implode(', ', array_column(isotopes(), 'api_code')) . '.';
    return [
        'code' => 'VALIDATION_ERROR',
        'message' => $in['recognized'] ? "Échec du calcul pour isotope_code={$in['isotope']['api_code']}." : $unknownCode,
        'reason' => $in['recognized'] ? failure_reason($in['isotope']) : $validCodes,
        'expected_payload' => expected_payload($in['isotope']),
    ];
}
