<?php
// Réglages par défaut (conservation des journaux) et cache de résultats SFMN. php api/tests/test-config-store.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('RATE_LIMIT_BACKEND=file');
foreach (['json', 'config', 'store'] as $f) require __DIR__ . "/../lib/{$f}.php";

$fail = 0;
$check = function (string $name, bool $cond) use (&$fail): void { echo ($cond ? 'OK    ' : 'ÉCHEC ') . $name . "\n"; if (!$cond) $fail++; };

foreach ([[null, 12, 'non défini → 12 mois'], ['', 12, 'vide → 12 mois'], ['6', 6, '6 → 6 mois'], ['0', null, '0 → conservation illimitée (explicite)'], ['abc', 12, 'valeur invalide → 12 mois'], ['-3', 12, 'valeur négative → 12 mois']] as [$raw, $expected, $label]) {
    $raw === null ? putenv('LOGS_RETENTION_MONTHS') : putenv("LOGS_RETENTION_MONTHS={$raw}");
    $check($label, Radiv\Config\logs_retention_months() === $expected);
}

$key = hash('sha256', 'test-' . bin2hex(random_bytes(4)));
$check('cache: clé inconnue → null', Radiv\Store\result_get($key) === null);
Radiv\Store\result_set($key, ['ok' => true, 'rows' => [1, 2]]);
$check('cache: résultat relu à l\'identique', Radiv\Store\result_get($key) === ['ok' => true, 'rows' => [1, 2]]);
@unlink(Radiv\Config\var_dir() . '/result-cache.json');
exit($fail ? 1 : 0);
