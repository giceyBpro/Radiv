<?php
// Comparaison automatique, jour après jour, du moteur RadIV avec l'outil en ligne de la SFMN.
//
// Chaque jour (Europe/Paris), UN radiopharmaceutique est tiré dans un cycle mélangé (chacun repasse tous les ~14 jours) et QUATRE cas aux
// valeurs élevées, voire aberrantes en clinique (pour faire ressortir le moindre écart de calcul), sont calculés par le moteur (arrondi
// « floor », celui de la SFMN) et par la SFMN. Toute différence est une anomalie signalée par e-mail : elle témoigne d'une modification du
// mode de calcul d'un des deux côtés. Pas de tâche cron sur l'hébergement : le travail est déclenché par la première consultation du site après
// 1 h du matin, UN cas à la fois, avec au moins MIN_GAP_SECONDS entre deux appels à la SFMN (la fin de la journée attend la visite suivante),
// et les jours manqués sont rattrapés (MAX_CATCHUP_DAYS au plus). Désactivé par défaut : interrupteur dans l'administration (onglet Site).
//
// Catégories de résultat d'un cas :
//   ok               les deux calculs sont identiques ;
//   discordance      les deux répondent, mais les durées (ou la période effective, ou le débit de dose calculé) diffèrent ;
//   sfmn_indisponible la SFMN ne répond pas (réseau, erreur HTTP) — nouvel essai plus tard avant de conclure ;
//   sfmn_format      la page SFMN a changé (formulaire, jeton, liste des radiopharmaceutiques) ;
//   sfmn_vide        la SFMN répond sans aucune durée (valeurs refusées, ou tableau de résultats modifié) ;
//   moteur_erreur    le moteur RadIV refuse ou échoue (anormal pour ces valeurs).
declare(strict_types=1);

namespace Radiv\SfmnAudit;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use RivEngine as Calculation;
use Radiv\Config;
use Radiv\Contact;
use Radiv\Sfmn;

const CASES_PER_DAY = 4;
const MIN_GAP_SECONDS = 120;       // délai minimal entre deux appels à la SFMN (déclenchement automatique)
const MANUAL_GAP_SECONDS = 10;     // pause entre deux appels à la SFMN lors d'un lancement manuel depuis l'administration
const START_HOUR = 1;              // le travail d'un jour peut commencer à partir de 1 h (Europe/Paris)
const MAX_CATCHUP_DAYS = 7;        // jours manqués rattrapés au plus (les plus anciens sont ignorés et signalés)
const UNAVAILABLE_RETRIES = 3;     // nouveaux essais d'un cas dont la SFMN était indisponible
const RETRY_AFTER_SECONDS = 3600;
const KEEP_RUNS = 120;             // rapports quotidiens conservés
const TZ = 'Europe/Paris';

const DOSE_RATE_MIN = 300;         // µSv/h : valeurs élevées, aberrantes en clinique
const DOSE_RATE_MAX = 1500;
const SIZE_MIN = 120;              // cm
const SIZE_MAX = 210;
const BENIGN_ACTIVITY_MIN = 6000;  // MBq
const BENIGN_ACTIVITY_MAX = 8000;
const BENIGN_FIXATIONS = [15, 25, 30]; // % : nodule, goitre multinodulaire, Basedow (les trois valeurs standard de la SFMN)

// Radiopharmaceutiques comparés (ceux que l'outil SFMN sait calculer ; « Personnalisé » exclu).
const ISOTOPES = ['iode131_0_fixation', 'iode131_5_fixation', 'iode131_25_fixation', 'iode131_benin', 'lipiodol_131i', 'lutetium177_net', 'mibg_131i',
    'microspheres_90y', 'microspheres_166ho', 'psma_177lu', 'radium223', 'synovectomie_90y', 'synovectomie_169er', 'synovectomie_186re'];
const SCENARIO_KEYS = ['conjoint_plus_60', 'conjoint_moins_60', 'conjointe_enceinte', 'transport_commun', 'enfant_moins_3_ans', 'enfant_3_11_ans', 'collegues_travail'];
const CATEGORIES = [
    'discordance' => 'Discordance de calcul',
    'sfmn_indisponible' => 'SFMN indisponible',
    'sfmn_format' => 'Format de la page SFMN modifié',
    'sfmn_vide' => 'Réponse SFMN vide ou illisible',
    'moteur_erreur' => 'Erreur du moteur RadIV',
];

function dir(): string { return Config\var_dir() . '/sfmn-audit'; }
function state_file(): string { return dir() . '/state.json'; }
function runs_dir(): string { return dir() . '/runs'; }

function tz(): \DateTimeZone { return new \DateTimeZone(TZ); }
function local_date(int $ts): string { return (new \DateTimeImmutable('@' . $ts))->setTimezone(tz())->format('Y-m-d'); }
function start_of(string $date): int { return (new \DateTimeImmutable($date . ' ' . sprintf('%02d:00:00', START_HOUR), tz()))->getTimestamp(); }

// --- État (petit JSON, écrit de façon atomique) --------------------------------------------------------------------------------------

function default_state(): array
{
    return ['enabled' => false, 'enabled_by' => '', 'enabled_at' => '', 'queue' => [], 'current' => null, 'last_day_done' => null, 'last_request_at' => 0, 'skipped_days' => []];
}

function load_state(): array
{
    $raw = @file_get_contents(state_file());
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data + default_state() : default_state();
}

function save_state(array $state): bool
{
    if (!Config\ensure_private_dir(dir())) return false;
    $tmp = state_file() . '.tmp' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) return false;
    return @rename($tmp, state_file());
}

function enabled(): bool { return !empty(load_state()['enabled']); }

function set_enabled(bool $on, string $by): bool
{
    $state = load_state();
    $state['enabled'] = $on;
    $state['enabled_by'] = $by;
    $state['enabled_at'] = gmdate('Y-m-d\TH:i:s\Z');
    if ($on && $state['last_day_done'] === null) $state['last_day_done'] = local_date(time() - 86400); // pas de rattrapage avant l'activation
    return save_state($state);
}

// --- Tirage et valeurs ----------------------------------------------------------------------------------------------------------------

// Cycle mélangé : chaque radiopharmaceutique repasse une fois par cycle (~14 jours), dans un ordre aléatoire.
function next_isotope(array &$state): string
{
    $queue = array_values(array_filter((array) ($state['queue'] ?? []), static fn ($c) => in_array($c, ISOTOPES, true)));
    if (!$queue) {
        $queue = ISOTOPES;
        for ($i = count($queue) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$queue[$i], $queue[$j]] = [$queue[$j], $queue[$i]]; }
    }
    $state['queue'] = $queue;
    return array_shift($state['queue']);
}

// Quatre cas reproductibles à partir de la graine. Iode bénin : les trois fixations standard + une répétée, activités de 6000 à 8000 MBq.
function build_cases(string $isotope, string $seed): array
{
    mt_srand((int) hexdec(substr(hash('sha256', $seed . '|' . $isotope), 0, 7)));
    $cures = Calculation\cure_options_by_isotope()[$isotope] ?? [1];
    $cases = [];
    $fixations = BENIGN_FIXATIONS;
    $fixations[] = $fixations[mt_rand(0, count($fixations) - 1)];
    for ($i = 0; $i < CASES_PER_DAY; $i++) {
        $payload = ['isotope_code' => $isotope, 'patient_size_cm' => mt_rand(SIZE_MIN, SIZE_MAX)];
        if ($isotope === 'iode131_benin') {
            $payload['benign_activity_mbq'] = mt_rand(BENIGN_ACTIVITY_MIN, BENIGN_ACTIVITY_MAX);
            $payload['benign_fixation_pct'] = $fixations[$i];
        } else {
            $payload['dose_rate'] = mt_rand(DOSE_RATE_MIN * 10, DOSE_RATE_MAX * 10) / 10;
        }
        if (count($cures) > 1) $payload['cure_count'] = $cures[mt_rand(0, count($cures) - 1)];
        $cases[] = ['payload' => $payload];
    }
    return $cases;
}

// --- Calcul et comparaison ------------------------------------------------------------------------------------------------------------

function engine_calculate(array $payload): array
{
    Calculation\set_rounding_mode('floor'); // la SFMN tronque : même règle des deux côtés
    try {
        return Calculation\calculate($payload);
    } finally {
        Calculation\set_rounding_mode(null);
    }
}

function num_equal(mixed $a, mixed $b, float $tolerance = 0.0): bool
{
    if ($a === null || $b === null) return $a === $b;
    return abs((float) $a - (float) $b) <= $tolerance;
}

// Classe un cas à partir des deux réponses ; renvoie ['category' => ..., 'details' => [...]] (details : écarts ou message).
function classify(array $engine, array $sfmn): array
{
    if (empty($engine['ok'])) {
        return ['category' => 'moteur_erreur', 'details' => ['message' => (string) ($engine['errors'][0] ?? $engine['error']['message'] ?? 'Erreur inconnue')]];
    }
    if (empty($sfmn['ok'])) {
        $code = (string) ($sfmn['error']['code'] ?? '');
        $message = (string) ($sfmn['errors'][0] ?? $sfmn['error']['message'] ?? 'Erreur inconnue');
        $format = ['SFMN_PARSE_FORM_FAILED', 'SFMN_RADIOPHARMACEUTICAL_MISMATCH', 'SFMN_UNSUPPORTED_ISOTOPE'];
        return ['category' => in_array($code, $format, true) ? 'sfmn_format' : 'sfmn_indisponible', 'details' => ['code' => $code, 'message' => $message]];
    }
    $theirs = (array) ($sfmn['recommendations_days'] ?? []);
    if (!array_filter(array_intersect_key($theirs, array_flip(SCENARIO_KEYS)), static fn ($v) => $v !== null)) {
        return ['category' => 'sfmn_vide', 'details' => ['message' => 'La SFMN a répondu sans aucune durée de restriction.']];
    }
    $diffs = [];
    foreach (SCENARIO_KEYS as $key) {
        $mine = $engine['recommendations_days'][$key] ?? null;
        $other = $theirs[$key] ?? null;
        if (!num_equal($mine, $other)) {
            $diffs[] = ['champ' => $key, 'radiv' => $mine, 'sfmn' => $other, 'ecart' => ($mine !== null && $other !== null) ? abs((float) $mine - (float) $other) : null];
        }
    }
    foreach (['effective_days' => 0.01, 'computed_dose_rate' => 0.01] as $field => $tolerance) {
        $mine = $engine[$field] ?? null;
        $other = $sfmn[$field] ?? null;
        if ($other !== null && $mine !== null && !num_equal($mine, $other, $tolerance)) {
            $diffs[] = ['champ' => $field, 'radiv' => $mine, 'sfmn' => $other, 'ecart' => abs((float) $mine - (float) $other)];
        }
    }
    return $diffs ? ['category' => 'discordance', 'details' => ['ecarts' => $diffs]] : ['category' => 'ok', 'details' => []];
}

// Exécute un cas. $engineFn / $sfmnFn : fonctions de calcul (injectées par les tests ; par défaut le vrai moteur et la vraie SFMN).
function run_case(array $payload, ?callable $engineFn = null, ?callable $sfmnFn = null): array
{
    $engine = ($engineFn ?? 'Radiv\\SfmnAudit\\engine_calculate')($payload);
    $sfmn = ($sfmnFn ?? static fn (array $p): array => Sfmn\calculate($p, ['force' => true]))($payload);
    $verdict = classify($engine, $sfmn);
    return $verdict + [
        'radiv' => ['recommendations_days' => $engine['recommendations_days'] ?? null, 'effective_days' => $engine['effective_days'] ?? null, 'computed_dose_rate' => $engine['computed_dose_rate'] ?? null],
        'sfmn' => ['recommendations_days' => $sfmn['recommendations_days'] ?? null, 'effective_days' => $sfmn['effective_days'] ?? null, 'computed_dose_rate' => $sfmn['computed_dose_rate'] ?? null],
    ];
}

// --- Déroulement quotidien, un cas à la fois ------------------------------------------------------------------------------------------

function pending_dates(array &$state, int $now, bool $force = false): array
{
    $today = local_date($now);
    $last = (string) ($state['last_day_done'] ?? local_date($now - 86400));
    $dates = [];
    for ($d = new \DateTimeImmutable($last, tz()); ; ) {
        $d = $d->modify('+1 day');
        $date = $d->format('Y-m-d');
        if ($date > $today) break;
        if ($now >= start_of($date) || ($force && $date === $today)) $dates[] = $date; // lancement manuel : aujourd'hui, même avant 1 h
        if (count($dates) > 400) break;
    }
    if (count($dates) > MAX_CATCHUP_DAYS) { // trop de jours manqués : seuls les plus récents sont rattrapés
        $skipped = array_slice($dates, 0, count($dates) - MAX_CATCHUP_DAYS);
        $state['skipped_days'] = array_values(array_unique(array_merge((array) $state['skipped_days'], $skipped)));
        $dates = array_slice($dates, -MAX_CATCHUP_DAYS);
    }
    return $dates;
}

// Fait avancer d'UN pas : un cas de la journée en cours (ou le démarrage de la suivante). Renvoie ['status' => ..., ...].
// Options : 'force' (ignore le délai minimal), 'engine' / 'sfmn' / 'mailer' (injection pour les tests), 'now'.
function tick(array $opts = []): array
{
    $now = (int) ($opts['now'] ?? time());
    if (!Config\ensure_private_dir(dir())) return ['status' => 'erreur', 'message' => 'Dossier de travail inaccessible.'];
    $lock = @fopen(dir() . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['status' => 'occupe'];
    try {
        $state = load_state();
        if (empty($state['enabled']) && empty($opts['even_if_disabled'])) return ['status' => 'desactive'];
        $force = !empty($opts['force']);
        if (!$force && $now - (int) $state['last_request_at'] < MIN_GAP_SECONDS) return ['status' => 'attente'];

        // Journée de demain démarrée en avance (lancement manuel) : ses autres cas attendent demain 1 h.
        if (is_array($state['current']) && !$force && $now < start_of((string) $state['current']['date'])) { return ['status' => 'rien_a_faire']; }

        if (!is_array($state['current'])) {
            $dates = pending_dates($state, $now, $force);
            if (!$dates && !empty($opts['ahead'])) $dates = [(new \DateTimeImmutable('@' . $now))->setTimezone(tz())->modify('+1 day')->format('Y-m-d')]; // première requête de demain
            if (!$dates) { save_state($state); return ['status' => 'rien_a_faire']; }
            $isotope = next_isotope($state);
            $seed = $dates[0] . '-' . bin2hex(random_bytes(4));
            $state['current'] = ['date' => $dates[0], 'isotope' => $isotope, 'seed' => $seed, 'started_at' => $now, 'cases' => build_cases($isotope, $seed), 'retry_at' => 0];
        }
        $current = &$state['current'];

        if ((int) $current['retry_at'] > $now && !$force) { save_state($state); return ['status' => 'attente']; }
        $index = null;
        foreach ($current['cases'] as $i => $case) { if (!isset($case['category'])) { $index = $i; break; } }

        if ($index !== null) {
            $result = run_case($current['cases'][$index]['payload'], $opts['engine'] ?? null, $opts['sfmn'] ?? null);
            $current['cases'][$index] += $result;
            $state['last_request_at'] = $now;
            save_state($state);
            return ['status' => 'cas_execute', 'date' => $current['date'], 'isotope' => $current['isotope'], 'index' => $index + 1, 'category' => $result['category']];
        }

        // Tous les cas ont un résultat : nouveaux essais pour les « SFMN indisponible », puis conclusion de la journée.
        $retries = (int) ($current['retries'] ?? 0);
        $unavailable = array_keys(array_filter($current['cases'], static fn ($c) => ($c['category'] ?? '') === 'sfmn_indisponible'));
        if ($unavailable && $retries < UNAVAILABLE_RETRIES) {
            foreach ($unavailable as $i) { $current['cases'][$i] = ['payload' => $current['cases'][$i]['payload']]; }
            $current['retries'] = $retries + 1;
            $current['retry_at'] = $now + RETRY_AFTER_SECONDS;
            save_state($state);
            return ['status' => 'nouvel_essai_programme', 'date' => $current['date']];
        }
        $run = finalize($current, $now, $state, $opts['mailer'] ?? null);
        $state['last_day_done'] = $current['date'];
        $state['skipped_days'] = [];
        $state['current'] = null;
        save_state($state);
        return ['status' => 'jour_termine', 'date' => $run['date'], 'anomalies' => $run['anomalies'], 'email' => $run['email']];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Écrit le rapport du jour et envoie l'e-mail s'il y a au moins une anomalie.
function finalize(array $current, int $now, array $state, ?callable $mailer): array
{
    $counts = array_fill_keys(array_merge(['ok'], array_keys(CATEGORIES)), 0);
    foreach ($current['cases'] as $case) $counts[$case['category']] = ($counts[$case['category']] ?? 0) + 1;
    $anomalies = array_sum($counts) - $counts['ok'];
    $run = [
        'date' => $current['date'], 'isotope' => $current['isotope'], 'seed' => $current['seed'], 'started_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $current['started_at']),
        'finished_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'engine_version' => function_exists('RivEngine\\version') ? Calculation\version() : '', 'rounding' => 'floor',
        'sfmn_url' => Config\env('SFMN_CALCULATOR_URL'), 'retries' => (int) ($current['retries'] ?? 0), 'cases' => $current['cases'], 'counts' => $counts,
        'anomalies' => $anomalies, 'skipped_days' => (array) ($state['skipped_days'] ?? []), 'email' => ['sent' => false],
    ];
    if ($anomalies > 0) {
        $subject = subject($run);
        $send = $mailer ?? static fn (string $s, string $h): array => Contact\smtp_send(Config\env('SMTP_USER'), $s, $h);
        try {
            $r = $send($subject, email_html($run));
            $run['email'] = ['sent' => !empty($r['ok']), 'error' => empty($r['ok']) ? (string) ($r['error'] ?? 'Échec') : null];
        } catch (\Throwable $e) {
            $run['email'] = ['sent' => false, 'error' => 'Exception : ' . $e->getMessage()];
        }
        if (!$run['email']['sent']) error_log('[SFMN-AUDIT] e-mail non envoyé : ' . $run['email']['error']);
    }
    if (Config\ensure_private_dir(runs_dir())) {
        @file_put_contents(runs_dir() . '/' . $run['date'] . '.json', json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $files = glob(runs_dir() . '/*.json') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - KEEP_RUNS)) as $old) @unlink($old);
    }
    return $run;
}

function load_run(string $date): ?array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) return null;
    $raw = @file_get_contents(runs_dir() . '/' . $date . '.json');
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function recent_runs(int $limit = 14): array
{
    $files = glob(runs_dir() . '/*.json') ?: [];
    rsort($files);
    $out = [];
    foreach (array_slice($files, 0, $limit) as $file) {
        $run = load_run(basename($file, '.json'));
        if ($run) $out[] = $run;
    }
    return $out;
}

// --- Courrier -------------------------------------------------------------------------------------------------------------------------

function esc(string $text): string { return Contact\escape_html($text); }

function show(mixed $v): string { return $v === null ? '—' : (is_float($v) ? rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',') : (string) $v); }

function payload_text(array $payload): string
{
    $skip = ['isotope_code'];
    $parts = [];
    foreach ($payload as $k => $v) if (!in_array($k, $skip, true)) $parts[] = $k . ' = ' . show(is_numeric($v) ? $v + 0 : $v);
    return implode(', ', $parts);
}

function subject(array $run): string
{
    $parts = [];
    foreach (CATEGORIES as $key => $label) {
        $n = (int) ($run['counts'][$key] ?? 0);
        if ($n > 0) $parts[] = $n . ' × ' . $label;
    }
    return '[RadIV] Comparaison SFMN du ' . $run['date'] . ' (' . $run['isotope'] . ') : ' . implode(', ', $parts);
}

function email_html(array $run): string
{
    $th = 'style="padding:6px 8px;border:1px solid #d9e0e6;background:#f3f6f9;text-align:left"';
    $td = 'style="padding:6px 8px;border:1px solid #d9e0e6;vertical-align:top"';
    $site = Config\site_public_url();
    $link = $site !== '' ? '<p><a href="' . esc($site . '/auth/sfmn-audit/' . $run['date']) . '">Voir le rapport complet dans l\'administration</a></p>' : '';
    $out = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;max-width:820px">'
        . '<h2 style="margin:0 0 8px">Comparaison RadIV / SFMN : ' . (int) $run['anomalies'] . ' anomalie(s)</h2>'
        . '<p>Jour : <strong>' . esc($run['date']) . '</strong> · radiopharmaceutique : <strong>' . esc($run['isotope']) . '</strong> · cas testés : ' . count($run['cases'])
        . ' · moteur v' . esc((string) $run['engine_version']) . ' (arrondi « ' . esc($run['rounding']) . ' ») · graine <code>' . esc($run['seed']) . '</code></p>'
        . '<p style="color:#555;font-size:.9em">Les valeurs testées sont volontairement élevées (aberrantes en clinique) pour faire ressortir les écarts. '
        . 'Toute discordance indique une modification du mode de calcul d\'un des deux outils.</p>';
    foreach (CATEGORIES as $key => $label) {
        $cases = array_filter($run['cases'], static fn (array $c): bool => ($c['category'] ?? '') === $key);
        if (!$cases) continue;
        $out .= '<h3 style="margin:18px 0 6px">' . esc($label) . ' (' . count($cases) . ')</h3>';
        if ($key === 'discordance') {
            $out .= '<table style="border-collapse:collapse;width:100%"><tr><th ' . $th . '>Valeurs saisies</th><th ' . $th . '>Champ</th><th ' . $th . '>RadIV</th><th ' . $th . '>SFMN</th><th ' . $th . '>Écart</th></tr>';
            foreach ($cases as $c) {
                foreach ($c['details']['ecarts'] as $e) {
                    $gravity = ($e['ecart'] !== null && $e['ecart'] <= 1.0 && str_contains(implode(',', SCENARIO_KEYS), (string) $e['champ'])) ? ' (1 jour : arrondi ?)' : '';
                    $out .= '<tr><td ' . $td . '>' . esc(payload_text($c['payload'])) . '</td><td ' . $td . '>' . esc((string) $e['champ']) . '</td><td ' . $td . '>' . esc(show($e['radiv']))
                        . '</td><td ' . $td . '>' . esc(show($e['sfmn'])) . '</td><td ' . $td . '>' . esc(show($e['ecart'])) . esc($gravity) . '</td></tr>';
                }
            }
            $out .= '</table>';
        } else {
            $out .= '<ul>';
            foreach ($cases as $c) {
                $detail = (string) ($c['details']['message'] ?? '') . (isset($c['details']['code']) && $c['details']['code'] !== '' ? ' [' . $c['details']['code'] . ']' : '');
                $out .= '<li>' . esc(payload_text($c['payload'])) . ($detail !== '' ? ' — ' . esc($detail) : '') . '</li>';
            }
            $out .= '</ul>';
        }
    }
    $ok = (int) ($run['counts']['ok'] ?? 0);
    $out .= '<p style="margin-top:16px">Cas concordants : <strong>' . $ok . '</strong> sur ' . count($run['cases']) . '.'
        . ($run['retries'] > 0 ? ' Nouveaux essais pour cause d\'indisponibilité : ' . (int) $run['retries'] . '.' : '') . '</p>';
    if (!empty($run['skipped_days'])) $out .= '<p style="color:#8a5a00">Jours non rattrapés (site resté sans visite) : ' . esc(implode(', ', $run['skipped_days'])) . '.</p>';
    $out .= $link . '<p style="color:#555;font-size:.85em">Outil SFMN interrogé : ' . esc((string) $run['sfmn_url']) . '</p></div>';
    return $out;
}

// --- Lancement manuel depuis l'administration -------------------------------------------------------------------------------------------

// Ce que fera le bouton « Lancer » : 'today' (requêtes du jour pas encore lancées), 'resume_today' (journée entamée : on la termine),
// 'tomorrow' (aujourd'hui est fait : première requête planifiée demain, lancée maintenant), 'tomorrow_started' (déjà fait : rien à lancer).
function manual_plan(?int $now = null): array
{
    $now ??= time();
    $today = local_date($now);
    $state = load_state();
    $cur = $state['current'] ?? null;
    if (is_array($cur) && (string) $cur['date'] === $today) return ['mode' => 'resume_today', 'date' => $today, 'isotope' => (string) $cur['isotope']];
    if (is_array($cur) && (string) $cur['date'] > $today) return ['mode' => 'tomorrow_started', 'date' => (string) $cur['date'], 'isotope' => (string) $cur['isotope']];
    if ((string) ($state['last_day_done'] ?? '') >= $today) return ['mode' => 'tomorrow', 'date' => local_date($now + 86400), 'isotope' => ''];
    return ['mode' => 'today', 'date' => $today, 'isotope' => ''];
}

// Exécute le plan ci-dessus, même si l'audit est désactivé (essai avant activation) et sans attendre 1 h : les requêtes du jour une par une
// (MANUAL_GAP_SECONDS entre deux appels, budget 280 s), ou la seule première requête de demain. $sleep / $clock : injection (tests).
function manual_run(?callable $sleep = null, ?callable $clock = null, array $opts = []): array
{
    $clock ??= 'time';
    $sleep ??= 'sleep';
    $start = (int) $clock();
    $plan = manual_plan($start)['mode'];
    if ($plan === 'tomorrow_started') return ['plan' => $plan, 'log' => ['deja_demarree']];
    $log = [];
    for ($i = 0; $i < 40; $i++) {
        if ((int) $clock() - $start > 280) { $log[] = 'budget_epuise'; break; }
        $r = tick(['now' => (int) $clock(), 'force' => true, 'even_if_disabled' => true, 'ahead' => $plan === 'tomorrow'] + $opts);
        $log[] = $r['status'] . (isset($r['category']) ? ':' . $r['category'] : '') . (isset($r['anomalies']) ? ':' . $r['anomalies'] . '_anomalie(s)' : '');
        if ($r['status'] !== 'cas_execute') break;      // jour terminé, nouvel essai programmé (on ne martèle pas une SFMN indisponible), erreur, occupé…
        if ($plan === 'tomorrow') break;                // seule la première requête de demain
        $sleep(MANUAL_GAP_SECONDS);
    }
    return ['plan' => $plan, 'log' => $log];
}

// --- Exécution planifiée (tâche cron, facultative) ------------------------------------------------------------------------------------

function cron_file(): string { return dir() . '/last-cron'; }
function last_cron(): int { return (int) @file_get_contents(cron_file()); }

// Traite les cas dus l'un après l'autre en respectant le délai minimal, jusqu'à épuisement du budget de temps (secondes) ; s'arrête dès qu'il n'y a plus
// rien à faire, ou qu'un nouvel essai est programmé plus tard (la tâche suivante, ou la prochaine visite, le reprendra). $sleep / $clock : injection (tests).
function batch(int $budgetSeconds = 1500, ?callable $sleep = null, ?callable $clock = null, array $opts = []): array
{
    $clock ??= 'time';
    $sleep ??= 'sleep';
    $start = (int) $clock();
    if (Config\ensure_private_dir(dir())) @file_put_contents(cron_file(), (string) $start);
    $log = [];
    for ($guard = 0; $guard < 400; $guard++) {
        $now = (int) $clock();
        if ($now - $start >= $budgetSeconds) { $log[] = 'budget_epuise'; break; }
        $r = tick(['now' => $now] + $opts);
        $log[] = $r['status'] . (isset($r['category']) ? ':' . $r['category'] : '') . (isset($r['anomalies']) ? ':' . $r['anomalies'] . '_anomalie(s)' : '');
        if ($r['status'] === 'attente') {
            $retryAt = (int) (load_state()['current']['retry_at'] ?? 0);
            if ($retryAt > $now + ($budgetSeconds - ($now - $start))) break; // nouvel essai trop lointain pour ce passage
            $sleep(30);
            continue;
        }
        if (!in_array($r['status'], ['cas_execute', 'jour_termine'], true)) break; // désactivé, rien à faire, occupé, nouvel essai programmé, erreur
        $sleep(MIN_GAP_SECONDS);
    }
    return $log;
}

// --- Déclenchement par la première visite ---------------------------------------------------------------------------------------------

// Appelé en fin de requête (après envoi de la réponse) : ne fait quelque chose que si l'audit est activé, si un cas est dû et si le
// serveur permet de rendre la réponse au visiteur AVANT de travailler (sinon rien : le visiteur ne doit jamais attendre la SFMN).
function maybe_tick(): void
{
    try {
        $marker = dir() . '/.last-check';
        if (is_file($marker) && time() - (int) @filemtime($marker) < 30) return;
        if (!is_file(state_file()) || !enabled()) return;
        if (!function_exists('fastcgi_finish_request')) return;
        @touch($marker);
        fastcgi_finish_request();
        @set_time_limit(120);
        ignore_user_abort(true);
        tick();
    } catch (\Throwable $e) {
        error_log('[SFMN-AUDIT] ' . get_class($e) . ' : ' . $e->getMessage());
    }
}
