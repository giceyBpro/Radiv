<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== PROFILS DE CALCUL : CE QUI DIFFÈRE D'UN ISOTOPE À L'AUTRE =====
// La plupart des isotopes se calculent pareil : débit de dose mesuré à 1 m, période propre à l'isotope. Quelques-uns ont une
// règle particulière ; elle est regroupée ici sous un NOM de profil, que l'isotope choisit dans data/isotopes.json (champ
// « profile », "standard" par défaut). Ajouter un isotope qui suit une règle existante = ajouter une entrée de données,
// sans code. Une NOUVELLE règle (autre formule) exige d'ajouter un profil ici : c'est une évolution du moteur, à traiter
// comme telle (voir AGENTS.md : contrat, version, cas figés).
//
// Un profil est un tableau de cinq éléments :
//   inputs              saisie attendue, champ de l'API → "required" | "optional" | "ignored" (annoncée aux interfaces par
//                       isotope_inputs() ; sert aussi à construire les messages d'erreur)
//   dose_rate           (activité MBq, fixation %, débit saisi) → débit de dose retenu en µSv/h, ou null
//   default_period_days (isotope) → période par défaut en jours, null si l'appelant doit la fournir
//   dose_rate_errors    (code, activité, fixation, débit retenu) → messages d'erreur sur le débit ou ses ingrédients
//   period_errors       (code, période retenue) → messages d'erreur sur la période
//
// Les profils « benign_iodine » et « free_period » ne décrivent que ce qui change : le reste vient du profil standard.

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// Les trois profils connus, par nom.
function profiles(): array
{
    static $profiles = null;
    if ($profiles !== null) return $profiles;

    // STANDARD : le débit de dose est saisi, la période est celle de l'isotope.
    $standard = [
        'inputs' => ['dose_rate' => 'required', 'benign_activity_mbq' => 'ignored', 'benign_fixation_pct' => 'ignored', 'user_period_days' => 'optional'],
        'dose_rate' => static fn (?float $activityMbq, ?float $fixationPct, mixed $entered): ?float => to_number_or_null($entered),
        'default_period_days' => static fn (array $isotope): ?float => $isotope['periodHours'] / 24,
        'dose_rate_errors' => static fn (string $code, ?float $activityMbq, ?float $fixationPct, ?float $doseRate): array =>
            !($doseRate > 0) ? ['Pour cet isotope, dose_rate doit être strictement positif.'] : [],
        'period_errors' => static fn (string $code, ?float $effectiveDays): array => [],
    ];

    // IODE BÉNIN : le débit de dose se calcule à partir de l'activité administrée A (MBq) et de la fixation thyroïdienne f (%) :
    //     débit = 2,2 × A × (f / 100) / 37        ; le débit saisi éventuel (dose_rate) est ignoré.
    $benignIodine = [
        'inputs' => ['dose_rate' => 'ignored', 'benign_activity_mbq' => 'required', 'benign_fixation_pct' => 'required', 'user_period_days' => 'optional'],
        'dose_rate' => static function (?float $activityMbq, ?float $fixationPct, mixed $entered): ?float {
            if ($activityMbq === null || $fixationPct === null) return null;
            return 2.2 * $activityMbq * ($fixationPct / 100) / 37;
        },
        'dose_rate_errors' => static function (string $code, ?float $activityMbq, ?float $fixationPct, ?float $doseRate): array {
            $errors = [];
            if (!($activityMbq > 0)) $errors[] = "Pour {$code}, benign_activity_mbq doit être strictement positif.";
            if (!($fixationPct > 0 && $fixationPct <= 100)) $errors[] = "Pour {$code}, benign_fixation_pct doit être un pourcentage strictement positif et ≤ 100 (ex. 15).";
            if (($activityMbq > 0) && ($fixationPct > 0) && !($doseRate > 0)) $errors[] = "Le débit calculé automatiquement pour {$code} est invalide. Vérifiez benign_activity_mbq et benign_fixation_pct.";
            return $errors;
        },
    ] + $standard;

    // PÉRIODE LIBRE : l'isotope n'a pas de période propre ; l'appelant doit fournir user_period_days (> 0).
    $freePeriod = [
        'inputs' => ['dose_rate' => 'required', 'benign_activity_mbq' => 'ignored', 'benign_fixation_pct' => 'ignored', 'user_period_days' => 'required'],
        'default_period_days' => static fn (array $isotope): ?float => null,
        'period_errors' => static fn (string $code, ?float $effectiveDays): array =>
            !($effectiveDays > 0) ? ["Avec isotope_code={$code}, user_period_days devient obligatoire et doit être > 0."] : [],
    ] + $standard;

    return $profiles = ['standard' => $standard, 'benign_iodine' => $benignIodine, 'free_period' => $freePeriod];
}

// Profil de l'isotope choisi. Un nom inconnu dans les données est une erreur de données : on l'exprime clairement plutôt
// que de calculer silencieusement avec une mauvaise règle.
function profile_of(array $isotope): array
{
    $name = isotope_profile((string) $isotope['api_code']);
    $profiles = profiles();
    if (!isset($profiles[$name])) throw new \RuntimeException("Profil de calcul inconnu « {$name} » pour l'isotope {$isotope['api_code']} (data/isotopes.json).");
    return $profiles[$name];
}

// Débit de dose (µSv/h) retenu pour l'isotope : voir la règle du profil.
function compute_dose_rate(array $isotope, ?float $benignActivityMbq, ?float $benignFixationPct, mixed $enteredDoseRate): ?float
{
    return profile_of($isotope)['dose_rate']($benignActivityMbq, $benignFixationPct, $enteredDoseRate);
}

// Saisie attendue pour chaque isotope, à l'usage des interfaces : code de l'isotope → champ de l'API → "required" (à
// renseigner), "optional" (facultatif) ou "ignored" (inutile : l'interface peut le masquer). Une interface construite sur
// cette table n'a plus besoin de connaître les codes d'isotopes : un isotope ajouté dans les données avec un profil
// existant s'affiche correctement sans modifier l'interface.
function isotope_inputs(): array
{
    $out = [];
    foreach (isotopes() as $isotope) $out[(string) $isotope['api_code']] = profile_of($isotope)['inputs'];
    return $out;
}

// Champs attendus, rappelés dans les erreurs de validation (clé expected_payload). Déduits de `inputs` : « common » est ce
// que tous les isotopes acceptent, le reste est propre à l'isotope.
function expected_payload(array $isotope): array
{
    $common = ['isotope_code', 'patient_size_cm', 'user_period_days', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit', 'cure_count'];
    $hints = ['benign_fixation_pct' => ' (en %, ex. 15)'];   // précision ajoutée au nom du champ
    $fieldsWhere = static function (string $kind) use ($isotope, $hints): array {
        $names = array_keys(array_filter(profile_of($isotope)['inputs'], static fn (string $need): bool => $need === $kind));
        return array_map(static fn (string $name): string => $name . ($hints[$name] ?? ''), $names);
    };
    return [
        'common' => $common,
        'isotope_specific_required' => $fieldsWhere('required'),
        'isotope_specific_optional' => array_values(array_diff($fieldsWhere('optional'), $common)),
        'isotope_specific_ignored' => $fieldsWhere('ignored'),
    ];
}

// Phrase d'explication d'un échec de validation (clé error.reason) : liste les champs obligatoires de l'isotope.
function failure_reason(array $isotope): string
{
    $required = array_keys(array_filter(profile_of($isotope)['inputs'], static fn (string $need): bool => $need === 'required'));
    return "Pour {$isotope['api_code']}, champs obligatoires : " . implode(', ', $required) . ' (valeurs strictement positives).';
}
