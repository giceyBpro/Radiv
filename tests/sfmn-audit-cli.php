<?php
// Pilote de tests/test-sfmn-audit.php.js : exécute des scénarios de la comparaison avec la SFMN, avec une SFMN simulée (jamais d'appel réel).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RADIV_ENTRY', true);
define('RIV_ENGINE', 1);
putenv('DATA_DIR=' . $argv[1]);
putenv('SITE_PUBLIC_URL=https://www.exemple.fr');
putenv('SFMN_CALCULATOR_URL=https://sfmn.exemple.org/outil');
foreach (['json', 'config', 'store', 'http', 'contact'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";
require __DIR__ . '/../core/src/bootstrap.php';
foreach (['sfmn', 'sfmn_audit'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";
use Radiv\SfmnAudit as A;

$now = (int) (new DateTimeImmutable('2026-11-25 09:00:00', new DateTimeZone('Europe/Paris')))->getTimestamp();
$mails = [];
$mailer = static function (string $subject, string $html) use (&$mails): array { $mails[] = ['subject' => $subject, 'html' => $html]; return ['ok' => true]; };
$same = static fn (array $p): array => A\engine_calculate($p);                                  // SFMN identique au moteur
$bump = static function (array $p): array { $r = A\engine_calculate($p); $r['recommendations_days']['conjointe_enceinte'] += 2; return $r; };
$net = static fn (array $p): array => ['ok' => false, 'error' => ['code' => 'SFMN_NETWORK_ERROR', 'message' => 'Impossible de joindre la SFMN'], 'errors' => ['Échec réseau vers SFMN.'], 'recommendations_days' => []];
$fmt = static fn (array $p): array => ['ok' => false, 'error' => ['code' => 'SFMN_PARSE_FORM_FAILED', 'message' => 'Jeton introuvable'], 'errors' => ['Impossible d’extraire action/token.'], 'recommendations_days' => []];
$empty = static fn (array $p): array => ['ok' => true, 'recommendations_days' => array_fill_keys(A\SCENARIO_KEYS, null), 'effective_days' => null, 'computed_dose_rate' => null];
$engKo = static fn (array $p): array => ['ok' => false, 'errors' => ['moteur en panne'], 'recommendations_days' => []];

// Déroule une journée complète : renvoie la liste des statuts de chaque pas.
$runDay = static function (callable $sfmn, ?callable $engine = null, int $start = 0) use (&$mails, $now, $mailer): array {
    $t = $start ?: $now; $log = [];
    for ($i = 0; $i < 40; $i++) {
        $r = A\tick(['now' => $t, 'sfmn' => $sfmn, 'engine' => $engine, 'mailer' => $mailer]);
        $log[] = $r['status'] . (isset($r['category']) ? ':' . $r['category'] : '');
        if ($r['status'] === 'jour_termine') break;
        $t += ($r['status'] === 'nouvel_essai_programme') ? 3601 : 130;
    }
    return $log;
};
$reset = static function (string $yesterday = '') use ($now): void {
    @array_map('unlink', glob(A\runs_dir() . '/*.json') ?: []); @unlink(A\state_file());
    A\set_enabled(true, 'test');
    $s = A\load_state(); $s['last_day_done'] = $yesterday ?: '2026-11-24'; A\save_state($s);
};
$out = [];

// 1. tirage : 14 jours = 14 radiopharmaceutiques distincts, valeurs dans les plages
$state = A\default_state(); $seen = [];
for ($i = 0; $i < 14; $i++) $seen[] = A\next_isotope($state);
$out['cycle'] = ['distincts' => count(array_unique($seen)), 'tous' => !array_diff(A\ISOTOPES, $seen)];
$b1 = A\build_cases('iode131_benin', 'graine'); $b2 = A\build_cases('iode131_benin', 'graine'); $b3 = A\build_cases('iode131_benin', 'autre');
$fix = array_column(array_column($b1, 'payload'), 'benign_fixation_pct'); $act = array_column(array_column($b1, 'payload'), 'benign_activity_mbq');
$out['benin'] = ['n' => count($b1), 'reproductible' => $b1 === $b2, 'graine_differente' => $b1 !== $b3, 'fixations_standard' => !array_diff([15, 25, 30], $fix), 'fixations_valides' => !array_diff($fix, [15, 25, 30]),
    'activites' => min($act) >= 6000 && max($act) <= 8000, 'sans_debit' => !isset($b1[0]['payload']['dose_rate'])];
$ps = A\build_cases('psma_177lu', 'x'); $dr = array_column(array_column($ps, 'payload'), 'dose_rate'); $sz = array_column(array_column($ps, 'payload'), 'patient_size_cm'); $cu = array_column(array_column($ps, 'payload'), 'cure_count');
$out['psma'] = ['n' => count($ps), 'debit' => min($dr) >= 300 && max($dr) <= 1500, 'taille' => min($sz) >= 120 && max($sz) <= 210, 'cures' => !array_diff($cu, [1, 4, 6])];

// 2. journées : concordante, discordante, SFMN indisponible, page modifiée, réponse vide, moteur en erreur
$reset(); $out['ok'] = ['log' => $runDay($same), 'mails' => count($mails)];
$run = A\load_run('2026-11-25'); $out['ok']['counts_ok'] = $run['counts']['ok'] ?? null; $out['ok']['engine'] = $run['engine_version'] !== ''; $out['ok']['rounding'] = $run['rounding'];
$mails = []; $reset(); $out['discordance'] = ['log' => $runDay($bump)]; $m = $mails[0] ?? ['subject' => '', 'html' => ''];
$out['discordance'] += ['mails' => count($mails), 'subject' => $m['subject'], 'html_table' => str_contains($m['html'], 'conjointe_enceinte') && str_contains($m['html'], 'Discordance de calcul'), 'html_seed' => str_contains($m['html'], A\load_run('2026-11-25')['seed']), 'html_link' => str_contains($m['html'], '/auth/sfmn-audit/2026-11-25')];
$mails = []; $reset(); $out['indisponible'] = ['log' => $runDay($net)]; $run = A\load_run('2026-11-25'); $out['indisponible'] += ['retries' => $run['retries'], 'counts' => $run['counts']['sfmn_indisponible'], 'mails' => count($mails), 'subject' => $mails[0]['subject'] ?? ''];
$mails = []; $reset(); $out['format'] = ['log' => array_slice($runDay($fmt), 0, 5), 'mails' => count($mails), 'subject' => $mails[0]['subject'] ?? ''];
$mails = []; $reset(); $out['vide'] = ['log' => array_slice($runDay($empty), 0, 5), 'mails' => count($mails), 'subject' => $mails[0]['subject'] ?? ''];
$mails = []; $reset(); $out['moteur'] = ['log' => array_slice($runDay($same, $engKo), 0, 5), 'mails' => count($mails), 'subject' => $mails[0]['subject'] ?? ''];

// 3. délai minimal, interrupteur, rattrapage
$mails = []; $reset(); $r1 = A\tick(['now' => $now, 'sfmn' => $same]); $r2 = A\tick(['now' => $now + 10, 'sfmn' => $same]); $r3 = A\tick(['now' => $now + 125, 'sfmn' => $same]);
$out['delai'] = [$r1['status'], $r2['status'], $r3['status']];
A\set_enabled(false, 'test'); $out['desactive'] = A\tick(['now' => $now + 500, 'sfmn' => $same])['status'];
$reset('2026-11-10'); $n = 0; $t = $now; $days = []; // 15 jours manqués : seuls 7 sont rattrapés
for ($i = 0; $i < 400 && $n < 8; $i++) { $r = A\tick(['now' => $t, 'sfmn' => $same, 'engine' => null, 'mailer' => $mailer]); if ($r['status'] === 'jour_termine') { $days[] = $r['date']; $n++; } if ($r['status'] === 'rien_a_faire') break; $t += 130; }
$out['rattrapage'] = ['jours' => $days];
$before_hour = (int) (new DateTimeImmutable('2026-11-25 00:30:00', new DateTimeZone('Europe/Paris')))->getTimestamp();
$reset('2026-11-24'); $out['avant_1h'] = A\tick(['now' => $before_hour, 'sfmn' => $same])['status'];
// 4. exécution planifiée (cron) : horloge simulée
$mails = []; $reset(); $clock = $now; $slept = 0;
$log = A\batch(1500, static function (int $s) use (&$clock, &$slept): void { $clock += $s; $slept += $s; }, static function () use (&$clock): int { return $clock; }, ['sfmn' => $same, 'mailer' => $mailer]);
$out['cron'] = ['log' => $log, 'attente_totale' => $slept, 'fichier' => A\last_cron() === $now, 'jour' => A\load_run('2026-11-25')['date'] ?? null];
$reset('2026-11-21'); $clock = $now; $slept = 0; $mails = [];   // 4 jours manqués, budget de 25 minutes : le passage s'arrête quand le budget est épuisé
$log = A\batch(1500, static function (int $s) use (&$clock, &$slept): void { $clock += $s; $slept += $s; }, static function () use (&$clock): int { return $clock; }, ['sfmn' => $same, 'mailer' => $mailer]);
$out['cron_rattrapage'] = ['jours' => count(array_filter($log, static fn ($l) => str_starts_with($l, 'jour_termine'))), 'fin' => end($log), 'duree' => $slept];
A\set_enabled(false, 'test'); $clock = $now + 99999; $out['cron_desactive'] = A\batch(1500, static function (int $s) use (&$clock): void { $clock += $s; }, static function () use (&$clock): int { return $clock; }, ['sfmn' => $same]);
// 5. lancement manuel depuis l'administration
$mails = []; $reset(); $clock = $now; $slept = 0;
$sl = static function (int $x) use (&$clock, &$slept): void { $clock += $x; $slept += $x; }; $ck = static function () use (&$clock): int { return $clock; };
$out['manuel'] = ['plan1' => A\manual_plan($now)['mode']];
$r = A\manual_run($sl, $ck, ['sfmn' => $same, 'mailer' => $mailer]); $out['manuel'] += ['log1' => $r['log'], 'pause' => $slept];
$out['manuel']['plan2'] = A\manual_plan($clock)['mode'];
$r = A\manual_run($sl, $ck, ['sfmn' => $same, 'mailer' => $mailer]); $st = A\load_state();
$out['manuel'] += ['log2' => $r['log'], 'date_demain' => $st['current']['date'] ?? null, 'cas_faits_demain' => count(array_filter($st['current']['cases'] ?? [], static fn ($c) => isset($c['category']))), 'plan3' => A\manual_plan($clock)['mode']];
$r = A\manual_run($sl, $ck, ['sfmn' => $same, 'mailer' => $mailer]); $out['manuel']['log3'] = $r['log'];
$out['manuel']['visite_avant_1h'] = A\tick(['now' => $clock + 600, 'sfmn' => $same])['status'];            // demain pas encore dû : les visites n'y touchent pas
$tom = (int) (new DateTimeImmutable('2026-11-26 02:00:00', new DateTimeZone('Europe/Paris')))->getTimestamp(); $t = $tom; $logTom = [];
for ($i = 0; $i < 12; $i++) { $r = A\tick(['now' => $t, 'sfmn' => $same, 'mailer' => $mailer]); $logTom[] = $r['status']; if ($r['status'] === 'jour_termine') break; $t += 130; }
$out['manuel']['demain_apres_1h'] = $logTom;
// essai avant activation, et avant 1 h du matin
$reset(); A\set_enabled(false, 'test'); $pre = (int) (new DateTimeImmutable('2026-11-25 00:30:00', new DateTimeZone('Europe/Paris')))->getTimestamp(); $clock = $pre;
$s2 = A\load_state(); $s2['last_day_done'] = '2026-11-24'; A\save_state($s2);
$r = A\manual_run($sl, $ck, ['sfmn' => $same, 'mailer' => $mailer]); $out['manuel']['desactive_avant_1h'] = [$r['plan'], $r['log'][0] ?? null];
$mails = []; $reset(); $clock = $now; $r = A\manual_run($sl, $ck, ['sfmn' => $net, 'mailer' => $mailer]); $out['manuel']['indisponible'] = $r['log']; // une SFMN indisponible n'est pas martelée
echo json_encode($out, JSON_UNESCAPED_UNICODE);
