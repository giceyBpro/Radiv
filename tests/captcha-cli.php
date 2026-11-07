<?php
// Pilote de tests/test-captcha.js : émet un défi, le résout, vérifie une charge (jamais exposé par le serveur web).
// Usage : php captcha-cli.php <dossier-var> challenge | solve <défi-json> | verify <charge> <heure>
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RADIV_ENTRY', true);
putenv('DATA_DIR=' . $argv[1]);
foreach (['json', 'config', 'captcha'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";
$cmd = $argv[2];
if ($cmd === 'challenge') { echo json_encode(Radiv\Captcha\challenge((int) $argv[3])); }
elseif ($cmd === 'verify') { echo Radiv\Captcha\verify($argv[3], (int) $argv[4]) ? 'oui' : 'non'; }
