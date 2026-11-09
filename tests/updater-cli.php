<?php
// Pilote de tests/test-update-check.js : appelle Updater\check_update() (jamais exposé par le serveur web).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RADIV_ENTRY', true);
foreach (['json', 'config', 'store', 'http', 'updater'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";
echo json_encode(Radiv\Updater\check_update('ghp_validtokenvalidtokenvalid1234', $argv[1]));
