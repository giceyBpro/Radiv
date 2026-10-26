<?php
// Répond comme l'API du site à partir d'un dossier de moteur donné (API minimale core/api/handler.php) : sert à
// tests/test-xplore.js, qui exécute le script du modèle Xplore sans réseau. Usage : php xplore-engine-cli.php <dossier_core> <METHODE> <route> [corps]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$script, $core, $method, $route] = $argv + [null, null, 'GET', 'config'];
require "{$core}/api/handler.php";
[$status, $data] = RivEngine\Api\handle($method, $route, (string) ($argv[4] ?? ''));
echo json_encode(['status' => $status, 'body' => RivEngine\Api\encode($data)]);
