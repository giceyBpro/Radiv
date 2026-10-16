<?php
// Réglages par défaut (conservation des journaux) et cache de résultats SFMN. php tests/test-config-store.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('RATE_LIMIT_BACKEND=file');
define('RADIV_ENTRY', true);
foreach (['json', 'config', 'store', 'disk', 'measurements'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";

$fail = 0;
$check = function (string $name, bool $cond) use (&$fail): void { echo ($cond ? 'OK    ' : 'ÉCHEC ') . $name . "\n"; if (!$cond) $fail++; };

foreach ([[null, 12, 'non défini → 12 mois'], ['', 12, 'vide → 12 mois'], ['6', 6, '6 → 6 mois'], ['0', null, '0 → conservation illimitée (explicite)'], ['abc', 12, 'valeur invalide → 12 mois'], ['-3', 12, 'valeur négative → 12 mois']] as [$raw, $expected, $label]) {
    $raw === null ? putenv('LOGS_RETENTION_MONTHS') : putenv("LOGS_RETENTION_MONTHS={$raw}");
    $check($label, Radiv\Config\logs_retention_months() === $expected);
}

// Dossiers : backend, configuration, données (DATA_DIR absolu ou relatif au backend)
$backend = Radiv\Config\backend_dir();
$check('backend_dir = dossier qui contient src/', is_file($backend . '/src/app.php'));
$check('config_dir = <backend>/config', Radiv\Config\config_dir() === $backend . '/config');
foreach ([[null, $backend . '/data', 'DATA_DIR non défini → <backend>/data'], ['', $backend . '/data', 'DATA_DIR vide → <backend>/data'], ['/srv/donnees', '/srv/donnees', 'DATA_DIR absolu'], ['/srv/donnees/', '/srv/donnees', 'DATA_DIR absolu avec / final'], ['mes-donnees', $backend . '/mes-donnees', 'DATA_DIR relatif au backend']] as [$raw, $expected, $label]) {
    $raw === null ? putenv('DATA_DIR') : putenv("DATA_DIR={$raw}");
    $check($label, Radiv\Config\data_dir() === $expected);
}
putenv('DATA_DIR=/srv/donnees');
$check('journaux et état sous DATA_DIR', Radiv\Config\logs_dir() === '/srv/donnees/logs' && Radiv\Config\var_dir() === '/srv/donnees/var');
putenv('DATA_DIR');
$check('web_root = dossier qui contient le backend (sans façade)', Radiv\Config\web_root() === dirname($backend));

$key = hash('sha256', 'test-' . bin2hex(random_bytes(4)));
$check('cache: clé inconnue → null', Radiv\Store\result_get($key) === null);
Radiv\Store\result_set($key, ['ok' => true, 'rows' => [1, 2]]);
$check('cache: résultat relu à l\'identique', Radiv\Store\result_get($key) === ['ok' => true, 'rows' => [1, 2]]);
@unlink(Radiv\Config\var_dir() . '/result-cache.json');

// Journal des mesures : le doublon exact ip_geo.ip n'est pas écrit, mais les lectures le retrouvent à l'identique
$tmpData = sys_get_temp_dir() . '/radiv-test-logs-' . bin2hex(random_bytes(3));
putenv('DATA_DIR=' . $tmpData);
$entry = ['timestamp' => '2026-10-11T10:00:00.000Z', 'ip' => '203.0.113.7', 'ip_geo' => ['ip' => '203.0.113.7', 'provider' => 'ipwho.is', 'country' => 'France', 'city' => 'Paris', 'latitude' => 48.857, 'longitude' => 2.352], 'input' => ['isotope_code' => 'radium223'], 'result' => ['ok' => true]];
$full = ['ok' => true, 'calculation_mode' => 'local', 'selected' => ['api_code' => 'radium223', 'label' => 'Radium-223', 'reference' => 'texte long', 'remark' => 'x'], 'cure_count' => 1, 'cure_count_allowed' => [1],
    'computed_dose_rate' => null, 'effective_days' => 11.43, 'effective_hours' => 274.3, 'errors' => [], 'recommendations_days' => ['conjoint_plus_60' => 12, 'transport_commun' => 3],
    'rows' => [['audience_code' => 'conjoint_plus_60', 'label' => 'Proximité avec son/sa conjoint(e)', 'condition' => '8 h à 0,3 m', 'value' => 12]], 'sfmn_debug' => ['enabled' => true, 'requests' => [['step' => 'x']]]];
$entryFull = $entry; $entryFull['result'] = $full;
$other = ['timestamp' => '2026-10-11T10:00:01.000Z', 'ip' => '203.0.113.8', 'ip_geo' => ['ip' => '198.51.100.1', 'country' => 'X'], 'input' => ['isotope_code' => 'radium223']];
$private = ['timestamp' => '2026-10-11T10:00:02.000Z', 'ip' => '127.0.0.1', 'ip_geo' => ['ip' => '127.0.0.1', 'scope' => 'private_or_local']];
$noGeo = ['timestamp' => '2026-10-11T10:00:03.000Z', 'ip' => '203.0.113.9'];
foreach ([$entry, $other, $private, $noGeo, $entryFull] as $e) Radiv\Measurements\append($e);
$raw = file_get_contents($tmpData . '/logs/measurements-2026-10.jsonl');
$lines = explode("\n", trim($raw));
$check('doublon exact ip_geo.ip non écrit', strpos($lines[0], '"ip_geo":{"provider"') !== false && substr_count($lines[0], '203.0.113.7') === 1);
$check('ip_geo.ip différent de ip : conservé', strpos($lines[1], '"ip_geo":{"ip":"198.51.100.1"') !== false);
$check('adresse locale : doublon retiré aussi, scope conservé', strpos($lines[2], '"scope":"private_or_local"') !== false && substr_count($lines[2], '127.0.0.1') === 1);
$check('résultat : seules les valeurs variables sont écrites (jours, période, erreurs)', strpos($lines[4], '"recommendations_days":{"conjoint_plus_60":12,"transport_commun":3}') !== false && strpos($lines[4], '"effective_days":11.43') !== false);
$check('résultat : fiche, libellés, scénarios et diagnostic distant jamais écrits', !preg_match('/selected|reference|Radium-223|"rows"|Proximité avec|condition|sfmn_debug|cure_count_allowed/', $lines[4]));
$check('ligne nettement plus courte (< 450 octets contre ≈ 1 000 avec les textes)', strlen($lines[4]) < 450, (string) strlen($lines[4]));
$back = Radiv\Measurements\read_file($tmpData . '/logs/measurements-2026-10.jsonl');
$check('lecture : ip_geo.ip rétabli en tête, mêmes données qu\'avant', json_encode($back[0]->ip_geo) === json_encode((object) $entry['ip_geo']));
$check('lecture : ip_geo différent inchangé', json_encode($back[1]->ip_geo) === json_encode((object) $other['ip_geo']));
$check('lecture : ligne sans ip_geo inchangée', !property_exists($back[3], 'ip_geo') && $back[3]->ip === '203.0.113.9');
file_put_contents($tmpData . '/logs/measurements-2026-09.jsonl', json_encode($entry) . "\n");
$old = Radiv\Measurements\read_file($tmpData . '/logs/measurements-2026-09.jsonl');
$check('ancienne ligne (doublon présent) : lue telle quelle', json_encode($old[0]->ip_geo) === json_encode((object) $entry['ip_geo']));
exec('rm -rf ' . escapeshellarg($tmpData));
putenv('DATA_DIR');

// Espace disque : au-delà du seuil, les journaux les plus anciens sont supprimés d'abord
$disk = sys_get_temp_dir() . '/radiv-test-disk-' . bin2hex(random_bytes(3));
mkdir($disk . '/logs', 0777, true); mkdir($disk . '/var', 0777, true); mkdir($disk . '-web/backend', 0777, true);
define('RADIV_WEB_ROOT', $disk . '-web'); define('RADIV_BACKEND_DIR', $disk . '-web/backend'); // site vide : seules les données comptent
putenv('DATA_DIR=' . $disk); putenv('DISK_QUOTA_MB=4'); putenv('DISK_MIN_FREE_MB=3');
$thisMonth = gmdate('Y-m');
$mk = function ($name, $kb) use ($disk) { file_put_contents($disk . '/logs/' . $name, str_repeat('x', $kb * 1024)); };
$mk('measurements-2025-01.jsonl', 300); $mk('measurements-2025-02.jsonl', 300); $mk('measurements-2025-02.jsonl.1', 200); $mk('measurements-2025-03.jsonl', 400);
$mk("measurements-{$thisMonth}.jsonl", 400); $mk("measurements-{$thisMonth}.jsonl.1", 200); $mk('updates.jsonl', 1);
putenv('DISK_MIN_FREE_MB'); $check('minimum par défaut : 30 Mo', Radiv\Config\disk_min_free_bytes() === 30 * 1048576);
putenv('DISK_MIN_FREE_MB=0'); $check('minimum 0 : désactivé', Radiv\Config\disk_min_free_bytes() === null && Radiv\Disk\guard(true) === []);
putenv('DISK_MIN_FREE_MB=abc'); $check('valeur invalide : défaut', Radiv\Config\disk_min_free_bytes() === 30 * 1048576);
putenv('DISK_MIN_FREE_MB=3'); putenv('DISK_QUOTA_MB=abc'); $check('quota invalide ignoré', Radiv\Config\disk_quota_bytes() === null);
putenv('DISK_QUOTA_MB=4');
$u = Radiv\Disk\usage();
$check('usage mesuré par rapport au quota (environ 1,8 Mo sur 4 Mo, 2,2 libres)', $u['source'] === 'quota' && $u['limit'] === 4194304 && $u["free"] > 2 * 1048576 && $u["free"] < 2.5 * 1048576, json_encode($u));
$deleted = Radiv\Disk\guard(true);
$left = array_map('basename', glob($disk . '/logs/measurements-*') ?: []); sort($left);
$check('les mois les plus anciens sont supprimés en premier', $deleted[0] === 'measurements-2025-01.jsonl', implode(',', $deleted));
$check('le minimum d\'espace libre est retrouvé', Radiv\Disk\usage()['free'] >= 3 * 1048576 && count($left) >= 1, implode(',', $left));
$check('le mois en cours (fichier principal) est conservé', in_array("measurements-{$thisMonth}.jsonl", $left, true));
$check('opération journalisée (disk_purge)', strpos((string) file_get_contents($disk . '/logs/updates.jsonl'), '"action":"disk_purge"') !== false);
$check('contrôle espacé : un second appel immédiat ne fait rien', Radiv\Disk\guard() === []);
// Dernier recours : quota minuscule, seul le mois en cours reste, sa rotation part, jamais le fichier principal
putenv('DISK_QUOTA_MB=1'); $mk("measurements-{$thisMonth}.jsonl.1", 300); $mk("measurements-{$thisMonth}.jsonl", 900);
Radiv\Disk\guard(true);
$check('dernier recours : la rotation du mois en cours part, jamais le fichier principal', !is_file($disk . "/logs/measurements-{$thisMonth}.jsonl.1") && is_file($disk . "/logs/measurements-{$thisMonth}.jsonl"));
exec('rm -rf ' . escapeshellarg($disk) . ' ' . escapeshellarg($disk . '-web')); putenv('DATA_DIR'); putenv('DISK_QUOTA_MB'); putenv('DISK_MIN_FREE_MB');
exit($fail ? 1 : 0);
