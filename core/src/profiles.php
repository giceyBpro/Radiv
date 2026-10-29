<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== PROFILS DE CALCUL : CE QUI DIFFÈRE D'UN ISOTOPE À L'AUTRE =====
// La plupart des isotopes se calculent pareil : débit de dose mesuré à 1 m, période de l'isotope. Quelques-uns ont une règle
// propre ; elle est regroupée ici sous un NOM de profil, que l'isotope choisit dans data/isotopes.json (champ « profile »,
// "standard" par défaut). Ajouter un isotope qui suit une règle existante = ajouter une entrée de données, sans code.
//
// Un profil fournit :
//   dose_rate($activityMbq, $fixationPct, $raw) : débit de dose retenu (µSv/h), ou null
//   default_period_days($selected)              : période par défaut en jours, null si l'appelant doit la fournir
//   dose_rate_errors(...)                       : messages de validation du débit (ou de ses ingrédients)
//   period_errors($code, $effectiveDays)        : messages de validation de la période
//   inputs                                      : champs de saisie de l'isotope, annoncés aux interfaces (isotope_inputs())
//   expected_payload($code, $common)            : champs attendus, rappelés dans les erreurs
//   failure_reason($code)                       : phrase d'explication de l'erreur de validation
//
// Une NOUVELLE règle de calcul (autre formule) exige d'ajouter un profil ici : c'est une évolution du moteur, à traiter
// comme telle (voir AGENTS.md : contrat, version, cas figés).

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

function profiles(): array
{
    static $profiles = null;
    if ($profiles !== null) return $profiles;

    // Standard : débit de dose saisi, période de l'isotope.
    $standard = [
        'dose_rate' => static fn (?float $activityMbq, ?float $fixationPct, mixed $raw): ?float => to_number_or_null($raw),
        'default_period_days' => static fn (array $selected): ?float => $selected['periodHours'] / 24,
        'dose_rate_errors' => static fn (string $code, ?float $activityMbq, ?float $fixationPct, ?float $doseRate): array =>
            !($doseRate > 0) ? ['Pour cet isotope, dose_rate doit être strictement positif.'] : [],
        'period_errors' => static fn (string $code, ?float $effectiveDays): array => [],
        'inputs' => ['dose_rate' => 'required', 'benign_activity_mbq' => 'ignored', 'benign_fixation_pct' => 'ignored', 'user_period_days' => 'optional'],
        'expected_payload' => static fn (string $code, array $common): array => [
            'common' => $common,
            'isotope_specific_required' => ['dose_rate'],
            'isotope_specific_optional' => ['benign_activity_mbq', 'benign_fixation_pct (ignores hors iode131_benin)'],
        ],
        'failure_reason' => static fn (string $code): string => 'Pour cet isotope, dose_rate est obligatoire et doit être strictement positif.',
    ];

    // Iode bénin : le débit de dose se calcule à partir de l'activité administrée et de la fixation thyroïdienne
    // (2,2 × A × f / 37) ; dose_rate est ignoré.
    $benignIodine = [
        'dose_rate' => static function (?float $activityMbq, ?float $fixationPct, mixed $raw): ?float {
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
        'inputs' => ['dose_rate' => 'ignored', 'benign_activity_mbq' => 'required', 'benign_fixation_pct' => 'required', 'user_period_days' => 'optional'],
        'expected_payload' => static fn (string $code, array $common): array => [
            'common' => $common,
            'isotope_specific_required' => ['benign_activity_mbq', 'benign_fixation_pct (en %, ex. 15)'],
            'isotope_specific_optional' => ["dose_rate (ignore pour {$code})"],
        ],
        'failure_reason' => static fn (string $code): string => "Pour {$code}, benign_activity_mbq et benign_fixation_pct (en %) sont obligatoires (dose_rate est ignoré).",
    ] + $standard;

    // Période libre : pas de période par défaut, l'appelant doit fournir user_period_days (> 0).
    $freePeriod = [
        'default_period_days' => static fn (array $selected): ?float => null,
        'period_errors' => static fn (string $code, ?float $effectiveDays): array =>
            !($effectiveDays > 0) ? ["Avec isotope_code={$code}, user_period_days devient obligatoire et doit être > 0."] : [],
        'inputs' => ['dose_rate' => 'required', 'benign_activity_mbq' => 'ignored', 'benign_fixation_pct' => 'ignored', 'user_period_days' => 'required'],
        'expected_payload' => static fn (string $code, array $common): array => [
            'common' => $common,
            'isotope_specific_required' => ['dose_rate', 'user_period_days'],
            'isotope_specific_optional' => ['benign_activity_mbq', 'benign_fixation_pct (ignores hors iode131_benin)'],
        ],
        'failure_reason' => static fn (string $code): string => "Pour {$code}, dose_rate et user_period_days sont obligatoires et strictement positifs.",
    ] + $standard;

    return $profiles = ['standard' => $standard, 'benign_iodine' => $benignIodine, 'free_period' => $freePeriod];
}

// Profil de l'isotope sélectionné. Un nom inconnu dans les données est une erreur de données : on l'exprime clairement
// plutôt que de calculer silencieusement avec une mauvaise règle.
function profile_of(array $selected): array
{
    $name = isotope_profile((string) $selected['api_code']);
    $profiles = profiles();
    if (!isset($profiles[$name])) throw new \RuntimeException("Profil de calcul inconnu « {$name} » pour l'isotope {$selected['api_code']} (data/isotopes.json).");
    return $profiles[$name];
}

// Débit de dose retenu pour l'isotope : voir le profil.
function compute_dose_rate(array $selected, ?float $benignActivityMbq, ?float $benignFixationPct, mixed $doseRate): ?float
{
    return profile_of($selected)['dose_rate']($benignActivityMbq, $benignFixationPct, $doseRate);
}

// Saisie attendue pour chaque isotope, à l'usage des interfaces : code de l'isotope → champ de l'API → "required"
// (à renseigner), "optional" (facultatif) ou "ignored" (inutile : l'interface peut le masquer). Une interface construite
// sur cette table n'a plus besoin de connaître les codes d'isotopes : un isotope ajouté dans les données avec un profil
// existant s'affiche correctement sans modifier l'interface.
function isotope_inputs(): array
{
    $out = [];
    foreach (isotopes() as $selected) $out[(string) $selected['api_code']] = profile_of($selected)['inputs'];
    return $out;
}
