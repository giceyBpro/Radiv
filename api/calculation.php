<?php
// ===== MODULE DE CALCUL LOCAL — AUCUNE DÉPENDANCE AU RESTE DU BACKEND =====
// Port PHP de calculation.js. Ce fichier contient l'intégralité du calcul des durées de
// restriction (mode "local"): données sources, formalisme mathématique, validation et
// orchestration. Il ne dépend d'aucun autre fichier du projet ni d'aucun secret — il peut
// être lu, audité ou testé isolément. Seule dépendance externe: la variable d'environnement
// RESTRICTION_ROUNDING_MODE (voir plus bas), qui ne contient jamais de valeur sensible.
//
// Le contrat est volontairement identique à celui de calculation.js (mêmes données, mêmes
// formules, même JSON): un client externe (questionnaire RIS Xplore, scripts) ne doit voir
// aucune différence. Les écarts de sémantique PHP/JS qui pourraient le casser sont traités
// explicitement et commentés là où ils interviennent (arrondi, formatage des nombres,
// division par zéro, conversion Number()).
//
// Formalisme général, scénarios (durées/distances/limites) et tableau des demi-vies
// effectives harmonisés au niveau national par le groupe Radioprotection de la SFMN:
// Carlier T, Denizot B, Prevot-Bitot N, Nioche C, Courbon F, Cachin F. Harmonization of
// exposure constraints for relatives following targeted radionuclide therapy: a French
// perspective. Médecine Nucléaire 2026;50:131-136. DOI: 10.1016/j.mednuc.2026.03.002
// (reprend et prolonge le modèle de calcul de Carlier et al., Radioprotection 2004;39:481-92).
// Valeurs du Tableau 1 de cet article reprises telles quelles, SAUF mibg_131i: sa source
// primaire (Wafelman et al. 1995, vérifiée intégralement) contredit la valeur du Tableau 1 —
// voir le commentaire sur cette entrée ci-dessous.

declare(strict_types=1);

namespace Radiv\Calculation;

// "round" (défaut): arrondi au plus proche, légèrement plus protecteur (arrondit au jour
// supérieur dès que la fraction dépasse 0,5). "floor": troncature, reproduit exactement les
// valeurs de l'outil SFMN de référence (vérifié le 2026-09-02 sur les 7 scénarios, Radium-223).
// Lu à chaque appel (pas à l'inclusion du fichier): le bootstrap peut ainsi charger .env
// après coup, et les tests peuvent basculer de mode sans relancer le processus.
function rounding_mode(): string
{
    $raw = getenv('RESTRICTION_ROUNDING_MODE');
    $mode = strtolower(trim($raw === false || $raw === '' ? 'round' : $raw));
    if ($mode !== 'round' && $mode !== 'floor') {
        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log("RESTRICTION_ROUNDING_MODE invalide ({$mode}); fallback sur \"round\"");
        }
        return 'round';
    }
    return $mode;
}

// Équivalent exact de Math.round (arrondi vers +∞ à la demi-valeur). round() de PHP arrondit
// "half away from zero" et, avant PHP 8.4, applique un pré-arrondi qui fait diverger certains
// cas limites (ex. 2.4999999999999996 → 3 en PHP, 2 en JS). La différence x - floor(x) est
// exacte en virgule flottante, donc la comparaison à 0,5 l'est aussi.
function js_round(float $x): float
{
    if (!is_finite($x)) return $x;
    $floor = floor($x);
    return ($x - $floor >= 0.5) ? $floor + 1.0 : $floor;
}

function round_restriction_days(float $day): float
{
    return rounding_mode() === 'floor' ? floor($day) : js_round($day);
}

// 1) isotopes: périodes effectives, références et libellés métier utilisés dans le calcul
function isotopes(): array
{
    return [
        ['api_code' => 'iode131_0_fixation', 'label' => 'Iode-131-0%-fixation', 'periodHours' => 16, 'reference' => 'Carlier et al., Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', 'remark' => '-', 'situation' => 'Cancer opéré'],
        ['api_code' => 'iode131_5_fixation', 'label' => 'Iode-131-5%-fixation', 'periodHours' => 16, 'reference' => 'Carlier et al., Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', 'remark' => 'en considérant la période au niveau de la thyroïde', 'situation' => 'Cancer oligo-métastasé'],
        ['api_code' => 'iode131_25_fixation', 'label' => 'Iode-131-25%-fixation', 'periodHours' => 16, 'reference' => 'Carlier et al., Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', 'remark' => 'en considérant la période au niveau de la thyroïde', 'situation' => 'Cancer poly-métastasé'],
        ['api_code' => 'iode131_benin', 'label' => 'Iode-131-Bénin', 'periodHours' => 122.4, 'reference' => 'Carlier et al., Nuclear Medicine Communications 2006, 27:559–566', 'remark' => 'en considérant la période au niveau de la thyroïde', 'situation' => 'Pathologie bénigne'],
        ['api_code' => 'radium223', 'label' => 'Radium-223', 'periodHours' => 11.43 * 24, 'reference' => 'Période physique du radium-223', 'remark' => 'Demi vie physique', 'situation' => ''],
        ['api_code' => 'psma_177lu', 'label' => 'PSMA-177Lu', 'periodHours' => 40, 'reference' => 'Kratochwil et al., EANM procedure guidelines for radionuclide therapy with 177Lu-labelled PSMA-ligands 2019', 'remark' => 'Valeur la plus longue proposée', 'situation' => ''],
        ['api_code' => 'synovectomie_90y', 'label' => 'Synovectomie-90Y', 'periodHours' => 2.67 * 24, 'reference' => 'Clunie et al., EANM Procedure Guidelines for Radiosynovectomy 2003', 'remark' => 'Demi vie physique', 'situation' => ''],
        ['api_code' => 'synovectomie_186re', 'label' => 'Synovectomie-186Re', 'periodHours' => 3.7 * 24, 'reference' => 'Clunie et al., EANM Procedure Guidelines for Radiosynovectomy 2003', 'remark' => 'Demi vie physique', 'situation' => ''],
        ['api_code' => 'synovectomie_169er', 'label' => 'Synovectomie-169Er', 'periodHours' => 9.4 * 24, 'reference' => 'Clunie et al., EANM Procedure Guidelines for Radiosynovectomy 2003', 'remark' => 'Demi vie physique', 'situation' => ''],
        ['api_code' => 'microspheres_90y', 'label' => 'Microsphères-90Y', 'periodHours' => 64.05, 'reference' => 'Période physique de l’yttrium-90', 'remark' => 'Demi vie physique', 'situation' => ''],
        ['api_code' => 'microspheres_166ho', 'label' => 'Microsphères-166Ho', 'periodHours' => 26.81, 'reference' => 'Période physique de l’holmium-166', 'remark' => 'Demi vie physique', 'situation' => ''],
        ['api_code' => 'lipiodol_131i', 'label' => 'Lipiodol-131I', 'periodHours' => 8.04 * 24, 'reference' => 'Giammarile et al., EANM procedure guideline for the treatment of liver cancer and liver metastases with intra-arterial radioactive compounds 2011', 'remark' => 'Demi vie physique', 'situation' => ''],
        ['api_code' => 'lutetium177_net', 'label' => 'Lutétium-177 NET', 'periodHours' => 100, 'reference' => 'Fitschen et al, Z Med Phys 2011, Levart et al, EJNMMI Phys 2019', 'remark' => 'Demi vie effective', 'situation' => ''],
        // 10,6 h = t½,elim des enfants (n=6) dans Wafelman et al. 1995, valeur locale historique
        // restaurée. Le Tableau 1 de Carlier et al. 2026 cite la même source pour une valeur de
        // 30,60 h, qui n'apparaît nulle part dans le texte, les tableaux ou les résultats de cette
        // source primaire (plage complète des 11 patients avec t½,elim calculé: 9,1 à 14,3 h,
        // moyenne globale 11,5 h) — erreur identifiée dans le Tableau 1 de l'article 2026, à
        // signaler au groupe Radioprotection de la SFMN. Choix volontaire, à ne pas "corriger".
        ['api_code' => 'mibg_131i', 'label' => 'MIBG-131I', 'periodHours' => 10.6, 'reference' => 'Wafelman et al., Nucl. Med. Commun. 16 (1995) 767–772 (t½,elim enfants, n=6)', 'remark' => 'Le Tableau 1 de Carlier et al. 2026 indique 30,60 h pour cette même source — erreur, signalée à la SFMN', 'situation' => ''],
        ['api_code' => 'non_defini', 'label' => 'Non défini', 'periodHours' => null, 'reference' => '-', 'remark' => '-', 'situation' => ''],
    ];
}

// 2) scenarios: paramètres d'exposition ligne par ligne (heures, distance, facteur 1m spécifique)
// Vérifiés contre la Fig. 1 de Carlier et al. 2026 (voir ci-dessus): durées/distances/limites
// identiques pour les 6 scénarios lisibles sur la figure. Le scénario "collègues de travail" n'a
// pas pu être revérifié sur la figure (rendu de capture d'écran illisible pour cette ligne dans
// l'article) — valeur conservée du modèle 2004 (6 h à 1 m, limite 1 mSv), cohérente avec les
// 6 autres scénarios qui, eux, correspondent exactement.
function scenarios(): array
{
    $e = static fn (float $hours, float $distance, bool $unit): array => ['hours' => $hours, 'distance' => $distance, 'unit_factor_at_1m' => $unit];
    return [
        ['audience_code' => 'conjoint_plus_60', 'label' => 'Contact avec le (la) conjoint(e) > 60 ans', 'exposures' => [$e(8, 0.3, false), $e(3, 1, true)], 'limit' => 15, 'condition' => "8 h à 0,3 m et 3 h à 1 m,\nlimite 15 mSv"],
        ['audience_code' => 'conjoint_moins_60', 'label' => 'Contact avec le (la) conjoint(e) < 60 ans', 'exposures' => [$e(8, 0.3, false), $e(3, 1, true)], 'limit' => 3, 'condition' => "8 h à 0,3 m et 3 h à 1 m,\nlimite 3 mSv"],
        ['audience_code' => 'conjointe_enceinte', 'label' => 'Contact avec la conjointe enceinte', 'exposures' => [$e(8, 0.3, false), $e(3, 1, true)], 'limit' => 1, 'condition' => "8 h à 0,3 m et 3 h à 1 m,\nlimite 1 mSv"],
        ['audience_code' => 'transport_commun', 'label' => 'Transport en commun', 'exposures' => [$e(3, 0.5, false)], 'limit' => 1, 'condition' => "3 h à 0,5 m,\nlimite 1 mSv"],
        ['audience_code' => 'enfant_moins_3_ans', 'label' => 'Contact avec un enfant (<3 ans) au retour à la maison', 'exposures' => [$e(9, 1, true)], 'limit' => 1, 'condition' => "9 h à 1 m,\nlimite 1 mSv"],
        ['audience_code' => 'enfant_3_11_ans', 'label' => 'Contact avec un enfant (entre 3 et 11 ans) au retour à la maison', 'exposures' => [$e(2, 0.5, false), $e(2, 1, true)], 'limit' => 1, 'condition' => "2 h à 0,5 m et 2 h à 1 m,\nlimite 1 mSv"],
        // unit_factor_at_1m corrigé à true (2026-09-02): c'était la seule exposition à 1 m sur les
        // 6 du tableau à avoir false, ce qui appliquait par erreur le facteur géométrique complet
        // (~0,86 pour un patient de 150 cm) au lieu de la référence 1 à cette distance — écart de
        // 2 jours constaté par comparaison directe avec l'outil SFMN de référence (Radium-223,
        // 100 µSv/h, 150 cm: 38 j attendus, 36 j calculés avant ce correctif).
        ['audience_code' => 'collegues_travail', 'label' => 'Contact avec des collègues de travail', 'exposures' => [$e(6, 1, true)], 'limit' => 1, 'condition' => "6 h à 1 m,\nlimite 1 mSv"],
    ];
}

function cure_options_by_isotope(): array
{
    return [
        'radium223' => [1],
        'psma_177lu' => [1, 4, 6],
        'lutetium177_net' => [1, 4],
    ];
}

// Équivalent de Number(value) de JS suivi d'un contrôle isFinite, pour les valeurs que peut
// produire un JSON: '' / null / absent → null; booléens → 0/1; chaînes → syntaxe numérique
// JS (espaces tolérés, "  " = 0, hexa/octal/binaire préfixés, "Infinity" rejeté car non
// fini); tableaux/objets → null (cas pathologique sans usage réel, non reproduit).
function to_number_or_null(mixed $value): ?float
{
    if ($value === '' || $value === null) return null;
    if (is_bool($value)) return $value ? 1.0 : 0.0;
    if (is_int($value)) return (float) $value;
    if (is_float($value)) return is_finite($value) ? $value : null;
    if (!is_string($value)) return null;
    $s = trim($value, " \t\n\r\v\f\u{00A0}\u{FEFF}\u{2028}\u{2029}");
    if ($s === '') return 0.0;
    if (preg_match('/^0[xX][0-9a-fA-F]+$/', $s)) return (float) hexdec(substr($s, 2));
    if (preg_match('/^0[oO][0-7]+$/', $s)) return (float) octdec(substr($s, 2));
    if (preg_match('/^0[bB][01]+$/', $s)) return (float) bindec(substr($s, 2));
    if (!preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $s)) return null;
    $number = (float) $s;
    return is_finite($number) ? $number : null;
}

// Équivalent de la conversion implicite d'un nombre en chaîne dans un gabarit JS (`${x}`).
// Le formatage natif de PHP diffère (précision 14, exposants "1.0E-5"): on repart des
// chiffres significatifs les plus courts (ceux de json_encode, identiques à ceux de JS) et on
// applique l'algorithme Number::toString de la spec ECMAScript.
function js_number_to_string(float $x): string
{
    if (is_nan($x)) return 'NaN';
    if ($x === 0.0) return '0';
    if (is_infinite($x)) return $x > 0 ? 'Infinity' : '-Infinity';
    if ($x < 0) return '-' . js_number_to_string(-$x);
    $repr = (string) json_encode($x); // plus courte représentation qui se relit à l'identique
    $mantissa = $repr;
    $exp10 = 0;
    if (($ePos = stripos($repr, 'e')) !== false) {
        $mantissa = substr($repr, 0, $ePos);
        $exp10 = (int) substr($repr, $ePos + 1);
    }
    $dot = strpos($mantissa, '.');
    $intPart = $dot === false ? $mantissa : substr($mantissa, 0, $dot);
    $fracPart = $dot === false ? '' : substr($mantissa, $dot + 1);
    $digits = $intPart . $fracPart;
    $n = strlen($intPart) + $exp10; // position de la virgule par rapport au début des chiffres
    $stripped = ltrim($digits, '0');
    $n -= strlen($digits) - strlen($stripped);
    $digits = rtrim($stripped, '0');
    $k = strlen($digits);
    if ($k <= $n && $n <= 21) return $digits . str_repeat('0', $n - $k);
    if (0 < $n && $n <= 21) return substr($digits, 0, $n) . '.' . substr($digits, $n);
    if (-6 < $n && $n <= 0) return '0.' . str_repeat('0', -$n) . $digits;
    $e = $n - 1;
    $sign = $e < 0 ? '-' : '+';
    $expStr = $sign . abs($e);
    return $k === 1 ? $digits . 'e' . $expStr : $digits[0] . '.' . substr($digits, 1) . 'e' . $expStr;
}

function get_isotope(mixed $apiCode): array
{
    $all = isotopes();
    foreach ($all as $isotope) {
        if ($isotope['api_code'] === $apiCode) return $isotope;
    }
    return $all[0];
}

// 3) formules de calcul: décroissance, géométrie et calcul de durée de restriction
// fdiv() plutôt que "/": en JS une division par zéro donne Infinity/NaN (traités ensuite par
// les contrôles de finitude), alors que "/" lève DivisionByZeroError en PHP 8.
function decay_fraction(float $hours, float $effectiveDays): float
{
    return 1 - exp(-(fdiv(M_LN2, $effectiveDays) * ($hours / 24)));
}

function geometry_factor(float $distance, float $patientSizeCm): float
{
    return fdiv(atan(fdiv($patientSizeCm, 2 * $distance * 100)), $patientSizeCm * $distance / 200);
}

function common_factor(float $effectiveDays): float
{
    return fdiv(fdiv($effectiveDays * 24, M_LN2), 1 - exp(-fdiv(M_LN2, $effectiveDays)));
}

function exposure_contribution(array $exposure, float $effectiveDays, float $patientSizeCm): float
{
    $geom = ($exposure['unit_factor_at_1m'] && $exposure['distance'] == 1)
        ? 1.0
        : geometry_factor($exposure['distance'], $patientSizeCm);
    return decay_fraction($exposure['hours'], $effectiveDays) * $geom;
}

function restriction_days(float $effectiveDays, float $doseRate, float $patientSizeCm, array $exposures, float $limit): ?float
{
    $sum = 0.0;
    foreach ($exposures as $exposure) {
        $sum += exposure_contribution($exposure, $effectiveDays, $patientSizeCm);
    }
    $denominator = $sum * common_factor($effectiveDays);
    if (!($denominator > 0)) return null;
    $ratio = fdiv($limit * 1000, $denominator);
    $day = -fdiv($effectiveDays, M_LN2) * log(fdiv($ratio, $doseRate));
    if (!is_finite($day)) return null;
    return max(0.0, round_restriction_days($day));
}

function compute_dose_rate(array $selected, ?float $benignActivityMbq, ?float $benignFixationPct, mixed $doseRate): ?float
{
    if ($selected['api_code'] !== 'iode131_benin') return to_number_or_null($doseRate);
    if ($benignActivityMbq === null || $benignFixationPct === null) return null;
    return 2.2 * $benignActivityMbq * ($benignFixationPct / 100) / 37;
}

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
    $common = ['calculation_mode', 'isotope_code', 'patient_size_cm', 'user_period_days', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit', 'cure_count'];
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

// Équivalent de JSON.stringify(valeur ?? null) pour le message d'erreur isotope_code.
function js_json_stringify(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        // aligné sur SFMN (confirmé par le groupe Radioprotection SFMN).
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
            'condition' => js_number_to_string($user['hours1']) . ' h à ' . js_number_to_string($user['distance1']) . ' m et '
                . js_number_to_string($user['hours2']) . " h à 1 m,\nlimite " . js_number_to_string($user['limit']) . ' mSv',
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
            'calculation_mode' => 'local',
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
    return ['ok' => true, 'calculation_mode' => 'local'] + $common + [
        'errors' => [],
        'recommendations_days' => $recommendationsDays,
        'rows' => $allRows,
    ];
}
