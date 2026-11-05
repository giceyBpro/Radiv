<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// Champs de saisie (data/fields.json) et ajout d'une règle de calcul. Le moteur est copié dans un dossier temporaire :
//   - on y déclare un nouveau champ, et on y ajoute le profil EXEMPLE écrit dans les commentaires de src/profiles.php
//     (texte extrait du fichier lui-même : si l'exemple du guide cesse de fonctionner, ce test échoue) ;
//   - on contrôle que l'isotope qui l'utilise est calculé, validé et annoncé aux interfaces sans autre modification ;
//   - on contrôle les erreurs de saisie de data/fields.json.
// Usage : php core/tests/test-fields.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/riv-fields-' . bin2hex(random_bytes(4));
mkdir($tmp);
foreach (['src', 'data'] as $dir) {
    mkdir("{$tmp}/{$dir}");
    foreach (scandir(__DIR__ . "/../{$dir}") as $f) if ($f[0] !== '.') copy(__DIR__ . "/../{$dir}/{$f}", "{$tmp}/{$dir}/{$f}");
}
copy(__DIR__ . '/../VERSION', "{$tmp}/VERSION");

// --- 1. Données : un champ « number » (celui du guide), un champ « choice », deux isotopes ---------------------------------
$fieldsFile = "{$tmp}/data/fields.json";
$fields = json_decode((string) file_get_contents($fieldsFile), true);
$baseCount = count($fields);
$fields[] = ['code' => 'poids_kg', 'label' => 'Poids du patient (kg)', 'unit' => 'kg', 'type' => 'number', 'min' => 1, 'step' => 'any'];
$fields[] = ['code' => 'voie', 'label' => "Voie d'administration", 'type' => 'choice', 'options' => [['value' => 'iv', 'label' => 'Intraveineuse'], ['value' => 'oral', 'label' => 'Orale']]];
file_put_contents($fieldsFile, json_encode($fields, JSON_UNESCAPED_UNICODE));

$isotopes = json_decode((string) file_get_contents("{$tmp}/data/isotopes.json"), true);
$base = ['label' => 'Essai', 'reference' => 'essai', 'remark' => '-', 'situation' => '', 'period_hours' => 100];
$isotopes[] = ['api_code' => 'essai_poids', 'profile' => 'poids_reference'] + $base;
$isotopes[] = ['api_code' => 'essai_voie', 'profile' => 'avec_voie'] + $base;
$isotopes[] = ['api_code' => 'essai_standard'] + $base;
file_put_contents("{$tmp}/data/isotopes.json", json_encode($isotopes, JSON_UNESCAPED_UNICODE));

// --- 2. Profil : l'exemple EXACT du guide de src/profiles.php, décommenté ; plus un profil avec le champ « choice » ---------
$profilesFile = "{$tmp}/src/profiles.php";
$source = (string) file_get_contents($profilesFile);
$lines = explode("\n", $source);
$example = [];
$inside = false;
foreach ($lines as $line) {
    if (str_contains($line, '$poidsReference = [')) $inside = true;
    if ($inside) $example[] = preg_replace('#^\s*//   ?#', '', $line);
    if ($inside && str_contains($line, '] + $standard;')) break;
}
$example = implode("\n", $example);
$withChoice = <<<'PHP'
    $avecVoie = [
        'inputs' => ['dose_rate' => 'required', 'voie' => 'required', 'user_period_days' => 'optional'],
    ] + $standard;
PHP;
$register = "    return \$profiles = ['standard' => \$standard, 'benign_iodine' => \$benignIodine, 'free_period' => \$freePeriod];";
if (!str_contains($source, $register) || $example === '') { echo "ÉCHEC exemple ou point d'insertion introuvable dans profiles.php\n"; exit(1); }
$source = str_replace($register, $example . "\n" . $withChoice . "\n    return \$profiles = ['standard' => \$standard, 'benign_iodine' => \$benignIodine, 'free_period' => \$freePeriod, 'poids_reference' => \$poidsReference, 'avec_voie' => \$avecVoie];", $source);
file_put_contents($profilesFile, $source);
require "{$tmp}/src/bootstrap.php";

$fail = 0;
$t = static function (string $name, bool $ok) use (&$fail): void { echo ($ok ? 'OK    ' : 'ÉCHEC ') . $name . "\n"; if (!$ok) $fail++; };
$j = static fn ($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
$size = ['patient_size_cm' => 170];

// --- 3. Champs annoncés ------------------------------------------------------------------------------------------------------
$declared = array_column(RivEngine\input_fields(), null, 'code');
$t('table des champs : les champs de base et les champs ajoutés sont décrits', count($declared) === $baseCount + 2 && $declared['poids_kg']['unit'] === 'kg' && $declared['poids_kg']['type'] === 'number' && $declared['voie']['options'][1]['value'] === 'oral');
$inputs = RivEngine\isotope_inputs();
$t('saisie annoncée : le champ ajouté est « required » pour l\'isotope qui l\'utilise, « ignored » pour tous les autres', $inputs['essai_poids']['poids_kg'] === 'required' && $inputs['essai_poids']['dose_rate'] === 'required' && $inputs['radium223']['poids_kg'] === 'ignored' && $inputs['iode131_benin']['voie'] === 'ignored');
$t('…les isotopes existants ne changent pas pour les champs de base', $inputs['iode131_benin']['benign_activity_mbq'] === 'required' && $inputs['radium223']['dose_rate'] === 'required');

// --- 4. Règle de l'exemple : débit ramené à 70 kg -------------------------------------------------------------------------
$ref = RivEngine\calculate(['isotope_code' => 'essai_standard', 'dose_rate' => 50] + $size);
$a = RivEngine\calculate(['isotope_code' => 'essai_poids', 'dose_rate' => 50, 'poids_kg' => 70] + $size);
$b = RivEngine\calculate(['isotope_code' => 'essai_poids', 'dose_rate' => 25, 'poids_kg' => 140] + $size);
$t('règle de l\'exemple : à 70 kg, résultat identique à la règle standard', $ref['ok'] && $a['ok'] && $j($ref['recommendations_days']) === $j($a['recommendations_days']));
$t('…et 25 µSv/h à 140 kg équivaut à 50 µSv/h à 70 kg', $b['ok'] && $j($b['recommendations_days']) === $j($a['recommendations_days']) && abs($b['computed_dose_rate'] - 50.0) < 1e-9);
$e = RivEngine\calculate(['isotope_code' => 'essai_poids', 'dose_rate' => 50] + $size);
$t('…champ obligatoire absent : erreur du profil, avec le code de l\'isotope et du champ', !$e['ok'] && str_contains($j($e['errors']), 'Pour essai_poids, poids_kg') && str_contains($e['error']['reason'], 'poids_kg') && in_array('poids_kg', $e['error']['expected_payload']['isotope_specific_required'], true));
$t('…le champ est ignoré par les autres isotopes (aucun effet)', RivEngine\calculate(['isotope_code' => 'essai_standard', 'dose_rate' => 50, 'poids_kg' => 3] + $size)['recommendations_days'] === $ref['recommendations_days']);

// --- 5. Champ « choice » -------------------------------------------------------------------------------------------------
$ok = RivEngine\calculate(['isotope_code' => 'essai_voie', 'dose_rate' => 50, 'voie' => 'oral'] + $size);
$missing = RivEngine\calculate(['isotope_code' => 'essai_voie', 'dose_rate' => 50] + $size);
$bad = RivEngine\calculate(['isotope_code' => 'essai_voie', 'dose_rate' => 50, 'voie' => 'rectale'] + $size);
$t('liste de choix : valeur valide acceptée', $ok['ok'] === true && $j($ok['recommendations_days']) === $j($ref['recommendations_days']));
$t('…absente alors qu\'obligatoire : erreur qui liste les valeurs possibles', !$missing['ok'] && str_contains($j($missing['errors']), 'voie est obligatoire') && str_contains($j($missing['errors']), 'iv, oral'));
$t('…hors liste : erreur', !$bad['ok'] && str_contains($j($bad['errors']), 'voie doit être l\'une des valeurs suivantes : iv, oral'));
$t('…ignorée par les autres isotopes : aucune erreur même avec une valeur invalide', RivEngine\calculate(['isotope_code' => 'essai_standard', 'dose_rate' => 50, 'voie' => 'rectale'] + $size)['ok'] === true);

// --- 6. Erreurs de saisie dans data/fields.json : exception explicite (processus dédié : les données sont mémorisées) ------
$check = static function (string $name, array $mutated, string $expected) use ($fieldsFile, $tmp, $t): void {
    file_put_contents($fieldsFile, json_encode($mutated, JSON_UNESCAPED_UNICODE));
    $out = shell_exec('php -r ' . escapeshellarg('define("RIV_ENGINE", 1); require ' . var_export($tmp . '/src/bootstrap.php', true) . '; try { RivEngine\\input_fields(); echo "OK"; } catch (RuntimeException $e) { echo $e->getMessage(); }'));
    $t($name, str_contains((string) $out, $expected));
};
$good = array_slice($fields, 0, $baseCount);
$extra = ['code' => 'x_test', 'label' => 'X', 'type' => 'number'];
$check('doublon de code refusé', [...$good, $extra, $extra], 'en double');
$check('code invalide refusé', [...$good, ['code' => 'Poids Kg'] + $extra], 'code manquant ou invalide');
$check('champ commun du calcul refusé comme code', [...$good, ['code' => 'patient_size_cm'] + $extra], 'champ commun');
$check('label manquant refusé', [...$good, ['label' => ''] + $extra], 'label manquant');
$check('type inconnu refusé', [...$good, ['type' => 'texte'] + $extra], 'type doit être');
$check('liste de choix sans options refusée', [...$good, ['type' => 'choice'] + $extra], 'options');
$check('option incomplète refusée', [...$good, ['type' => 'choice', 'options' => [['value' => 'a']]] + $extra], 'chaque option exige');
$check('borne non numérique refusée', [...$good, ['min' => 'abc'] + $extra], 'min doit être un nombre');
$check('champ de base retiré : refusé', array_slice($good, 1), 'champ de base');
$check('fichier valide accepté', [...$good, $extra], 'OK');

foreach (['src', 'data'] as $dir) { array_map('unlink', glob("{$tmp}/{$dir}/*")); rmdir("{$tmp}/{$dir}"); }
unlink("{$tmp}/VERSION"); rmdir($tmp);
echo "\n" . ($fail ? "{$fail} échec(s)" : 'tout réussi') . "\n";
exit($fail ? 1 : 0);
