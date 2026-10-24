<?php
// ===== EXEMPLE AUTONOME : SERVIR L'API DU MOTEUR =====
// Pour essayer sans rien installer :  php -S 127.0.0.1:8080 core/api/index.php
//   curl http://127.0.0.1:8080/config
//   curl -X POST http://127.0.0.1:8080/calculate -d '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":170}'
// En production, placez ce dossier derrière votre propre routage et ajoutez vos protections (limitation de débit...).
declare(strict_types=1);

require __DIR__ . '/handler.php';

// Mode d'arrondi : variable d'environnement RIV_ROUNDING_MODE ("round" par défaut, ou "floor").
RivEngine\set_rounding_mode(static fn () => getenv('RIV_ROUNDING_MODE') ?: 'round');

$route = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
[$status, $data] = RivEngine\Api\handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $route, (string) file_get_contents('php://input'));

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo RivEngine\Api\encode($data);
