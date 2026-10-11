<?php
// Réglages par défaut (conservation des journaux) et cache de résultats SFMN. php tests/test-config-store.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('RATE_LIMIT_BACKEND=file');
define('RADIV_ENTRY', true);
foreach (['json', 'config', 'store', 'measurements'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";

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
$other = ['timestamp' => '2026-10-11T10:00:01.000Z', 'ip' => '203.0.113.8', 'ip_geo' => ['ip' => '198.51.100.1', 'country' => 'X'], 'input' => ['isotope_code' => 'radium223']];
$private = ['timestamp' => '2026-10-11T10:00:02.000Z', 'ip' => '127.0.0.1', 'ip_geo' => ['ip' => '127.0.0.1', 'scope' => 'private_or_local']];
$noGeo = ['timestamp' => '2026-10-11T10:00:03.000Z', 'ip' => '203.0.113.9'];
foreach ([$entry, $other, $private, $noGeo] as $e) Radiv\Measurements\append($e);
$raw = file_get_contents($tmpData . '/logs/measurements-2026-10.jsonl');
$lines = explode("\n", trim($raw));
$check('doublon exact ip_geo.ip non écrit', strpos($lines[0], '"ip_geo":{"provider"') !== false && substr_count($lines[0], '203.0.113.7') === 1);
$check('ip_geo.ip différent de ip : conservé', strpos($lines[1], '"ip_geo":{"ip":"198.51.100.1"') !== false);
$check('adresse locale : doublon retiré aussi, scope conservé', strpos($lines[2], '"scope":"private_or_local"') !== false && substr_count($lines[2], '127.0.0.1') === 1);
$back = Radiv\Measurements\read_file($tmpData . '/logs/measurements-2026-10.jsonl');
$check('lecture : ip_geo.ip rétabli en tête, mêmes données qu\'avant', json_encode($back[0]->ip_geo) === json_encode((object) $entry['ip_geo']));
$check('lecture : ip_geo différent inchangé', json_encode($back[1]->ip_geo) === json_encode((object) $other['ip_geo']));
$check('lecture : ligne sans ip_geo inchangée', !property_exists($back[3], 'ip_geo') && $back[3]->ip === '203.0.113.9');
file_put_contents($tmpData . '/logs/measurements-2026-09.jsonl', json_encode($entry) . "\n");
$old = Radiv\Measurements\read_file($tmpData . '/logs/measurements-2026-09.jsonl');
$check('ancienne ligne (doublon présent) : lue telle quelle', json_encode($old[0]->ip_geo) === json_encode((object) $entry['ip_geo']));
exec('rm -rf ' . escapeshellarg($tmpData));
putenv('DATA_DIR');
exit($fail ? 1 : 0);
