<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== CATALOGUE : DONNÉES, ISOTOPES, SCÉNARIOS D'EXPOSITION, CURES =====
// Les valeurs ne sont PAS dans le code mais dans data/*.json (à modifier là, sans toucher à PHP) :
//
//   data/isotopes.json   un isotope par entrée :
//       api_code            identifiant stable utilisé par l'API (ex. "radium223")
//       label, reference, remark, situation   textes affichés (références détaillées : data/SOURCES.md)
//       period_hours | period_days   période effective en heures OU en jours (× 24) ; null = « à fournir par l'appelant »
//       profile             (facultatif) règle de calcul propre à l'isotope, voir profiles.php ; absent = "standard"
//       cures               (facultatif) nombres de cures autorisés, ex. [1, 4] ; absent = une seule cure
//   data/fields.json     les champs de saisie propres aux isotopes (libellé, unité, bornes, type) : guide dans data/FIELDS.md
//   data/scenarios.json  les situations d'exposition de l'entourage (guide complet : data/SCENARIOS.md)
//   data/settings.json   réglages du moteur (mode d'arrondi par défaut)

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// ---------------------------------------------------------------------------------------------------------------------
// Lecture des fichiers
// ---------------------------------------------------------------------------------------------------------------------

// Contenu d'un fichier de data/ (sans l'extension), lu une seule fois par requête. Fichier absent ou illisible :
// exception explicite, jamais de calcul sur des données incomplètes.
function load_data(string $name): array
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $raw = @file_get_contents(dirname(__DIR__) . '/data/' . $name . '.json');
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException("Données du moteur illisibles : data/{$name}.json");
        $cache[$name] = $data;
    }
    return $cache[$name];
}

// Version du moteur (fichier VERSION à la racine, format MAJEURE.MINEURE.CORRECTIF) : permet à un site de vérifier qu'il
// utilise une version compatible avec ce qu'il attend. "0.0.0" si le fichier est absent ou invalide.
function version(): string
{
    $raw = @file_get_contents(dirname(__DIR__) . '/VERSION');
    $version = $raw === false ? '' : trim($raw);
    return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : '0.0.0';
}

// ---------------------------------------------------------------------------------------------------------------------
// Isotopes
// ---------------------------------------------------------------------------------------------------------------------

// Liste des isotopes pour les interfaces. La période est toujours donnée en heures (clé `periodHours`, forme historique
// conservée) : null si l'appelant doit la fournir. Le profil de calcul n'est volontairement pas exposé ici.
function isotopes(): array
{
    return array_map(static function (array $row): array {
        $hours = array_key_exists('period_days', $row) ? $row['period_days'] * 24 : ($row['period_hours'] ?? null);
        return [
            'api_code' => $row['api_code'],
            'label' => $row['label'],
            'periodHours' => $hours,
            'reference' => $row['reference'],
            'remark' => $row['remark'],
            'situation' => $row['situation'],
        ];
    }, load_data('isotopes'));
}

// Isotope correspondant à `$apiCode`. Un code inconnu renvoie le premier isotope de la liste : à l'appelant de vérifier
// que le code est reconnu (calculate() le fait et signale l'erreur).
function get_isotope(mixed $apiCode): array
{
    $all = isotopes();
    foreach ($all as $isotope) {
        if ($isotope['api_code'] === $apiCode) return $isotope;
    }
    return $all[0];
}

// Nom du profil de calcul d'un isotope (champ « profile » de isotopes.json ; "standard" si absent). Voir profiles.php.
function isotope_profile(string $apiCode): string
{
    foreach (load_data('isotopes') as $row) {
        if (($row['api_code'] ?? null) === $apiCode) return (string) ($row['profile'] ?? 'standard');
    }
    return 'standard';
}

// Nombres de cures autorisés : code de l'isotope → liste, pour les seuls isotopes qui déclarent un champ « cures ».
function cure_options_by_isotope(): array
{
    $options = [];
    foreach (load_data('isotopes') as $row) {
        if (isset($row['cures']) && is_array($row['cures'])) $options[(string) $row['api_code']] = $row['cures'];
    }
    return $options;
}

// ---------------------------------------------------------------------------------------------------------------------
// Champs de saisie (data/fields.json ; guide complet : data/FIELDS.md)
// ---------------------------------------------------------------------------------------------------------------------
// Les champs dont la saisie dépend de l'isotope (débit de dose, activité, fixation, période...) sont DÉCRITS dans le fichier de
// données : code de l'API, libellé, unité, type, bornes, aide. Le moteur les expose aux interfaces (input_fields() ; l'API publie la liste dans GET config, clé « fields »)
// pour qu'elles construisent leur formulaire ; un champ ajouté au fichier apparaît donc sur les sites sans toucher à leur code.
// C'est le profil de calcul de l'isotope (profiles.php) qui dit, champ par champ, s'il est obligatoire, facultatif ou ignoré.
//
// Champs d'une entrée :
//   code      nom du champ dans l'API (minuscules, chiffres, _) ; différent des champs communs (isotope_code, patient_size_cm...)
//   label     libellé par défaut (chaque site peut afficher le sien)
//   unit      (facultatif) unité affichée à côté du champ, ex. "MBq"
//   type      "number" (nombre) ou "choice" (liste de choix : l'entrée « options » est alors obligatoire)
//   min, max  (facultatifs, nombres) bornes proposées par l'interface ; le moteur ne les contrôle pas lui-même : c'est le profil
//             qui valide ses champs (voir profiles.php)
//   step      (facultatif) pas de saisie : un nombre, ou "any"
//   help      (facultatif) phrase d'aide affichée avec le champ
//   options   (type "choice" seulement) liste de {"value": "...", "label": "..."}
//   common    (facultatif, true/false) true = champ COMMUN à tous les isotopes, contrôlé par le moteur lui-même (aujourd'hui seulement
//             patient_size_cm : toujours obligatoire). Un champ commun est décrit ici pour que les sites en lisent le libellé, l'unité et
//             les bornes, mais il n'apparaît pas dans isotope_inputs et les profils ne le citent pas.
// Les champs de base (dose_rate, benign_activity_mbq, benign_fixation_pct, user_period_days) et le champ commun patient_size_cm sont utilisés
// par le moteur : ne pas les retirer.

const BASE_FIELDS = ['dose_rate', 'benign_activity_mbq', 'benign_fixation_pct', 'user_period_days'];
// Champs communs à tous les isotopes que le moteur sait décrire dans ce fichier (marqués « common »).
const COMMON_FIELDS = ['patient_size_cm'];
// Noms déjà pris par les champs communs du calcul : un champ de saisie ne peut pas les reprendre (sauf, avec « common », ceux de COMMON_FIELDS).
const RESERVED_FIELD_CODES = ['isotope_code', 'patient_size_cm', 'cure_count', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit', 'provider'];

// Contrôle et normalise UNE entrée du fichier (mêmes règles que les scénarios : erreur explicite avec le numéro de l'entrée).
function read_field(array $row, int $position, array $knownCodes): array
{
    $where = "data/fields.json, champ n°{$position}";
    $fail = static function (string $message) use (&$where): void {
        throw new \RuntimeException("{$where} : {$message}");
    };

    $code = $row['code'] ?? null;
    if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1) $fail('code manquant ou invalide (minuscules, chiffres et _, commence par une lettre).');
    $common = ($row['common'] ?? false) === true;
    if ($common && !in_array($code, COMMON_FIELDS, true)) $fail('« common » est réservé aux champs communs que le moteur connaît (' . implode(', ', COMMON_FIELDS) . ').');
    if (!$common && in_array($code, RESERVED_FIELD_CODES, true)) $fail("« {$code} » est déjà un champ commun du calcul.");
    if (isset($knownCodes[$code])) $fail("code « {$code} » en double.");
    $where .= " ({$code})";

    if (!is_string($row['label'] ?? null) || trim($row['label']) === '') $fail('label manquant.');
    $type = $row['type'] ?? null;
    if ($type !== 'number' && $type !== 'choice') $fail('type doit être "number" ou "choice".');
    foreach (['min', 'max'] as $bound) {
        if (isset($row[$bound]) && !is_numeric($row[$bound])) $fail("{$bound} doit être un nombre.");
    }
    if (isset($row['step']) && $row['step'] !== 'any' && !(is_numeric($row['step']) && $row['step'] > 0)) $fail('step doit être un nombre > 0 ou "any".');

    $options = null;
    if ($type === 'choice') {
        if (!is_array($row['options'] ?? null) || !$row['options']) $fail('un champ « choice » exige une liste « options » non vide.');
        $options = [];
        foreach ($row['options'] as $option) {
            if (!is_string($option['value'] ?? null) || $option['value'] === '' || !is_string($option['label'] ?? null) || $option['label'] === '') {
                $fail('chaque option exige value et label (textes non vides).');
            }
            $options[] = ['value' => $option['value'], 'label' => $option['label']];
        }
    }

    return [
        'code' => $code,
        'label' => $row['label'],
        'unit' => isset($row['unit']) ? (string) $row['unit'] : '',
        'type' => $type,
        'min' => isset($row['min']) ? $row['min'] + 0 : null,
        'max' => isset($row['max']) ? $row['max'] + 0 : null,
        'step' => $row['step'] ?? null,
        'help' => isset($row['help']) ? (string) $row['help'] : '',
        'options' => $options,
        'common' => $common,
    ];
}

// Champs de saisie normalisés, dans l'ordre du fichier. Les quatre champs de base doivent y figurer.
function input_fields(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $fields = [];
    $knownCodes = [];   // code → true, pour repérer les doublons
    foreach (load_data('fields') as $index => $row) {
        $field = read_field($row, $index + 1, $knownCodes);
        $knownCodes[$field['code']] = true;
        $fields[] = $field;
    }
    foreach ([...BASE_FIELDS, ...COMMON_FIELDS] as $code) {
        if (!isset($knownCodes[$code])) throw new \RuntimeException("data/fields.json : le champ de base « {$code} » est obligatoire.");
    }
    foreach ($fields as $field) {
        if (in_array($field['code'], COMMON_FIELDS, true) && !$field['common']) {
            throw new \RuntimeException("data/fields.json : le champ « {$field['code']} » doit porter \"common\": true.");
        }
    }
    return $cache = $fields;
}

// ---------------------------------------------------------------------------------------------------------------------
// Scénarios d'exposition (data/scenarios.json ; guide complet : data/SCENARIOS.md)
// ---------------------------------------------------------------------------------------------------------------------
// Un scénario = une situation de la vie quotidienne de l'entourage (conjoint, enfant, collègues, transports...) pour laquelle
// le moteur calcule UNE durée de restriction. Chaque scénario déclaré dans le fichier produit automatiquement une ligne dans
// les résultats (rows, recommendations_days), donc une ligne dans le tableau de tous les sites et interfaces qui affichent
// ces lignes : ajouter ou modifier un scénario = modifier le JSON, sans toucher au code.
//
// Champs d'un scénario :
//   audience_code               identifiant stable (minuscules, chiffres, _), clé de la ligne dans les résultats
//   label                       libellé affiché (qui est exposé et dans quelles circonstances)
//   exposures[]                 une ou plusieurs périodes d'exposition :
//       hours                     durée d'exposition par jour (h)
//       distance                  distance au patient (m)
//       unit_factor_at_1m         true = à exactement 1 m, le facteur géométrique vaut 1 (référence) ; false = calculé
//       note                      (facultatif) précision entre parenthèses dans le texte des conditions, ex. "nuit"
//   limit                       limite de dose du scénario (mSv)
//   shares_limit_across_cures   (facultatif, true par défaut) true = la même personne cumule les doses cure après cure : la
//                               limite est divisée par le nombre de cures ; false = chaque cure expose d'autres personnes
//                               (ex. passagers de transports en commun) : limite non divisée
//   condition                   (facultatif) texte des conditions ; par défaut GÉNÉRÉ à partir de exposures et limit, pour
//                               ne jamais diverger des valeurs réellement calculées
//
// Le scénario réservé "scenario_utilisateur" (scénario personnalisé saisi par l'utilisateur) est géré par calculate() :
// ne pas le déclarer dans le fichier.

// Nombre au format français, sans zéro inutile : 0.3 → "0,3", 8 → "8".
function scenario_number(float $value): string
{
    return str_replace('.', ',', js_number_to_string($value));
}

// Texte des conditions : "Exposition : 8 h à 0,3 m (nuit) + 3 h à 1 m · limite de dose : 15 mSv".
function scenario_condition(array $exposures, float $limit): string
{
    $parts = array_map(static function (array $e): string {
        $note = ($e['note'] ?? '') !== '' ? ' (' . $e['note'] . ')' : '';
        return scenario_number($e['hours']) . ' h à ' . scenario_number($e['distance']) . ' m' . $note;
    }, $exposures);
    return 'Exposition : ' . implode(' + ', $parts) . ' · limite de dose : ' . scenario_number($limit) . ' mSv';
}

// Contrôle et normalise UN scénario du fichier. `$position` (1, 2, ...) et le code servent à localiser l'erreur : une
// entrée invalide lève une exception explicite plutôt que de produire des durées fausses.
function read_scenario(array $row, int $position, array $knownCodes): array
{
    $where = "data/scenarios.json, scénario n°{$position}";
    $fail = static function (string $message) use (&$where): void {
        throw new \RuntimeException("{$where} : {$message}"); // `$where` s'enrichit du code du scénario dès qu'il est connu
    };

    $code = $row['audience_code'] ?? null;
    if (!is_string($code) || preg_match('/^[a-z0-9_]+$/', $code) !== 1) $fail('audience_code manquant ou invalide (minuscules, chiffres et _).');
    if ($code === 'scenario_utilisateur') $fail('« scenario_utilisateur » est réservé au scénario personnalisé.');
    if (isset($knownCodes[$code])) $fail("audience_code « {$code} » en double.");
    $where .= " ({$code})";

    if (!is_string($row['label'] ?? null) || trim($row['label']) === '') $fail('label manquant.');
    if (!is_array($row['exposures'] ?? null) || !$row['exposures']) $fail('au moins une exposition est requise.');
    if (!is_numeric($row['limit'] ?? null) || !($row['limit'] > 0)) $fail('limit doit être un nombre > 0 (mSv).');

    $exposures = [];
    foreach ($row['exposures'] as $e) {
        if (!is_numeric($e['hours'] ?? null) || !($e['hours'] > 0) || !is_numeric($e['distance'] ?? null) || !($e['distance'] > 0)) {
            $fail('chaque exposition exige hours > 0 et distance > 0.');
        }
        $exposures[] = [
            'hours' => (float) $e['hours'],
            'distance' => (float) $e['distance'],
            'unit_factor_at_1m' => (bool) ($e['unit_factor_at_1m'] ?? false),
            'note' => isset($e['note']) ? (string) $e['note'] : '',
        ];
    }

    return [
        'audience_code' => $code,
        'label' => $row['label'],
        'exposures' => $exposures,
        'limit' => $row['limit'],
        'shares_limit_across_cures' => (bool) ($row['shares_limit_across_cures'] ?? true),
        'condition' => is_string($row['condition'] ?? null) ? $row['condition'] : scenario_condition($exposures, (float) $row['limit']),
    ];
}

// Scénarios normalisés, dans l'ordre du fichier. Le fichier doit en contenir au moins un.
function scenarios(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $scenarios = [];
    $knownCodes = [];   // code → true, pour repérer les doublons
    foreach (load_data('scenarios') as $index => $row) {
        $scenario = read_scenario($row, $index + 1, $knownCodes);
        $knownCodes[$scenario['audience_code']] = true;
        $scenarios[] = $scenario;
    }
    if (!$scenarios) throw new \RuntimeException('data/scenarios.json : aucun scénario déclaré.');
    return $cache = $scenarios;
}
