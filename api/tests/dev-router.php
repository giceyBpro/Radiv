<?php
// Routeur du serveur PHP intégré, uniquement pour le développement local:
//   php -S 127.0.0.1:8081 -t . api/tests/dev-router.php   (depuis la racine du dépôt)
// Reproduit ce que font api/.htaccess (tout /api/* → index.php) et les règles racine de /health et /auth;
// le reste est servi tel quel (pages HTML statiques).
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/health' || $path === '/auth' || str_starts_with($path, '/auth/') || str_starts_with($path, '/api/')) {
    require __DIR__ . '/../index.php';
    return true;
}
return false;
