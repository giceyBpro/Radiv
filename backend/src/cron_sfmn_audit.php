<?php
// Exécution planifiée de la comparaison RadIV / SFMN (tâche CRON de l'hébergement, facultative : sans elle, la première visite du site après 1 h
// déclenche le même travail). En ligne de commande uniquement, et seulement avec l'argument « run » (sans argument : aucune action, sortie vide).
//   php backend/src/cron_sfmn_audit.php run        silencieux sauf anomalie ou erreur (l'hébergeur envoie la sortie par e-mail s'il est configuré pour)
//   php backend/src/cron_sfmn_audit.php run -v     affiche chaque pas
// Respecte l'interrupteur de l'administration (désactivée : ne fait rien), le délai minimal entre deux appels à la SFMN et le verrou commun.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== 'run') exit;

define('RADIV_ENTRY', true);
require is_file(__DIR__ . '/../core/src/bootstrap.php') ? __DIR__ . '/../core/src/bootstrap.php' : __DIR__ . '/../../core/src/bootstrap.php';
foreach (['json', 'config', 'store', 'http', 'contact', 'sfmn', 'sfmn_audit'] as $f) require __DIR__ . "/lib/{$f}.php";

\Radiv\Config\boot();
@set_time_limit(0);
$verbose = in_array('-v', $argv, true);
$log = \Radiv\SfmnAudit\batch();
$noisy = array_filter($log, static fn (string $l): bool => str_contains($l, 'erreur') || (str_contains($l, 'jour_termine') && !str_contains($l, ':0_anomalie')));
if ($verbose) echo implode("\n", $log) . "\n";
elseif ($noisy) echo '[SFMN-AUDIT] ' . implode(' ; ', $log) . "\n";
