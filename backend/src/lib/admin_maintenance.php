<?php
// Carte « Maintenance » de l'onglet Site de l'administration (voir maintenance.php) : mettre le site en maintenance
// (accueil seulement) ou en maintenance complète (API de calcul et contact arrêtés aussi), ou le remettre en service.
// Mêmes protections que les mises à jour : session, CSRF, connexion Google récente.
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Http;
use Radiv\Maintenance;
use Radiv\Session;
use Radiv\Store;
use Radiv\Updater;

function maintenance_card(string $csrf): string
{
    $state = Maintenance\state();
    $labels = ['off' => ['en service', 'good'], 'site' => ['maintenance (accueil)', 'warn'], 'full' => ['maintenance complète (accueil + API)', 'bad']];
    [$label, $class] = $labels[$state['mode']];
    $button = static fn (string $mode, string $text, bool $secondary = false): string =>
        '<form method="post" action="/auth/maintenance" style="margin:0"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="mode" value="' . $mode . '">'
        . '<button type="submit"' . ($secondary ? ' class="secondary"' : '') . '>' . h($text) . '</button></form>';
    $out = '<section class="card"><h2>Maintenance</h2><p class="big"><span class="badge ' . $class . '">' . h($label) . '</span></p>';
    if ($state['mode'] !== 'off') {
        $out .= '<p class="small">Depuis le ' . h($state['since']) . ' (' . h($state['by']) . ').</p>';
    }
    $out .= '<p class="small"><strong>Maintenance</strong> : la page d\'accueil n\'affiche que « site en maintenance » avec le lien vers l\'outil de la SFMN ; l\'API continue de fonctionner. '
        . '<strong>Maintenance complète</strong> : en plus, le calcul (<code>/api/calculate</code>) et le formulaire de contact répondent 503. '
        . '<code>/auth</code>, <code>/health</code> et la configuration en lecture seule restent disponibles.</p>'
        . '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    if ($state['mode'] !== 'site') $out .= $button('site', 'Activer la maintenance', true);
    if ($state['mode'] !== 'full') $out .= $button('full', 'Maintenance complète (arrête aussi l\'API)', true);
    if ($state['mode'] !== 'off') $out .= $button('off', 'Remettre le site en service');
    return $out . '</div></section>';
}

function do_maintenance(): bool
{
    $admin = guard_post(true);
    if ($admin === null) return false;
    if (!Store\rate_limit_hit('adminmaint', Http\client_ip(), 20)) return false;
    $mode = (string) ($_POST['mode'] ?? '');
    if (!in_array($mode, Maintenance\MODES, true) || !Maintenance\set_mode($mode, $admin['email'])) {
        Session\flash_set(['ok' => false, 'message' => 'Changement de mode impossible.']);
    } else {
        Updater\journal(['action' => 'maintenance', 'by' => $admin['email'], 'mode' => $mode, 'ok' => true]);
        Session\flash_set(['ok' => true, 'message' => ['off' => 'Site remis en service.', 'site' => 'Maintenance activée : l\'accueil affiche l\'avis de maintenance, l\'API continue de fonctionner.', 'full' => 'Maintenance complète activée : l\'accueil et l\'API de calcul sont arrêtés.'][$mode]]);
    }
    Http\redirect(home_url(), 303);
    return true;
}
