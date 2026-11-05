<?php
// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// ===== EXEMPLE AUTONOME : SERVIR L'API ET LA PAGE DU MOTEUR =====
// Pour essayer sans rien installer :  php -S 127.0.0.1:8080 demo/api/index.php
//   http://127.0.0.1:8080/   la page de saisie (web/index.html)
//   curl http://127.0.0.1:8080/config
//   curl -X POST http://127.0.0.1:8080/calculate -d '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":170}'
// En production, placez ce dossier derrière votre propre routage et ajoutez vos protections (limitation de débit...).
// Le mode d'arrondi est celui de data/settings.json ; pour le surcharger : RivEngine\set_rounding_mode('floor').
declare(strict_types=1);

// Dépôt : handler.php est à côté ; site installé par install-core.php : engine/api/handler.php.   Le moteur lui-même est dans core/.
require is_file(__DIR__ . '/handler.php') ? __DIR__ . '/handler.php' : __DIR__ . '/../engine/api/handler.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Route demandée. Si le script est installé dans un sous-dossier (ex. /riv/api/config), on ne garde que ce qui suit le dossier.
$route = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$folder = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($folder !== '' && strpos($route, $folder . '/') === 0) $route = substr($route, strlen($folder));

// La page de saisie (un seul fichier HTML, plus ses textes dans config.js) est servie à la racine pour l'essai ;
// en production, servez plutôt web/ par le serveur web lui-même.
$pages = ['/' => 'index.html', '/index.html' => 'index.html', '/config.js' => 'config.js'];
if ($method === 'GET' && isset($pages[$route])) {
    $file = $pages[$route];
    header('Content-Type: ' . ($file === 'config.js' ? 'text/javascript' : 'text/html') . '; charset=utf-8');
    readfile(__DIR__ . '/../web/' . $file);
    exit;
}

[$status, $data] = RivEngine\Api\handle($method, $route, (string) file_get_contents('php://input'));

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo RivEngine\Api\encode($data);
