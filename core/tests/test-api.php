<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// Contrôles de l'interface d'API minimale (sans serveur web). Usage : php core/tests/test-api.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../api/handler.php';

use RivEngine\Api;

$fail = 0;
$t = static function (string $name, bool $ok) use (&$fail): void { echo ($ok ? 'OK    ' : 'ÉCHEC ') . $name . "\n"; if (!$ok) $fail++; };

[$s, $d] = Api\handle('GET', '/config');
$t('config : 200 et isotopes', $s === 200 && count($d['isotopes']) === 15 && $d['cure_options_by_isotope']['psma_177lu'] === [1, 4, 6]);
$t('config : saisie attendue par isotope (iode bénin, période libre, standard)', $d['isotope_inputs']['iode131_benin']['dose_rate'] === 'ignored' && $d['isotope_inputs']['iode131_benin']['benign_activity_mbq'] === 'required'
    && $d['isotope_inputs']['non_defini']['user_period_days'] === 'required' && $d['isotope_inputs']['radium223'] === ['dose_rate' => 'required', 'benign_activity_mbq' => 'ignored', 'benign_fixation_pct' => 'ignored', 'user_period_days' => 'optional']
    && count($d['isotope_inputs']) === count($d['isotopes']));
[$s, $d] = Api\handle('POST', 'calculate', '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":150}');
$t('calcul valide : 200 et 7 scénarios + utilisateur', $s === 200 && $d['ok'] === true && count($d['rows']) === 8);
[$s, $d] = Api\handle('POST', 'calculate', '{"isotope_code":"x"}');
$t('entrées invalides : 400', $s === 400 && $d['ok'] === false);
[$s] = Api\handle('POST', 'calculate', '{pas du json');
$t('JSON invalide : 400', $s === 400);
[$s] = Api\handle('GET', 'calculate');
$t('mauvaise méthode : 405', $s === 405);
[$s] = Api\handle('GET', 'inconnu');
$t('route inconnue : 404', $s === 404);
[$s] = Api\handle('POST', 'calculate', str_repeat(' ', 70000));
$t('corps trop gros : 413', $s === 413);
RivEngine\set_rounding_mode('floor');
[, $f] = Api\handle('POST', 'calculate', '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":150}');
RivEngine\set_rounding_mode('round');
[, $r] = Api\handle('POST', 'calculate', '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":150}');
$t('arrondi réglable (floor ≤ round)', $f['rows'][0]['value'] <= $r['rows'][0]['value']);
echo "\n" . ($fail ? "{$fail} échec(s)" : 'tout réussi') . "\n";
exit($fail ? 1 : 0);
