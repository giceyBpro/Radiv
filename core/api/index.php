<?php
// ===== EXEMPLE AUTONOME : SERVIR L'API DU MOTEUR =====
// Pour essayer sans rien installer :  php -S 127.0.0.1:8080 core/api/index.php
//   http://127.0.0.1:8080/   (page minimale)
//   curl http://127.0.0.1:8080/config
//   curl -X POST http://127.0.0.1:8080/calculate -d '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":170}'
// En production, placez ce dossier derrière votre propre routage et ajoutez vos protections (limitation de débit...).
declare(strict_types=1);

require __DIR__ . '/handler.php';

// Mode d'arrondi : celui de core/data/settings.json (surcharge possible : RivEngine\set_rounding_mode('floor')).

$route = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
// Page minimale (core/web) servie à la racine ; en production, servez plutôt web/ par le serveur web lui-même.
$static = ['/' => 'index.html', '/index.html' => 'index.html', '/config.js' => 'config.js', '/app.js' => 'app.js', '/style.css' => 'style.css',
    '/print.html' => 'print.html', '/patient.html' => 'patient.html', '/sheets.js' => 'sheets.js', '/print.css' => 'print.css'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($static[$route])) {
    $types = ['html' => 'text/html', 'js' => 'text/javascript', 'css' => 'text/css'];
    header('Content-Type: ' . $types[pathinfo($static[$route], PATHINFO_EXTENSION)] . '; charset=utf-8');
    readfile(__DIR__ . '/../web/' . $static[$route]);
    exit;
}

[$status, $data] = RivEngine\Api\handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $route, (string) file_get_contents('php://input'));

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo RivEngine\Api\encode($data);
