<?php
// Façade publique de l'API : la SEULE partie PHP servie par le site. Toute la logique, la configuration et les
// données sont dans le backend (src/, assets/, config/, data/), qui peut se trouver dans la racine web (par défaut
// <racine>/backend) ou ailleurs, et qui est protégé par son propre .htaccess dans tous les cas.
//
// Emplacement du backend, dans l'ordre :
//   1. la variable d'environnement RADIV_BACKEND (SetEnv dans un .htaccess, ou réglage du serveur) ;
//   2. le fichier api/backend.php (généré par l'installation quand le backend n'est pas à l'emplacement par défaut :
//      il contient seulement « <?php return '/chemin/absolu'; ») ;
//   3. le dossier <racine web>/backend.
declare(strict_types=1);

$backend = (string) (getenv('RADIV_BACKEND') ?: '');
if ($backend === '' && is_file(__DIR__ . '/backend.php')) {
    $pointer = include __DIR__ . '/backend.php';
    if (is_string($pointer)) $backend = $pointer;
}
if ($backend === '') $backend = dirname(__DIR__) . '/backend';
$backend = rtrim($backend, '/\\');

if (!is_file($backend . '/src/app.php')) {
    error_log('[API] backend introuvable : ' . $backend);
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"Not found"}';
    exit;
}

define('RADIV_ENTRY', true);
define('RADIV_BACKEND_DIR', $backend);
define('RADIV_WEB_ROOT', dirname(__DIR__));
require $backend . '/src/app.php';
