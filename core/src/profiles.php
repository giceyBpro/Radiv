<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== PROFILS DE CALCUL : C'EST ICI QU'ON AJOUTE UNE RÈGLE DE CALCUL =====
//
// La plupart des isotopes se calculent pareil : débit de dose mesuré à 1 m, période propre à l'isotope. Quelques-uns ont une
// règle particulière ; elle est regroupée ici sous un NOM de profil, que l'isotope choisit dans data/isotopes.json (champ
// « profile », "standard" par défaut).
//
// ---------------------------------------------------------------------------------------------------------------------
// COMMENT AJOUTER UNE NOUVELLE RÈGLE DE CALCUL (4 étapes)
// ---------------------------------------------------------------------------------------------------------------------
//  1. Champs de saisie. La règle utilise-t-elle un champ qui n'existe pas encore (une masse, un volume, un choix...) ?
//     Si oui, le décrire dans data/fields.json (code, libellé, unité, type "number" ou "choice", bornes, aide ; guide :
//     data/FIELDS.md). Les sites qui construisent leur formulaire d'après input_fields() (GET config de l'API) affichent alors ce champ SANS modification. Si la règle n'utilise que
//     des champs existants, passer à l'étape 2.
//  2. Profil. Dans profiles() ci-dessous, copier le profil « EXEMPLE À COPIER » (il est commenté), le renommer et le
//     compléter. Un profil ne décrit que ce qui change par rapport au profil standard : ce qu'il ne définit pas est
//     repris du standard. Un profil contient jusqu'à cinq éléments (voir la liste plus bas).
//  3. Isotopes. Dans data/isotopes.json, ajouter  "profile": "nom_du_profil"  à chaque isotope qui suit la règle. Un isotope
//     qui suit une règle EXISTANTE s'ajoute sans aucun code : c'est seulement une entrée de données.
//  4. Contrôles. Ajouter des cas à core/tests/ (voir test-fields.php, qui applique cet exemple), regénérer les cas figés si des
//     résultats existants changent, incrémenter VERSION et consigner la règle dans data/SOURCES.md (référence publiée de la
//     formule). Une règle qui ne modifie aucun résultat existant est une version MINEURE ; sinon, MAJEURE (voir AGENTS.md).
//
// ---------------------------------------------------------------------------------------------------------------------
// LES CINQ ÉLÉMENTS D'UN PROFIL
// ---------------------------------------------------------------------------------------------------------------------
// Tous les éléments qui lisent la saisie reçoivent $fields : le tableau des champs de data/fields.json, indexé par leur code,
// déjà converti (un nombre ou null pour « number » ; le texte de l'option ou null pour « choice »). Un champ non renseigné
// ou illisible vaut null : à chaque règle de décider si c'est une erreur.
//
//   inputs               saisie attendue pour cet isotope : code du champ → "required" (à renseigner), "optional" ou "ignored".
//                        Les champs non cités sont « ignored ». Annoncé aux interfaces (isotope_inputs) : c'est ce qui fait
//                        afficher ou masquer chaque champ sur les sites.
//   dose_rate            (array $fields) → débit de dose retenu en µSv/h, ou null s'il ne peut pas être établi.
//   default_period_days  (array $isotope, array $fields) → période effective par défaut en jours ; null si l'appelant doit la
//                        fournir (user_period_days).
//   dose_rate_errors     (string $code, array $fields, ?float $doseRate) → liste de messages si la saisie du débit (ou des champs
//                        dont il dépend) est invalide ; liste vide si tout va bien. Les champs propres à la règle se contrôlent ici.
//   period_errors        (string $code, ?float $effectiveDays, array $fields) → messages sur la période retenue.
//
// $code est le code de l'isotope (pour des messages clairs : « Pour radium223, ... »).

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// Les profils connus, par nom.
function profiles(): array
{
    static $profiles = null;
    if ($profiles !== null) return $profiles;

    // STANDARD : le débit de dose est saisi (dose_rate), la période est celle de l'isotope.
    $standard = [
        'inputs' => ['dose_rate' => 'required', 'user_period_days' => 'optional'],
        'dose_rate' => static fn (array $fields): ?float => $fields['dose_rate'],
        'default_period_days' => static fn (array $isotope, array $fields): ?float => $isotope['periodHours'] / 24,
        'dose_rate_errors' => static fn (string $code, array $fields, ?float $doseRate): array =>
            !($doseRate > 0) ? ['Pour cet isotope, dose_rate doit être strictement positif.'] : [],
        'period_errors' => static fn (string $code, ?float $effectiveDays, array $fields): array => [],
    ];

    // IODE BÉNIN : le débit de dose se calcule à partir de l'activité administrée A (MBq) et de la fixation thyroïdienne f (%) :
    //     débit = 2,2 × A × (f / 100) / 37        ; le débit saisi éventuel (dose_rate) est ignoré.
    $benignIodine = [
        'inputs' => ['benign_activity_mbq' => 'required', 'benign_fixation_pct' => 'required', 'user_period_days' => 'optional'],
        'dose_rate' => static function (array $fields): ?float {
            $activityMbq = $fields['benign_activity_mbq'];
            $fixationPct = $fields['benign_fixation_pct'];
            if ($activityMbq === null || $fixationPct === null) return null;
            return 2.2 * $activityMbq * ($fixationPct / 100) / 37;
        },
        'dose_rate_errors' => static function (string $code, array $fields, ?float $doseRate): array {
            $activityMbq = $fields['benign_activity_mbq'];
            $fixationPct = $fields['benign_fixation_pct'];
            $errors = [];
            if (!($activityMbq > 0)) $errors[] = "Pour {$code}, benign_activity_mbq doit être strictement positif.";
            if (!($fixationPct > 0 && $fixationPct <= 100)) $errors[] = "Pour {$code}, benign_fixation_pct doit être un pourcentage strictement positif et ≤ 100 (ex. 15).";
            if (($activityMbq > 0) && ($fixationPct > 0) && !($doseRate > 0)) $errors[] = "Le débit calculé automatiquement pour {$code} est invalide. Vérifiez benign_activity_mbq et benign_fixation_pct.";
            return $errors;
        },
    ] + $standard;

    // PÉRIODE LIBRE : l'isotope n'a pas de période propre ; l'appelant doit fournir user_period_days (> 0).
    $freePeriod = [
        'inputs' => ['dose_rate' => 'required', 'user_period_days' => 'required'],
        'default_period_days' => static fn (array $isotope, array $fields): ?float => null,
        'period_errors' => static fn (string $code, ?float $effectiveDays, array $fields): array =>
            !($effectiveDays > 0) ? ["Avec isotope_code={$code}, user_period_days devient obligatoire et doit être > 0."] : [],
    ] + $standard;

    // ===== EXEMPLE À COPIER (désactivé : une règle fictive, fondée sur un champ « poids » à déclarer dans data/fields.json) =====
    // Règle fictive : le débit de dose saisi est ramené à un patient de référence de 70 kg  (débit × poids / 70).
    //
    //   data/fields.json :  {"code": "poids_kg", "label": "Poids du patient (kg)", "unit": "kg", "type": "number", "min": 1, "step": "any"}
    //   data/isotopes.json : sur l'isotope concerné, ajouter  "profile": "poids_reference"
    //
    //   $poidsReference = [
    //       // dose_rate (débit saisi) et poids_kg sont obligatoires ; les autres champs sont ignorés ; la période reste facultative.
    //       'inputs' => ['dose_rate' => 'required', 'poids_kg' => 'required', 'user_period_days' => 'optional'],
    //       // Débit retenu pour le calcul : null si une donnée manque (l'erreur est alors signalée par dose_rate_errors).
    //       'dose_rate' => static function (array $fields): ?float {
    //           if ($fields['dose_rate'] === null || $fields['poids_kg'] === null) return null;
    //           return $fields['dose_rate'] * $fields['poids_kg'] / 70;
    //       },
    //       // Messages de validation (liste vide = saisie correcte). Le code de l'isotope rend le message précis.
    //       'dose_rate_errors' => static function (string $code, array $fields, ?float $doseRate): array {
    //           $errors = [];
    //           if (!($fields['poids_kg'] > 0)) $errors[] = "Pour {$code}, poids_kg doit être strictement positif.";
    //           if (!($fields['dose_rate'] > 0)) $errors[] = "Pour {$code}, dose_rate doit être strictement positif.";
    //           return $errors;
    //       },
    //   ] + $standard;   // + $standard : tout ce qui n'est pas défini ci-dessus est repris du profil standard
    //
    //   puis ajouter  'poids_reference' => $poidsReference  dans le tableau renvoyé juste en dessous.
    // ===== FIN DE L'EXEMPLE =====

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

// Saisie attendue pour un profil, pour TOUS les champs de data/fields.json : ceux que le profil ne cite pas sont « ignored ».
// Un champ cité par un profil mais absent de data/fields.json est une erreur de données (faute de frappe probable).
function profile_inputs(array $isotope): array
{
    // Les champs communs (taille du patient) ne dépendent pas de l'isotope : ils n'entrent pas dans la saisie par profil.
    $declared = array_column(array_filter(input_fields(), static fn (array $field): bool => !$field['common']), 'code');
    $wanted = profile_of($isotope)['inputs'];
    foreach (array_keys($wanted) as $code) {
        if (!in_array($code, $declared, true)) {
            throw new \RuntimeException("Le profil de l'isotope {$isotope['api_code']} cite le champ « {$code} », absent de data/fields.json.");
        }
    }
    $all = [];
    foreach ($declared as $code) $all[$code] = $wanted[$code] ?? 'ignored';
    return $all;
}

// Débit de dose (µSv/h) retenu pour l'isotope : voir la règle du profil.
function compute_dose_rate(array $isotope, array $fields): ?float
{
    return profile_of($isotope)['dose_rate']($fields);
}

// Saisie attendue pour chaque isotope, à l'usage des interfaces : code de l'isotope → champ de l'API → "required" (à
// renseigner), "optional" (facultatif) ou "ignored" (inutile : l'interface peut le masquer). Une interface construite sur
// cette table n'a plus besoin de connaître les codes d'isotopes : un isotope ajouté dans les données avec un profil
// existant s'affiche correctement sans modifier l'interface.
function isotope_inputs(): array
{
    $out = [];
    foreach (isotopes() as $isotope) $out[(string) $isotope['api_code']] = profile_inputs($isotope);
    return $out;
}

// Champs attendus, rappelés dans les erreurs de validation (clé expected_payload). Déduits de la saisie du profil : « common » est
// ce que tous les isotopes acceptent, le reste est propre à l'isotope.
function expected_payload(array $isotope): array
{
    $common = ['isotope_code', 'patient_size_cm', 'user_period_days', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit', 'cure_count'];
    $hints = ['benign_fixation_pct' => ' (en %, ex. 15)'];   // précision ajoutée au nom du champ
    $inputs = profile_inputs($isotope);
    $fieldsWhere = static function (string $kind) use ($inputs, $hints): array {
        $names = array_keys(array_filter($inputs, static fn (string $need): bool => $need === $kind));
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
    $required = array_keys(array_filter(profile_inputs($isotope), static fn (string $need): bool => $need === 'required'));
    return "Pour {$isotope['api_code']}, champs obligatoires : " . implode(', ', $required) . ' (valeurs strictement positives).';
}
