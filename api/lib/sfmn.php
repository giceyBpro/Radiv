<?php
// Mode "sfmn": calcul délégué à l'outil SFMN distant (scraping du formulaire). Port de
// Calcul SFMN distant: mêmes étapes, mêmes codes d'erreur, mêmes formes de réponse.
// Désactivable entièrement par SFMN_MODE_ENABLED=false (voir Config\sfmn_mode_enabled).
declare(strict_types=1);

namespace Radiv\Sfmn;

use Radiv\Calculation;
use Radiv\Config;
use Radiv\Http;

function empty_recommendations(): array
{
    return [
        'conjoint_plus_60' => null, 'conjoint_moins_60' => null, 'conjointe_enceinte' => null,
        'transport_commun' => null, 'enfant_moins_3_ans' => null, 'enfant_3_11_ans' => null,
        'collegues_travail' => null, 'scenario_utilisateur' => null,
    ];
}

// String(value) côté JS pour une valeur issue d'un JSON (null/absent traités par l'appelant).
function js_string(mixed $value): string
{
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_int($value)) return (string) $value;
    if (is_float($value)) return Calculation\js_number_to_string($value);
    return is_string($value) ? $value : '';
}

function decode_html(?string $value): string
{
    $v = (string) $value;
    $v = str_replace(['&nbsp;', '&egrave;', '&eacute;', '&ecirc;', '&agrave;', '&ocirc;', '&icirc;', '&uuml;', '&amp;', '&#160;'],
        [' ', 'è', 'é', 'ê', 'à', 'ô', 'î', 'ü', '&', ' '], $v);
    $v = preg_replace('/<[^>]*>/u', ' ', $v) ?? $v;
    $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
    return trim($v);
}

// Minuscules + suppression des accents (équivalent de toLowerCase().normalize('NFD') sans les
// diacritiques), sans dépendre de l'extension intl.
function normalize_text(?string $value): string
{
    $v = function_exists('mb_strtolower') ? mb_strtolower(decode_html($value)) : strtolower(decode_html($value));
    $v = strtr($v, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ]);
    return trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
}

// Un décodage tolérant remplace les octets invalides par U+FFFD; sans cela les regex /u de PHP
// échouent entièrement sur une page contenant un octet non UTF-8.
function scrub_utf8(string $html): string
{
    return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'UTF-8');
}

function parse_number(?string $value): ?float
{
    if (!preg_match('/([0-9]+(?:[.,][0-9]+)?)/', (string) $value, $m)) return null;
    $n = (float) str_replace(',', '.', $m[1]);
    return is_finite($n) ? $n : null;
}

function parse_response(string $html): array
{
    $recommendations = empty_recommendations();
    $fallbackOrder = ['conjoint_plus_60', 'conjoint_moins_60', 'conjointe_enceinte', 'transport_commun', 'enfant_moins_3_ans', 'enfant_3_11_ans', 'collegues_travail'];
    $labelMap = [
        'contact avec le (la) conjoint(e) > 60 ans' => 'conjoint_plus_60',
        'contact avec le (la) conjoint(e) < 60 ans' => 'conjoint_moins_60',
        'contact avec la conjointe enceinte' => 'conjointe_enceinte',
        'transport en commun' => 'transport_commun',
        'contact avec un enfant (<3 ans) au retour a la maison' => 'enfant_moins_3_ans',
        'contact avec un enfant (entre 3 et 11 ans) au retour a la maison' => 'enfant_3_11_ans',
        'contact avec des collegues de travail' => 'collegues_travail',
        'contact with spouse > 60 years old' => 'conjoint_plus_60',
        'contact with spouse < 60 years old' => 'conjoint_moins_60',
        'contact with pregnant spouse' => 'conjointe_enceinte',
        'public transportation' => 'transport_commun',
        'contact with child (<3 years old) when back home' => 'enfant_moins_3_ans',
        'contact with child (between 3 and 11 years old) when back home' => 'enfant_3_11_ans',
        'contact with colleagues at work' => 'collegues_travail',
    ];
    $rows = [];
    $fallbackIndex = 0;
    preg_match_all('/<tr>([\s\S]*?)<\/tr>/iu', $html, $trMatches);
    foreach ($trMatches[1] as $tr) {
        preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/iu', $tr, $tdMatches);
        $cols = array_map('Radiv\Sfmn\decode_html', $tdMatches[1]);
        if (count($cols) < 2) continue;
        if (str_contains(normalize_text($cols[0]), "cas d'exemple")) continue;
        $code = $labelMap[normalize_text($cols[0])] ?? null;
        $periodDays = parse_number($cols[1]);
        if (!$code && $periodDays !== null && $fallbackIndex < count($fallbackOrder)) {
            $code = $fallbackOrder[$fallbackIndex];
        }
        if ($code && $periodDays !== null) $recommendations[$code] = $periodDays;
        if ($periodDays !== null) $fallbackIndex++;
        $rows[] = [
            'audience_code' => $code ?: null,
            'label' => $cols[0],
            'value' => $periodDays,
            // `cols[n] || '-'` côté JS: seule la chaîne vide est "fausse" (pas "0" comme en PHP).
            'condition' => ($cols[2] ?? '') === '' ? '-' : $cols[2],
            'limit' => ($cols[3] ?? '') === '' ? '-' : $cols[3],
        ];
    }
    $periodMatch = preg_match('/Période effective imposée:\s*([0-9.,]+)\s*heures\s*=\s*([0-9.,]+)\s*jours/iu', $html, $pm) ? $pm : null;
    $doseMatch = preg_match('/Débit de dose à 1m en sortie de chambre:\s*([0-9.,]+)/iu', $html, $dm) ? $dm : null;
    $curesMatch = preg_match('/Nb cures:\s*([0-9]+)/iu', $html, $cm) ? $cm : null;
    return [
        'recommendations' => $recommendations,
        'rows' => $rows,
        'effectiveHours' => $periodMatch ? parse_number($periodMatch[1]) : null,
        'effectiveDays' => $periodMatch ? parse_number($periodMatch[2]) : null,
        'computedDoseRate' => $doseMatch ? parse_number($dm[1]) : null,
        'parsedCureCount' => $curesMatch ? parse_number($cm[1]) : 1,
    ];
}

function fail(array $selected, array $errors, array $error, bool $debug, array $sfmnDebug): array
{
    $result = [
        'ok' => false,
        'calculation_mode' => 'sfmn',
        'selected' => $selected,
        'errors' => $errors,
        'error' => $error,
        'recommendations_days' => empty_recommendations(),
    ];
    return $debug ? $result + ['sfmn_debug' => $sfmnDebug] : $result;
}

// $opts (réservé à l'administration, jamais issu d'une requête publique) :
//   'force' => true  : calcule même si SFMN_MODE_ENABLED=false ;
//   'debug' => true  : joint toujours sfmn_debug, sans tenir compte de SFMN_DEBUG.
function calculate(array $payload, array $opts = []): array
{
    try {
        return calculate_inner($payload, $opts);
    } catch (\Throwable $e) {
        error_log('[SFMN] exception: ' . $e->getMessage());
        $debug = !empty($opts['debug']) || strtolower(Config\env('SFMN_DEBUG', 'false')) === 'true';
        $result = [
            'ok' => false,
            'calculation_mode' => 'sfmn',
            'selected' => Calculation\get_isotope($payload['isotope_code'] ?? null),
            'errors' => ['Échec réseau vers SFMN.'],
            'error' => ['code' => 'SFMN_NETWORK_ERROR', 'message' => 'Impossible de joindre/traiter la réponse SFMN.'],
            'recommendations_days' => empty_recommendations(),
        ];
        return $debug ? $result + ['sfmn_debug' => [
            'enabled' => true,
            'requests' => [['step' => 'sfmn_exception', 'error' => 'exception_during_remote_call']],
            'parsing' => (object) [],
        ]] : $result;
    }
}

function calculate_inner(array $payload, array $opts = []): array
{
    $isotopeCode = $payload['isotope_code'] ?? null;
    $selected = Calculation\get_isotope($isotopeCode);
    $validCodes = array_column(Calculation\isotopes(), 'api_code');
    // Même défense qu'en mode local: get_isotope() retombe silencieusement sur le premier
    // isotope si isotope_code est inconnu — sans ce contrôle, le calcul distant
    // interrogerait le SFMN pour le mauvais isotope sans le signaler.
    if (!is_string($isotopeCode) || !in_array($isotopeCode, $validCodes, true)) {
        $shown = Calculation\js_json_stringify($isotopeCode);
        return [
            'ok' => false,
            'calculation_mode' => 'sfmn',
            'selected' => $selected,
            'errors' => ["isotope_code inconnu ou manquant : {$shown}. Codes valides : " . implode(', ', $validCodes) . '.'],
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => "isotope_code inconnu ou manquant : {$shown}.",
                'reason' => 'Codes valides : ' . implode(', ', $validCodes) . '.',
            ],
            'recommendations_days' => empty_recommendations(),
        ];
    }
    // Même défense pour cure_count: sans validation explicite, un cure_count hors liste
    // retomberait silencieusement sur le mapping SFMN "1 cure" au lieu d'être rejeté.
    $cure = Calculation\normalize_cure_count($selected, $payload['cure_count'] ?? null);
    if (!$cure['valid']) {
        $allowed = implode(', ', $cure['allowed']);
        return [
            'ok' => false,
            'calculation_mode' => 'sfmn',
            'selected' => $selected,
            'errors' => ["Pour {$selected['api_code']}, cure_count doit être l'une des valeurs suivantes : {$allowed}."],
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => "cure_count invalide pour isotope_code={$selected['api_code']}.",
                'reason' => "Valeurs valides : {$allowed}.",
            ],
            'recommendations_days' => empty_recommendations(),
        ];
    }
    if (!Config\sfmn_mode_enabled() && empty($opts['force'])) {
        return [
            'ok' => false,
            'calculation_mode' => 'sfmn',
            'selected' => $selected,
            'errors' => ['Mode SFMN désactivé sur ce déploiement.'],
            'error' => ['code' => 'SFMN_MODE_DISABLED', 'message' => 'SFMN_MODE_ENABLED=false côté serveur.'],
            'recommendations_days' => empty_recommendations(),
        ];
    }
    // Le mode debug expose le HTML distant complet et les champs cachés du formulaire SFMN
    // (jeton CSRF compris): il ne dépend que de la configuration serveur, jamais du payload.
    $debug = !empty($opts['debug']) || strtolower(Config\env('SFMN_DEBUG', 'false')) === 'true';
    $sfmnDebug = ['enabled' => $debug, 'requests' => [], 'parsing' => (object) []];
    $parsing = [];
    $url = Config\env('SFMN_CALCULATOR_URL');
    if ($url === '') {
        return fail($selected, ['Mode SFMN indisponible: URL distante non configurée.'],
            ['code' => 'SFMN_URL_NOT_CONFIGURED', 'message' => 'SFMN_CALCULATOR_URL est vide côté backend.'], $debug, $sfmnDebug);
    }
    $map = [
        'iode131_0_fixation' => 'Iodine-131-0%-uptake',
        'iode131_5_fixation' => 'Iodine-131-5%-uptake',
        'iode131_25_fixation' => 'Iodine-131-25%-uptake',
        'iode131_benin' => 'Iodine-131-Benign disease',
        'psma_177lu' => $cure['value'] === 6 ? 'PSMA-177Lu 6 cures' : ($cure['value'] === 4 ? 'PSMA-177Lu 4 cures' : 'PSMA-177Lu'),
        'radium223' => 'Radium-223',
        'lutetium177_net' => $cure['value'] === 4 ? 'NET 177Lu 4 cures' : 'NET 177Lu',
        'microspheres_90y' => 'Microspheres-90Y',
        'microspheres_166ho' => 'Microsphères-166Ho',
        'lipiodol_131i' => 'Lipiodol-131I',
        'mibg_131i' => 'MIBG 131I',
        'synovectomie_90y' => 'Synovectomy-90Y',
        'synovectomie_186re' => 'Synovectomy-186Re',
        'synovectomie_169er' => 'Synovectomy-169Er',
    ];
    $radiopharmaceutical = $map[$selected['api_code']] ?? null;
    if (!$radiopharmaceutical) {
        return fail($selected, ["Isotope non supporté par le mapping SFMN: {$selected['api_code']}"],
            ['code' => 'SFMN_UNSUPPORTED_ISOTOPE', 'message' => "Aucun mapping SFMN pour isotope_code={$selected['api_code']}"], $debug, $sfmnDebug);
    }

    $num = static fn (string $k) => Calculation\to_number_or_null($payload[$k] ?? null);
    $userPeriod = $num('user_period_days');
    $userH1 = $num('user_hours_1');
    $userD1 = $num('user_distance_1');
    $userH2 = $num('user_hours_2');
    $userLimit = $num('user_limit');
    $userComplete = $userH1 !== null && $userD1 !== null && $userH2 !== null && $userLimit !== null;
    $useScenarioAdapted = $userPeriod !== null || $userComplete;
    $benignActivity = $num('benign_activity_mbq');
    $benignFixation = $num('benign_fixation_pct');
    $pathology = 'nodule_hot_measured';
    $measuredEstimated = '0';
    $benignUptake = '';
    $isBenign = $selected['api_code'] === 'iode131_benin';
    if ($isBenign && $benignFixation !== null) {
        $measuredEstimated = '1';
        $benignUptake = Calculation\js_number_to_string($benignFixation);
        if (abs($benignFixation - 25) < 0.001) $pathology = 'mng_measured';
        elseif (abs($benignFixation - 30) < 0.001) $pathology = 'graves_measured';
        else $pathology = 'nodule_hot_measured';
    }
    $str = static fn (?float $v, string $default = '') => $v === null ? $default : Calculation\js_number_to_string($v);
    $rawStr = static fn (string $k) => isset($payload[$k]) ? js_string($payload[$k]) : '';

    $ua = [
        'User-Agent: Mozilla/5.0 (compatible; RadioprotectionBot/1.0; +https://example.org)',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
    ];
    $finish = static function (array $result) use ($debug, &$sfmnDebug, &$parsing): array {
        if (!$debug) return $result;
        $sfmnDebug['parsing'] = (object) $parsing;
        return $result + ['sfmn_debug' => $sfmnDebug];
    };
    // $debug étant fixé à true seulement par la config serveur, l'ordre des clés de
    // sfmn_debug.parsing suit l'ordre d'insertion.
    $fail = static fn (array $errors, array $error) => $finish([
        'ok' => false, 'calculation_mode' => 'sfmn', 'selected' => $selected,
        'errors' => $errors, 'error' => $error, 'recommendations_days' => empty_recommendations(),
    ]);

    $root = Http\fetch($url, ['headers' => $ua, 'timeout_ms' => 10000]);
    if (!$root) return $fail(['Échec réseau vers SFMN.'], ['code' => 'SFMN_NETWORK_ERROR', 'message' => 'Impossible de joindre/traiter la réponse SFMN.']);
    if ($debug) $sfmnDebug['requests'][] = ['step' => 'sfmn_root_get', 'method' => 'GET', 'url' => $url, 'status' => $root['status']];
    if ($root['status'] < 200 || $root['status'] > 299) {
        return $fail(["SFMN inaccessible (GET {$root['status']})."],
            ['code' => 'SFMN_FETCH_FAILED', 'message' => "GET SFMN a retourné {$root['status']}"]);
    }
    $rootHtml = scrub_utf8($root['body']);
    $cookies = [];
    foreach ($root['headers'] as $h) {
        if (stripos($h, 'set-cookie:') === 0) {
            $first = trim(explode(';', trim(substr($h, 11)))[0]);
            if ($first !== '') $cookies[] = $first;
        }
    }
    $cookieHeader = implode('; ', $cookies);

    $options = [];
    if (preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([\s\S]*?)<\/option>/iu', $rootHtml, $om, PREG_SET_ORDER)) {
        foreach ($om as $m) {
            $o = ['value' => decode_html($m[1]), 'text' => decode_html($m[2])];
            if ($o['value'] !== '' && $o['value'] !== '—' && $o['value'] !== '-') $options[] = $o;
        }
    }
    $hidden = [];
    if (preg_match_all('/<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"[^>]*>/iu', $rootHtml, $hm, PREG_SET_ORDER)) {
        foreach ($hm as $m) $hidden[decode_html($m[1])] = decode_html($m[2]);
    }
    if ($options) {
        $hasMapped = (bool) array_filter($options, static fn ($o) => $o['value'] === $radiopharmaceutical);
        if (!$hasMapped) {
            $target = normalize_text($selected['label'] ?? '');
            foreach ($options as $o) {
                if (str_contains(normalize_text($o['text']), $target)) { $radiopharmaceutical = $o['value']; break; }
            }
        }
    }
    if ($debug) {
        $parsing['root_html'] = $rootHtml;
        $parsing['form_page_html'] = $rootHtml;
        $parsing['radiopharmaceutical_options'] = $options;
        $parsing['selected_radiopharmaceutical'] = $radiopharmaceutical;
        $parsing['cookies_forwarded'] = array_map(static fn ($c) => explode('=', $c)[0], $cookies);
        $parsing['hidden_fields'] = $hidden ?: (object) [];
    }
    if ($options && !array_filter($options, static fn ($o) => $o['value'] === $radiopharmaceutical)) {
        return $fail(['Le radiopharmaceutique demandé n’est pas reconnu par le formulaire SFMN distant.'],
            ['code' => 'SFMN_RADIOPHARMACEUTICAL_MISMATCH', 'message' => "Valeur non trouvée dans les options SFMN: {$radiopharmaceutical}"]);
    }
    $hasAction = preg_match('/<form[^>]*action="([^"]*option=com_evictionperiod[^"]*task=process[^"]*)"/iu', $rootHtml, $am);
    $hasCsrf = preg_match('/<input[^>]*type="hidden"[^>]*name="([a-f0-9]{32})"[^>]*value="1"/iu', $rootHtml, $cm);
    if (!$hasAction || !$hasCsrf) {
        return $fail(['Impossible d’extraire action/token CSRF du formulaire SFMN.'],
            ['code' => 'SFMN_PARSE_FORM_FAILED', 'message' => 'Action ou token CSRF introuvable.']);
    }
    $actionUrl = resolve_url($am[1], $url);

    $doseRate = $isBenign ? '' : $rawStr('dose_rate');
    $form = $hidden;
    $form['jform[radiopharmaceutical]'] = $radiopharmaceutical;
    $form['jform[dose_rate]'] = $doseRate;
    $form['jform[patient_size]'] = $rawStr('patient_size_cm');
    $form['jform[scenario_adapted_to_the_patient]'] = $useScenarioAdapted ? '1' : '0';
    $form['jform[effective_half_life]'] = $str($userPeriod, '0');
    $form['jform[duration_at_xm]'] = $str($userH1);
    $form['jform[distance_at_xm]'] = $str($userD1);
    $form['jform[duration_at_1m]'] = $str($userH2);
    $form['jform[distance_at_1m]'] = '1';
    $form['jform[dosimetric_constraint]'] = $str($userLimit);
    $form['jform[thyroide]'] = $isBenign ? '1' : '0';
    $form['jform[dysthyroidism_activity_administered]'] = $str($benignActivity);
    $form['jform[pathology]'] = $pathology;
    $form['jform[measured_estimated]'] = $measuredEstimated;
    $form['jform[dysthyroidism_iodine_uptake]'] = $benignUptake;
    $form['jform[dysthyroidism_iodine_uptake_measured]'] = $benignUptake;
    $form['jform[dysthyroidism_iodine_uptake_estimated]'] = $benignUptake;
    $form['boxchecked'] = '0';
    $form[$cm[1]] = '1';
    if ($debug) {
        $sfmnDebug['requests'][] = [
            'step' => 'prepare_post_body',
            'url' => null,
            'payload' => [
                'radiopharmaceutical' => $radiopharmaceutical,
                'dose_rate' => $doseRate,
                'patient_size' => $rawStr('patient_size_cm'),
                'scenario_adapted_to_the_patient' => $useScenarioAdapted ? '1' : '0',
                'effective_half_life' => $str($userPeriod, '0'),
                'duration_at_xm' => $str($userH1),
                'distance_at_xm' => $str($userD1),
                'duration_at_1m' => $str($userH2),
                'distance_at_1m' => '1',
                'dosimetric_constraint' => $str($userLimit),
                'thyroide' => $isBenign ? '1' : '0',
                'dysthyroidism_activity_administered' => $str($benignActivity),
                'pathology' => $pathology,
                'measured_estimated' => $measuredEstimated,
                'dysthyroidism_iodine_uptake' => $benignUptake,
                'dysthyroidism_iodine_uptake_measured' => $benignUptake,
                'dysthyroidism_iodine_uptake_estimated' => $benignUptake,
                'csrf_name' => $cm[1],
                'hidden_forwarded' => $hidden ?: (object) [],
            ],
        ];
    }
    $origin = (string) (parse_url($url, PHP_URL_SCHEME) ?? 'https') . '://' . (string) parse_url($url, PHP_URL_HOST)
        . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : '');
    $headers = array_merge($ua, [
        'Content-Type: application/x-www-form-urlencoded',
        "Referer: {$url}",
        "Origin: {$origin}",
        'Upgrade-Insecure-Requests: 1',
    ], $cookieHeader !== '' ? ["Cookie: {$cookieHeader}"] : []);
    $post = Http\fetch($actionUrl, [
        'method' => 'POST',
        'headers' => $headers,
        'body' => http_build_query($form, '', '&', PHP_QUERY_RFC1738),
        'timeout_ms' => 8000,
    ]);
    if (!$post) return $fail(['Échec réseau vers SFMN.'], ['code' => 'SFMN_NETWORK_ERROR', 'message' => 'Impossible de joindre/traiter la réponse SFMN.']);
    if ($debug) {
        $sfmnDebug['requests'][] = [
            'step' => 'sfmn_process_post', 'method' => 'POST', 'url' => $actionUrl,
            'status' => $post['status'], 'redirected' => $post['redirected'], 'final_url' => $post['final_url'] ?: null,
        ];
    }
    if ($post['status'] < 200 || $post['status'] > 299) {
        return $fail(["SFMN task=process inaccessible (POST {$post['status']})."],
            ['code' => 'SFMN_PROCESS_FAILED', 'message' => "POST SFMN a retourné {$post['status']}"]);
    }
    $resultHtml = scrub_utf8($post['body']);
    if ($debug) {
        $excerpt = mb_substr(ltrim($resultHtml), 0, 2000);
        $parsing['result_html_excerpt'] = $excerpt;
        $parsing['result_html'] = $resultHtml;
        $parsing['response_html_excerpt'] = $excerpt;
        $parsing['response_html'] = $resultHtml;
    }
    $parsed = parse_response($resultHtml);
    if ($debug) {
        $parsing['parsed_summary'] = [
            'parsed_rows_count' => count($parsed['rows']),
            'effective_days' => $parsed['effectiveDays'],
            'effective_hours' => $parsed['effectiveHours'],
            'computed_dose_rate' => $parsed['computedDoseRate'],
        ];
    }
    return $finish([
        'ok' => true,
        'calculation_mode' => 'sfmn',
        'selected' => $selected,
        'cure_count' => $parsed['parsedCureCount'] ?: 1,
        'cure_count_allowed' => $cure['allowed'],
        'computed_dose_rate' => $parsed['computedDoseRate'],
        'effective_days' => $parsed['effectiveDays'],
        'effective_hours' => $parsed['effectiveHours'],
        'errors' => [],
        'recommendations_days' => $parsed['recommendations'],
        // Pas de "rows" ici (contrairement au mode local): ce que renvoie le scraping de la page
        // SFMN distante n'a ni forme garantie ni fiabilité comparable; le détail n'est exposé
        // qu'en mode debug. En usage normal, seule l'URL reste (attribution de la source).
        'sfmn_source' => ['url' => $url] + ($debug ? ['parsed_rows' => $parsed['rows']] : []),
    ]);
}

// new URL(relative, base).toString() pour les cas rencontrés (chemin absolu, relatif, complet).
function resolve_url(string $href, string $base): string
{
    if (preg_match('#^https?://#i', $href)) return $href;
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($href, '//')) return ($p['scheme'] ?? 'https') . ':' . $href;
    if (str_starts_with($href, '/')) return $origin . $href;
    $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    return $origin . $dir . $href;
}
