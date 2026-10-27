<?php
// ===== CATALOGUE : ISOTOPES, SCÉNARIOS D'EXPOSITION, CURES =====
// Les valeurs ne sont PAS dans le code mais dans data/*.json (à modifier là, sans toucher à PHP) :
//   data/isotopes.json   périodes effectives, références (voir data/SOURCES.md), profil de calcul (champ facultatif « profile »,
//                        voir profiles.php) et, pour les isotopes qui en acceptent plusieurs,
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

// ===== SCÉNARIOS D'EXPOSITION (data/scenarios.json ; guide complet : data/SCENARIOS.md) =====
// Un scénario = une situation de la vie quotidienne de l'entourage (conjoint, enfant, collègues, transports...) pour laquelle
// le moteur calcule UNE durée de restriction. Chaque scénario déclaré dans le fichier produit automatiquement une ligne dans
// les résultats (rows, recommendations_days) — et donc une ligne dans les tableaux de tous les sites et interfaces qui
// affichent ces lignes : ajouter ou modifier un scénario = modifier le JSON, sans toucher au code.
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
//   shares_limit_across_cures   (facultatif, true par défaut) true = la même personne cumule les doses cure après cure, la limite
//                               est donc divisée par le nombre de cures ; false = chaque cure expose d'autres personnes
//                               (ex. passagers de transports en commun) : limite non divisée
//   condition                   (facultatif) texte des conditions ; par défaut GÉNÉRÉ à partir de exposures et limit,
//                               pour ne jamais diverger des valeurs réellement calculées
//
// Le scénario réservé "scenario_utilisateur" (scénario personnalisé saisi par l'utilisateur) est géré par calculate() : ne pas
// le déclarer ici.

// Nombre au format français, sans zéro inutile : 0.3 → "0,3", 8 → "8".
function scenario_number(float $value): string
{
    return str_replace('.', ',', js_number_to_string($value));
}

// Texte des conditions : "Exposition : 8 h à 0,3 m (nuit) + 3 h à 1 m · limite de dose : 15 mSv".
function scenario_condition(array $exposures, float $limit): string
{
    $parts = array_map(static fn (array $e): string =>
        scenario_number($e['hours']) . ' h à ' . scenario_number($e['distance']) . ' m' . (($e['note'] ?? '') !== '' ? ' (' . $e['note'] . ')' : ''), $exposures);
    return 'Exposition : ' . implode(' + ', $parts) . ' · limite de dose : ' . scenario_number($limit) . ' mSv';
}

// Scénarios normalisés et contrôlés. Une erreur dans le fichier (champ manquant, doublon, valeur négative...) lève une exception
// explicite plutôt que de produire des durées fausses.
function scenarios(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $seen = [];
    $out = [];
    foreach (load_data('scenarios') as $i => $row) {
        $where = 'data/scenarios.json, scénario n°' . ($i + 1);
        $code = $row['audience_code'] ?? null;
        if (!is_string($code) || preg_match('/^[a-z0-9_]+$/', $code) !== 1) throw new \RuntimeException("{$where} : audience_code manquant ou invalide (minuscules, chiffres et _).");
        if ($code === 'scenario_utilisateur') throw new \RuntimeException("{$where} : « scenario_utilisateur » est réservé au scénario personnalisé.");
        if (isset($seen[$code])) throw new \RuntimeException("{$where} : audience_code « {$code} » en double.");
        $seen[$code] = true;
        $where .= " ({$code})";
        if (!is_string($row['label'] ?? null) || trim($row['label']) === '') throw new \RuntimeException("{$where} : label manquant.");
        if (!is_array($row['exposures'] ?? null) || !$row['exposures']) throw new \RuntimeException("{$where} : au moins une exposition est requise.");
        if (!is_numeric($row['limit'] ?? null) || !($row['limit'] > 0)) throw new \RuntimeException("{$where} : limit doit être un nombre > 0 (mSv).");
        $exposures = [];
        foreach ($row['exposures'] as $e) {
            if (!is_numeric($e['hours'] ?? null) || !($e['hours'] > 0) || !is_numeric($e['distance'] ?? null) || !($e['distance'] > 0)) {
                throw new \RuntimeException("{$where} : chaque exposition exige hours > 0 et distance > 0.");
            }
            $exposures[] = [
                'hours' => (float) $e['hours'],
                'distance' => (float) $e['distance'],
                'unit_factor_at_1m' => (bool) ($e['unit_factor_at_1m'] ?? false),
                'note' => isset($e['note']) ? (string) $e['note'] : '',
            ];
        }
        $out[] = [
            'audience_code' => $code,
            'label' => $row['label'],
            'exposures' => $exposures,
            'limit' => $row['limit'],
            'shares_limit_across_cures' => (bool) ($row['shares_limit_across_cures'] ?? true),
            'condition' => is_string($row['condition'] ?? null) ? $row['condition'] : scenario_condition($exposures, (float) $row['limit']),
        ];
    }
    if (!$out) throw new \RuntimeException('data/scenarios.json : aucun scénario déclaré.');
    return $cache = $out;
}

// Profil de calcul d'un isotope (champ facultatif « profile » de isotopes.json ; voir profiles.php). Absent = "standard".
// Volontairement absent de la réponse de isotopes() : il ne change pas la forme des résultats.
function isotope_profile(string $apiCode): string
{
    foreach (load_data('isotopes') as $row) {
        if (($row['api_code'] ?? null) === $apiCode) return (string) ($row['profile'] ?? 'standard');
    }
    return 'standard';
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
