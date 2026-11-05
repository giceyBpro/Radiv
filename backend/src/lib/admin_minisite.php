<?php
// Onglet « Mini-site » de l'administration (/auth/minisite) : la page minimale de l'application simple (demo/web/index.html + config.js),
// telle qu'elle fonctionne seule, mais encapsulée dans l'administration, derrière la session.
//
// Elle sert à essayer le moteur lui-même (intitulés et scénarios du moteur, sans la surcouche ni les pages de RadIV), y compris pendant
// une maintenance : ses appels passent par /auth/minisite/api/*, jamais par l'API publique (donc rien n'est enregistré dans les mesures).
// Le fichier HTML est celui de l'application simple, non modifié : seuls l'en-tête d'administration, l'adresse de config.js et un nonce sur son script
// en ligne (la page d'administration refuse tout autre script) sont ajoutés. Les appels de calcul sont ceux de l'onglet « Radiv » (admin_radiv.php).
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Session;

const MINISITE_BASE = '/auth/minisite';

// Dossier de l'application simple : backend/demo/ une fois le site installé, demo/ à la racine du dépôt en développement.
function minisite_dir(): string
{
    $installed = dirname(__DIR__, 2) . '/demo';
    return is_file($installed . '/web/index.html') ? $installed : dirname(__DIR__, 3) . '/demo';
}

// Contenu d'un fichier de la page minimale (demo/web/), ou null s'il est absent.
function minisite_file(string $name): ?string
{
    $content = @file_get_contents(minisite_dir() . '/web/' . $name);
    return $content === false ? null : $content;
}

function do_minisite_page(): bool
{
    $admin = Session\admin();
    $html = $admin === null ? null : minisite_file('index.html');
    if ($html === null) return false;

    // Nonce : autorise le seul script en ligne de la page du moteur, sans ouvrir la porte à d'autres scripts.
    $nonce = base64_encode(random_bytes(16));
    $nav = '<header class="top"><div class="brand">Administration</div><nav class="tabs" aria-label="Sections">' . tabs_html('minisite') . '</nav>'
        . '<div class="who"><span>' . h($admin['email']) . '</span></div></header>';
    $html = str_replace('<script src="config.js"></script>', '<script src="' . MINISITE_BASE . '/config.js"></script>', $html);
    $html = str_replace('<script>', '<script nonce="' . $nonce . '">', $html);
    $html = str_ireplace('</head>', '<meta name="robots" content="noindex, nofollow"><style>' . RADIV_HEADER_CSS . '</style></head>', $html);
    $html = preg_replace('#(<body[^>]*>)#i', '$1' . str_replace('$', '\\$', $nav), $html, 1) ?? $html;

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'self'");
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: ' . strlen($html));
    echo $html;
    return true;
}

// config.js de la page (titre, textes, libellés), avec l'adresse de l'API remplacée par celle de l'administration.
function do_minisite_config_js(): bool
{
    $js = Session\admin() === null ? null : minisite_file('config.js');
    if ($js === null) return false;
    $js .= "\nwindow.RIV_CONFIG.apiBase = " . json_encode(MINISITE_BASE . '/api', JSON_UNESCAPED_SLASHES) . ";\n";
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($js));
    echo $js;
    return true;
}

// Mêmes réponses et mêmes protections que l'onglet « Radiv » (session, origine, limite de requêtes).
function do_minisite_api_config(): bool { return do_radiv_api_config(); }
function do_minisite_api_calculate(): bool { return do_radiv_api_calculate(); }
