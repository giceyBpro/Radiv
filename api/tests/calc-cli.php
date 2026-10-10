<?php
// Lit sur stdin un tableau JSON de payloads, écrit sur stdout le tableau des résultats de
// calculate(). Sert uniquement à tests/test-calcul.js (jamais exposé par le serveur web).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../calculation.php';
require __DIR__ . '/../lib/json.php';

$payloads = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$results = [];
foreach ($payloads as $payload) {
    // Règle de l'API: un JSON scalaire (5, "x") n'a pas de champs → payload vide.
    $results[] = Radiv\Calculation\calculate(is_array($payload) ? $payload : []);
}
echo Radiv\Json\encode($results);
