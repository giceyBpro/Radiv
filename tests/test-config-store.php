<?php
// Réglages par défaut (conservation des journaux) et cache de résultats SFMN. php tests/test-config-store.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('RATE_LIMIT_BACKEND=file');
define('RADIV_ENTRY', true);
foreach (['json', 'config', 'store'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";

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
exit($fail ? 1 : 0);
