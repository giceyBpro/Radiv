<?php
// Onglet « Radiv » de l'administration (/auth/radiv) : le site lui-même, tel que le voient les visiteurs, accessible à l'administrateur
// connecté EN TOUTE CIRCONSTANCE — y compris pendant une maintenance (simple ou complète).
//
// Principe : c'est la page d'accueil du site (index.html + app.js, donc toujours à jour avec elle, sans maintenance propre à cette
// vue), seulement adaptée pour fonctionner depuis l'administration : même origine, aucune ressource tierce, page d'attente de
// maintenance retirée, appels redirigés vers /auth/radiv/api/*. Ces routes sont derrière la session d'administration et ne passent pas par
// la maintenance, qui ne touche que l'API publique (/api/*) : le calcul fonctionne donc ici même quand l'API publique est arrêtée.
// Rien n'est enregistré dans les mesures.
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use RivEngine as Calculation;
use Radiv\Config;
use Radiv\Frontend;
use Radiv\Http;
use Radiv\Session;
use Radiv\Store;

const RADIV_BASE = '/auth/radiv';

// En-tête d'administration (onglets) posé au-dessus de la page : seul ajout visuel.
const RADIV_HEADER_CSS = '.top{background:#17324d;color:#fff;display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center;padding:10px 20px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}'
    . '.brand{font-weight:700}.tabs{display:flex;gap:4px;flex:1;flex-wrap:wrap}.tab{color:#cfe0f1;text-decoration:none;padding:8px 16px;border-radius:8px}'
    . '.tab:hover{background:rgba(255,255,255,.12)}.tab.on{background:#fff;color:#17324d;font-weight:700}.who{font-size:.9rem;opacity:.9}';

function do_radiv_page(): bool
{
    $admin = Session\admin();
    if ($admin === null) return false;
    $html = @file_get_contents(Config\web_root() . '/index.html');
    if ($html === false) return false;
    $nav = '<header class="top"><div class="brand">Administration</div><nav class="tabs" aria-label="Sections">' . tabs_html('radiv') . '</nav>'
        . '<div class="who"><span>' . h($admin['email']) . '</span></div></header>';
    $html = preg_replace('#<link[^>]*fonts\.googleapis\.com[^>]*>\s*#i', '', $html) ?? $html;   // aucune ressource tierce
    $html = preg_replace('#<link[^>]*rel="canonical"[^>]*>\s*#i', '', $html) ?? $html;
    $html = preg_replace('#<meta[^>]*name="robots"[^>]*>#i', '<meta name="robots" content="noindex, nofollow" />', $html, 1) ?? $html;
    // Page d'attente de maintenance (style, avis, script en ligne) : sans objet ici, et le script est refusé par la CSP de cette page.
    $html = preg_replace('#<!--MAINTENANCE-GATE-->.*?<!--/MAINTENANCE-GATE-->\s*#s', '', $html) ?? $html;
    $html = preg_replace('#<head>#i', '<head><base href="/">', $html, 1) ?? $html;
    $html = str_ireplace('</head>', '<style>' . RADIV_HEADER_CSS . '</style></head>', $html);
    $html = preg_replace('#(<body[^>]*>)#i', '$1' . str_replace('$', '\\$', $nav), $html, 1) ?? $html;
    $html = str_replace('<script src="config.js"></script>', '<script src="' . RADIV_BASE . '/config.js"></script>', $html);
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'self'");
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: ' . strlen($html));
    echo $html;
    return true;
}

// Configuration injectée à la place de config.js : la page appelle l'API d'administration, avec l'identité réelle du site.
function do_radiv_config_js(): bool
{
    if (Session\admin() === null) return false;
    $js = 'window.RADIOPROTECTION_API_URL = ' . json_encode(RADIV_BASE . '/api', JSON_UNESCAPED_SLASHES) . ";\n"
        . 'window.RADIOPROTECTION_SITE_URL = ' . json_encode(Config\site_public_url(), JSON_UNESCAPED_SLASHES) . ";\n";
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($js));
    echo $js;
    return true;
}

function do_radiv_api_config(): bool
{
    if (Session\admin() === null) return false;
    Http\send_json(200, Frontend\config(false, false)); // sans annonce de maintenance : la vue reste utilisable
    return true;
}

function do_radiv_api_public(): bool
{
    if (Session\admin() === null) return false;
    Http\send_json(200, Frontend\public_config(false));
    return true;
}

function do_radiv_api_calculate(): bool
{
    if (Session\admin() === null) return false;
    // Appel JSON de la page elle-même : même origine exigée, et type JSON (un formulaire d'un autre site ne peut pas l'envoyer).
    if (!Session\same_origin_request() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        Http\send_json(403, ['ok' => false, 'error' => 'Requête refusée.']);
        return true;
    }
    if (!Store\rate_limit_hit('adminradiv', Http\client_ip(), 120)) {
        Http\send_json(429, ['ok' => false, 'error' => 'Trop de requêtes. Réessayez dans une minute.']);
        return true;
    }
    $body = Http\read_body();
    $payload = $body !== null && $body !== '' ? json_decode($body, true) : null;
    if (!is_array($payload)) {
        Http\send_json(400, ['ok' => false, 'error' => 'JSON invalide']);
        return true;
    }
    unset($payload['provider'], $payload['calculation_mode']); // mêmes entrées que l'API publique
    Http\send_json(200, Calculation\calculate($payload));
    return true;
}
