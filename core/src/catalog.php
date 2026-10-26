<?php
// ===== CATALOGUE : ISOTOPES, SCÉNARIOS D'EXPOSITION, CURES =====
// Les valeurs ne sont PAS dans le code mais dans data/*.json (à modifier là, sans toucher à PHP) :
//   data/isotopes.json   périodes effectives, références (voir data/SOURCES.md) et, pour les isotopes qui en acceptent plusieurs,
//                        les nombres de cures autorisés (champ facultatif « cures », ex. [1, 4] ; absent = une seule cure)
//   data/scenarios.json  durées, distances et limites de dose par situation
// Un isotope donne soit "period_hours" (période en heures), soit "period_days" (période en jours, multipliée par 24),
// soit null (isotope « non défini », période à fournir par l'appelant).

declare(strict_types=1);

namespace RivEngine;

if (!defined('RIV_ENGINE')) { http_response_code(404); exit; } // jamais exécutable seul : passer par bootstrap.php

// Lecture d'un fichier de data/, mémorisée pour la durée de la requête.
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

// Version du moteur (fichier VERSION à la racine du moteur, format MAJEURE.MINEURE.CORRECTIF) : permet à un site de
// vérifier qu'il utilise une version compatible avec ce qu'il attend. "0.0.0" si le fichier est absent ou invalide.
function version(): string
{
    $raw = @file_get_contents(dirname(__DIR__) . '/VERSION');
    $v = $raw === false ? '' : trim($raw);
    return preg_match('/^\d+\.\d+\.\d+$/', $v) === 1 ? $v : '0.0.0';
}

// Isotopes : périodes effectives (en heures), références et libellés. Forme historique conservée (clé periodHours).
function isotopes(): array
{
    return array_map(static function (array $row): array {
        $hours = array_key_exists('period_days', $row) ? $row['period_days'] * 24
            : ($row['period_hours'] ?? null);
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

// Scénarios : paramètres d'exposition ligne par ligne (heures, distance, facteur 1 m spécifique).
function scenarios(): array
{
    return array_map(static function (array $row): array {
        $row['exposures'] = array_map(static fn (array $e): array => [
            'hours' => (float) $e['hours'],
            'distance' => (float) $e['distance'],
            'unit_factor_at_1m' => (bool) $e['unit_factor_at_1m'],
        ], $row['exposures']);
        return $row;
    }, load_data('scenarios'));
}

// Nombres de cures autorisés, pour les seuls isotopes qui déclarent un champ « cures » (ordre du fichier).
function cure_options_by_isotope(): array
{
    $options = [];
    foreach (load_data('isotopes') as $row) {
        if (isset($row['cures']) && is_array($row['cures'])) $options[(string) $row['api_code']] = $row['cures'];
    }
    return $options;
}

function get_isotope(mixed $apiCode): array
{
    $all = isotopes();
    foreach ($all as $isotope) {
        if ($isotope['api_code'] === $apiCode) return $isotope;
    }
    return $all[0];
}
