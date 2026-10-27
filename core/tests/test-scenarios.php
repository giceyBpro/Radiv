<?php
// Scénarios déclarés dans data/scenarios.json : un scénario AJOUTÉ DANS LES DONNÉES produit une ligne de résultat sans toucher au code ;
// contrôle des erreurs de saisie. Le moteur est copié dans un dossier temporaire. Usage : php core/tests/test-scenarios.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/riv-scenarios-' . bin2hex(random_bytes(4));
mkdir($tmp);
foreach (['src', 'data'] as $dir) {
    mkdir("{$tmp}/{$dir}");
    foreach (scandir(__DIR__ . "/../{$dir}") as $f) if ($f[0] !== '.') copy(__DIR__ . "/../{$dir}/{$f}", "{$tmp}/{$dir}/{$f}");
}
copy(__DIR__ . '/../VERSION', "{$tmp}/VERSION");
$file = "{$tmp}/data/scenarios.json";
$scenarios = json_decode((string) file_get_contents($file), true);
$count = count($scenarios);
$scenarios[] = ['audience_code' => 'voisin_palier', 'label' => 'Voisin de palier', 'exposures' => [['hours' => 1, 'distance' => 1, 'unit_factor_at_1m' => true]], 'limit' => 1];
$scenarios[] = ['audience_code' => 'passagers_taxi', 'label' => 'Passagers de taxi', 'exposures' => [['hours' => 0.5, 'distance' => 0.5, 'note' => 'trajet']], 'limit' => 2.5, 'shares_limit_across_cures' => false];
file_put_contents($file, json_encode($scenarios, JSON_UNESCAPED_UNICODE));
require "{$tmp}/src/bootstrap.php";

$fail = 0;
$t = static function (string $name, bool $ok) use (&$fail): void { echo ($ok ? 'OK    ' : 'ÉCHEC ') . $name . "\n"; if (!$ok) $fail++; };
$base = ['isotope_code' => 'psma_177lu', 'dose_rate' => 30, 'patient_size_cm' => 170];

$r = RivEngine\calculate($base);
$codes = array_column($r['rows'], 'audience_code');
$t('scénarios ajoutés dans les données : une ligne de plus chacun, avant le scénario personnalisé', $r['ok'] && count($r['rows']) === $count + 3 && $codes[$count] === 'voisin_palier' && $codes[$count + 1] === 'passagers_taxi' && end($codes) === 'scenario_utilisateur');
$t('…présents aussi dans recommendations_days, avec une durée', is_int($r['recommendations_days']['voisin_palier']) || is_float($r['recommendations_days']['voisin_palier']));
$row = $r['rows'][$count];
$t('condition générée à partir des expositions et de la limite', $row['condition'] === 'Exposition : 1 h à 1 m · limite de dose : 1 mSv');
$t('…avec décimales françaises et note entre parenthèses', $r['rows'][$count + 1]['condition'] === 'Exposition : 0,5 h à 0,5 m (trajet) · limite de dose : 2,5 mSv');

$r4 = RivEngine\calculate($base + ['cure_count' => 4]);
$t('limite partagée entre les cures (défaut) : durée plus longue et mention « répartie sur 4 cures »', $r4['recommendations_days']['voisin_palier'] >= $r['recommendations_days']['voisin_palier'] && str_contains($r4['rows'][$count]['condition'], '(répartie sur 4 cures)'));
$t('shares_limit_across_cures=false : durée inchangée et mention « par cure, non cumulée »', $r4['recommendations_days']['passagers_taxi'] === $r['recommendations_days']['passagers_taxi'] && str_contains($r4['rows'][$count + 1]['condition'], '(par cure, non cumulée)'));
$t('scénarios historiques inchangés par l\'ajout', array_slice($r['recommendations_days'], 0, $count) === array_slice(RivEngine\calculate($base)['recommendations_days'], 0, $count));

// Erreurs de saisie dans le fichier : exception explicite (numéro et code du scénario)
$check = static function (string $name, array $mutated, string $expected) use ($file, $t): void {
    file_put_contents($file, json_encode($mutated, JSON_UNESCAPED_UNICODE));
    // le cache est par processus : on contrôle la validation avec un processus PHP dédié
    $out = shell_exec('php -r ' . escapeshellarg('define("RIV_ENGINE", 1); require ' . var_export(dirname($file, 2) . '/src/bootstrap.php', true) . '; try { RivEngine\\scenarios(); echo "OK"; } catch (RuntimeException $e) { echo $e->getMessage(); }'));
    $t($name, str_contains((string) $out, $expected));
};
$good = ['audience_code' => 'x', 'label' => 'X', 'exposures' => [['hours' => 1, 'distance' => 1]], 'limit' => 1];
$check('doublon d\'audience_code refusé', [$good, $good], 'en double');
$check('audience_code réservé refusé', [['audience_code' => 'scenario_utilisateur'] + $good], 'réservé');
$check('audience_code invalide refusé', [['audience_code' => 'Voisin Palier'] + $good], 'audience_code manquant ou invalide');
$check('label manquant refusé', [['label' => ''] + $good], 'label manquant');
$check('exposition sans durée refusée', [['exposures' => [['hours' => 0, 'distance' => 1]]] + $good], 'hours > 0');
$check('limite nulle refusée', [['limit' => 0] + $good], 'limit doit être');
$check('fichier vide refusé', [], 'aucun scénario');

foreach (['src', 'data'] as $dir) { array_map('unlink', glob("{$tmp}/{$dir}/*")); rmdir("{$tmp}/{$dir}"); }
unlink("{$tmp}/VERSION"); rmdir($tmp);
echo "\n" . ($fail ? "{$fail} échec(s)" : 'tout réussi') . "\n";
exit($fail ? 1 : 0);
