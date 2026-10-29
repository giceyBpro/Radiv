<?php
// Carte « Modèles Xplore » de l'onglet Site (voir xplore_access.php) : rendre la page /xplore et les fichiers XML accessibles ou non.
// Mêmes protections que les autres actions : session, CSRF, connexion Google récente.
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Http;
use Radiv\Session;
use Radiv\Store;
use Radiv\Xplore;

function xplore_card(string $csrf): string
{
    $off = Xplore\disabled();
    $info = Xplore\info();
    $out = '<section class="card"><h2>Modèles Xplore</h2><p class="big"><span class="badge ' . ($off ? 'bad' : 'good') . '">' . ($off ? 'inaccessibles' : 'accessibles') . '</span></p>'
        . ($off && $info['since'] !== '' ? '<p class="small">Depuis le ' . h($info['since']) . ' (' . h($info['by']) . ').</p>' : '')
        . '<p class="small">La page <code>/xplore</code> et les fichiers <code>downloads/*.xml</code> répondent 404 quand l\'accès est coupé (règle posée dans le <code>.htaccess</code> de la racine web, retirée à la réactivation).</p>'
        . '<p class="small">Téléchargement réservé à l\'administration (fonctionne même si l\'accès public est coupé) : '
        . implode(' · ', array_map(function ($n) { return '<a href="/auth/xplore/' . h($n) . '">' . h($n) . '</a>'; }, array_keys(Xplore\files()))) . '</p>'
        . '<form method="post" action="/auth/xplore" style="margin:0"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="enabled" value="' . ($off ? '1' : '0') . '">'
        . '<button type="submit"' . ($off ? '' : ' class="secondary"') . '>' . ($off ? 'Rendre accessibles' : 'Rendre inaccessibles') . '</button></form></section>';
    return $out;
}

// Envoie un modèle XML à un administrateur connecté (liste fermée : jamais de chemin construit à partir de la requête).
function do_xplore_file(string $name): bool
{
    $files = Xplore\files();
    if (Session\admin() === null || !isset($files[$name])) return false;
    $content = @file_get_contents($files[$name]);
    if ($content === false) return false;
    http_response_code(200);
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . strlen($content));
    echo $content;
    return true;
}

function do_xplore(): bool
{
    $admin = guard_post(true);
    if ($admin === null) return false;
    if (!Store\rate_limit_hit('adminxplore', Http\client_ip(), 10)) return false;
    $enabled = ($_POST['enabled'] ?? '') === '1';
    Session\flash_set(Xplore\set_enabled($enabled, $admin['email']));
    Http\redirect(home_url(), 303);
    return true;
}
