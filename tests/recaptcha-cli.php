<?php
// Appelle Contact\verify_recaptcha() ; réservé à tests/test-recaptcha.js (jamais exposé par le serveur web).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RADIV_ENTRY', true);
foreach (['json', 'config', 'store', 'http', 'measurements', 'contact'] as $f) require __DIR__ . "/../backend/src/lib/{$f}.php";
echo Radiv\Contact\verify_recaptcha('jeton', '203.0.113.5') ? 'oui' : 'non';
