<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// Lit sur stdin un tableau JSON de payloads, écrit sur stdout le tableau des résultats de
// calculate(). Sert uniquement à core/tests/test-calcul.js (jamais exposé par le serveur web).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../api/handler.php';

// Arrondi : variable RESTRICTION_ROUNDING_MODE positionnée par test-calcul.js.
RivEngine\set_rounding_mode(static fn () => getenv('RESTRICTION_ROUNDING_MODE') ?: null);

$payloads = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$results = [];
foreach ($payloads as $payload) {
    // Règle de l'API: un JSON scalaire (5, "x") n'a pas de champs → payload vide.
    $results[] = RivEngine\calculate(is_array($payload) ? $payload : []);
}
echo RivEngine\Api\encode($results);
