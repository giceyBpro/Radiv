<?php
// Routeur du serveur PHP intégré, uniquement pour le développement local (depuis la racine du dépôt) :
//   php -S 127.0.0.1:8081 -t public tests/dev-router.php
// Reproduit ce que font public/api/.htaccess (tout /api/* → façade api/index.php) et les règles racine de /health et /auth ;
// le reste est servi tel quel (pages HTML statiques de public/). Le backend du dépôt (backend/) est utilisé directement.
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/health' || $path === '/auth' || str_starts_with($path, '/auth/') || str_starts_with($path, '/api/')) {
    putenv('RADIV_BACKEND=' . dirname(__DIR__) . '/backend');
    require dirname(__DIR__) . '/public/api/index.php';
    return true;
}
return false;
