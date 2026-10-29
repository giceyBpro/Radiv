<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// Profils de calcul (src/profiles.php) : un isotope AJOUTÉ DANS LES DONNÉES, avec un profil existant, se comporte comme
// l'isotope d'origine, sans toucher au code. Le moteur est copié dans un dossier temporaire pour y ajouter des isotopes.
// Usage : php core/tests/test-profiles.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/riv-profiles-' . bin2hex(random_bytes(4));
mkdir($tmp);
foreach (['src', 'data'] as $dir) {
    mkdir("{$tmp}/{$dir}");
    foreach (scandir(__DIR__ . "/../{$dir}") as $f) if ($f[0] !== '.') copy(__DIR__ . "/../{$dir}/{$f}", "{$tmp}/{$dir}/{$f}");
}
copy(__DIR__ . '/../VERSION', "{$tmp}/VERSION");
$isotopes = json_decode((string) file_get_contents("{$tmp}/data/isotopes.json"), true);
$base = ['label' => 'Essai', 'reference' => 'essai', 'remark' => '-', 'situation' => ''];
$isotopes[] = ['api_code' => 'essai_benin', 'period_hours' => 122.4, 'profile' => 'benign_iodine'] + $base;
$isotopes[] = ['api_code' => 'essai_libre', 'period_hours' => null, 'profile' => 'free_period'] + $base;
$isotopes[] = ['api_code' => 'essai_standard', 'period_hours' => 100] + $base;
$isotopes[] = ['api_code' => 'essai_inconnu', 'period_hours' => 10, 'profile' => 'profil_qui_n_existe_pas'] + $base;
file_put_contents("{$tmp}/data/isotopes.json", json_encode($isotopes, JSON_UNESCAPED_UNICODE));
require "{$tmp}/src/bootstrap.php";

$fail = 0;
$t = static function (string $name, bool $ok) use (&$fail): void { echo ($ok ? 'OK    ' : 'ÉCHEC ') . $name . "\n"; if (!$ok) $fail++; };
$j = static fn ($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
$rows = static fn (array $r): array => $r['recommendations_days'];

$benin = ['benign_activity_mbq' => 400, 'benign_fixation_pct' => 20, 'patient_size_cm' => 170];
$a = RivEngine\calculate(['isotope_code' => 'iode131_benin'] + $benin);
$b = RivEngine\calculate(['isotope_code' => 'essai_benin'] + $benin);
$t('isotope ajouté (profil iode bénin) : mêmes durées et même débit que l\'iode bénin de même période', $a['ok'] && $b['ok'] && $j($rows($a)) === $j($rows($b)) && $a['computed_dose_rate'] === $b['computed_dose_rate']);
$e = RivEngine\calculate(['isotope_code' => 'essai_benin', 'patient_size_cm' => 170]);
$t('…sans activité ni fixation : erreurs du profil, avec le code de l\'isotope', !$e['ok'] && str_contains($j($e['errors']), 'Pour essai_benin, benign_activity_mbq') && str_contains($e['error']['reason'], 'Pour essai_benin'));
$t('…champs attendus propres au profil', $e['error']['expected_payload']['isotope_specific_required'][0] === 'benign_activity_mbq');

$f = RivEngine\calculate(['isotope_code' => 'essai_libre', 'dose_rate' => 50, 'patient_size_cm' => 170]);
$t('profil période libre : période obligatoire', !$f['ok'] && str_contains($j($f['errors']), 'Avec isotope_code=essai_libre, user_period_days'));
$g = RivEngine\calculate(['isotope_code' => 'essai_libre', 'dose_rate' => 50, 'patient_size_cm' => 170, 'user_period_days' => 3]);
$t('…avec la période : calcul normal', $g['ok'] === true && $g['effective_days'] === 3.0);

$s = RivEngine\calculate(['isotope_code' => 'essai_standard', 'dose_rate' => 50, 'patient_size_cm' => 170]);
$t('sans profil : standard (débit saisi, période de l\'isotope)', $s['ok'] === true && abs($s['effective_hours'] - 100) < 1e-9);

try {
    RivEngine\calculate(['isotope_code' => 'essai_inconnu', 'dose_rate' => 50, 'patient_size_cm' => 170]);
    $t('profil inconnu : erreur de données explicite', false);
} catch (RuntimeException $ex) {
    $t('profil inconnu : erreur de données explicite (jamais un calcul silencieux)', str_contains($ex->getMessage(), 'profil_qui_n_existe_pas') && str_contains($ex->getMessage(), 'essai_inconnu'));
}
$t('les autres isotopes ne sont pas affectés par le profil inconnu', RivEngine\calculate(['isotope_code' => 'radium223', 'dose_rate' => 100, 'patient_size_cm' => 150])['ok'] === true);
$t('le profil n\'apparaît pas dans la réponse', !array_key_exists('profile', RivEngine\isotopes()[0]) && !str_contains($j($b['selected']), 'profile'));

foreach (['src', 'data'] as $dir) { array_map('unlink', glob("{$tmp}/{$dir}/*")); rmdir("{$tmp}/{$dir}"); }
unlink("{$tmp}/VERSION"); rmdir($tmp);
echo "\n" . ($fail ? "{$fail} échec(s)" : 'tout réussi') . "\n";
exit($fail ? 1 : 0);
