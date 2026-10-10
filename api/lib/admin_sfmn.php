<?php
// Onglet « Test SFMN » de l'administration (/auth/test-sfmn) : la page d'accueil du site (index.html + app.js,
// donc toujours à jour avec elle) servie sous une autre feuille de style, avec le calcul SFMN TOUJOURS actif,
// même si SFMN_MODE_ENABLED=false. Les appels de la page passent par /auth/test-sfmn/api/* (réservés à une
// session d'administration), jamais par l'API publique. Rien n'est écrit dans les mesures.
declare(strict_types=1);

namespace Radiv\AdminSite;

use Radiv\Calculation;
use Radiv\Config;
use Radiv\Frontend;
use Radiv\Http;
use Radiv\Sfmn;
use Radiv\Session;
use Radiv\Store;

const SFMN_BASE = '/auth/test-sfmn';

// Page : index.html du site, adaptée (même origine, aucune ressource tierce, appels redirigés vers l'administration).
function do_sfmn_page(): bool
{
    $admin = Session\admin();
    if ($admin === null) return false;
    $html = @file_get_contents(Config\web_root() . '/index.html');
    if ($html === false) return false;
    $name = 'test-sfmn';
    $nav = '<header class="top"><div class="brand">Administration</div><nav class="tabs" aria-label="Sections">' . tabs_html('sfmn') . '</nav>'
        . '<div class="who"><span>' . h($admin['email']) . '</span></div></header>'
        . '<div class="test-banner">TEST SFMN — le calcul SFMN est toujours actif ici, même s\'il est désactivé sur le site. Aucune mesure n\'est enregistrée.</div>';
    $html = preg_replace('#<link[^>]*fonts\.googleapis\.com[^>]*>\s*#i', '', $html) ?? $html;   // aucune ressource tierce
    $html = preg_replace('#<link[^>]*rel="canonical"[^>]*>\s*#i', '', $html) ?? $html;
    $html = preg_replace('#<meta[^>]*name="robots"[^>]*>#i', '<meta name="robots" content="noindex, nofollow" />', $html, 1) ?? $html;
    $html = preg_replace('#<title>#i', '<title>[Test SFMN] ', $html, 1) ?? $html;
    $html = preg_replace('#<head>#i', '<head><base href="/">', $html, 1) ?? $html;
    $html = str_ireplace('</head>', '<link rel="stylesheet" href="/auth/assets/' . $name . '.css"></head>', $html);
    $html = preg_replace('#(<body[^>]*>)#i', '$1' . str_replace('$', '\\$', $nav), $html, 1) ?? $html;
    $html = str_replace('<script src="config.js"></script>', '<script src="' . SFMN_BASE . '/config.js"></script>', $html);
    $html = str_replace('<script src="app.js"></script>', '<script src="app.js"></script><script src="/auth/assets/' . $name . '.js"></script>', $html);
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

// Configuration injectée à la place de config.js : appels de la page vers l'API de test.
function do_sfmn_config_js(): bool
{
    if (Session\admin() === null) return false;
    $js = 'window.RADIOPROTECTION_API_URL = ' . json_encode(SFMN_BASE . '/api', JSON_UNESCAPED_SLASHES) . ";\n"
        . 'window.RADIOPROTECTION_SITE_URL = ' . json_encode(Config\site_public_url(), JSON_UNESCAPED_SLASHES) . ";\n"
        . "window.RADIOPROTECTION_SITE_NAME = 'TEST SFMN';\nwindow.RADIOPROTECTION_COPYRIGHT_OWNER = 'Test SFMN (administration)';\n";
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($js));
    echo $js;
    return true;
}

function do_sfmn_api_config(): bool
{
    if (Session\admin() === null) return false;
    Http\send_json(200, Frontend\config(true));
    return true;
}

function do_sfmn_api_public(): bool
{
    if (Session\admin() === null) return false;
    Http\send_json(200, ['site_name' => 'TEST SFMN', 'copyright_owner' => 'Test SFMN (administration)'] + Frontend\public_config());
    return true;
}

function do_sfmn_api_calculate(): bool
{
    if (Session\admin() === null) return false;
    // Appel JSON de la page elle-même : même origine exigée, et type JSON (un formulaire d'un autre site ne peut pas l'envoyer).
    if (!Session\same_origin_request() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        Http\send_json(403, ['ok' => false, 'error' => 'Requête refusée.']);
        return true;
    }
    // Chaque essai interroge le site SFMN distant : plafond bas.
    if (!Store\rate_limit_hit('adminsfmn', Http\client_ip(), 20)) {
        Http\send_json(429, ['ok' => false, 'error' => 'Trop de requêtes. Réessayez dans une minute.']);
        return true;
    }
    $body = Http\read_body();
    $payload = $body !== null && $body !== '' ? json_decode($body, true) : null;
    if (!is_array($payload)) {
        Http\send_json(400, ['ok' => false, 'error' => 'JSON invalide']);
        return true;
    }
    $mode = ($payload['calculation_mode'] ?? 'sfmn') === 'local' ? 'local' : 'sfmn';
    $local = Calculation\calculate($payload);
    if ($mode === 'local') {
        $result = $local;
        $debug = null;
    } else {
        $result = Sfmn\calculate($payload, ['force' => true, 'debug' => true]);
        $debug = $result['sfmn_debug'] ?? null;
        unset($result['sfmn_debug']); // affiché dans le volet repliable, pas dans la zone d'erreur de la page
    }
    // Détails réservés au volet de l'administration : comparaison avec le calcul local et échanges SFMN.
    $result['admin_details'] = ['mode' => $mode, 'local' => $local, 'sfmn_debug' => $debug];
    Http\send_json(200, $result);
    return true;
}
