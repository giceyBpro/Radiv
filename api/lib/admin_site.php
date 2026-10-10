<?php
// Administration du site, en deux onglets : « Site » (version installée, mise à jour depuis GitHub, retour
// à la version précédente, historique) et « Mesures » (historique des calculs : carte, graphiques, filtres,
// export). Rendue côté serveur sous une politique CSP stricte ; l'unique JavaScript est celui de la carte
// (Leaflet et mesures.js, servis par ce site, jamais par un tiers). Tant que la configuration Google n'est
// pas complète, ou si une requête ne respecte pas les règles, la réponse est le 404 générique de l'API.
declare(strict_types=1);

namespace Radiv\AdminSite;

use Radiv\AdminMeasures;
use Radiv\Config;
use Radiv\Contact;
use Radiv\Google;
use Radiv\Http;
use Radiv\Measurements;
use Radiv\Session;
use Radiv\Store;
use Radiv\Updater;

// Aucun lien vers ces adresses n'existe sur le site: l'entrée est connue de l'administrateur seul.
// /auth redirige vers Google (ou affiche la page si la session est déjà ouverte); tout le reste,
// sans session valide, répond par le 404 générique.
const ROUTES = [
    'GET /auth' => 'home',
    'GET /auth/' => 'home',
    'GET /auth/callback' => 'callback',
    'GET /auth/mesures' => 'measurements',
    'GET /auth/mesures.csv' => 'measurements_csv',
    'POST /auth/update' => 'update',
    'POST /auth/rollback' => 'rollback',
    'POST /auth/logout' => 'logout',
];

// Échappement HTML de toute valeur affichée. Les données des journaux viennent d'appelants anonymes:
// aucune n'est jamais insérée telle quelle.
function h(mixed $value): string
{
    $text = ($value === null || is_scalar($value)) ? (string) $value : (string) json_encode($value);
    return Contact\escape_html($text);
}

function home_url(): string
{
    return Config\site_public_url() . '/auth';
}

// true si la requête a été traitée (réponse envoyée), false pour laisser le 404 général s'en charger.
function handle(string $method, string $path): bool
{
    // Fichiers de la carte : GET /auth/assets/<nom>, réservés aux sessions ouvertes (hors limite de requêtes :
    // une page de mesures en charge plusieurs).
    if ($method === 'GET' && preg_match('#^/auth/assets/([A-Za-z0-9._-]+)$#', $path, $m)) {
        return Config\admin_site_enabled() && Session\transport_ok() && do_asset($m[1]);
    }
    $action = ROUTES["{$method} {$path}"] ?? null;
    if ($action === null || !Config\admin_site_enabled()) return false;
    if (!Session\transport_ok()) {
        error_log('[ADMIN] Page d\'administration refusée: HTTPS requis.');
        return false;
    }
    // Plafond large (120/min : un administrateur qui feuillette les mesures enchaîne les pages) ; la connexion Google a le sien, bien plus bas.
    if (!Store\rate_limit_hit('adminsite', Http\client_ip(), 120)) return false;
    return ('Radiv\\AdminSite\\' . 'do_' . $action)();
}

// /auth: sans session valide (ou avec ?reauth=1) on part vers Google, sinon on affiche la page.
function do_home(): bool
{
    $reauth = ($_GET['reauth'] ?? '') === '1';
    if (Session\admin() === null || $reauth) {
        if (!Store\rate_limit_hit('admin', Http\client_ip(), 10)) return false;
        Http\redirect(Google\begin_login($reauth));
        return true;
    }
    return do_site();
}

function do_callback(): bool
{
    if (!Store\rate_limit_hit('admin', Http\client_ip(), 10)) return false;
    $email = Google\complete_login($_GET);
    if ($email === null) {
        render('Accès refusé', '<p>Connexion impossible avec ce compte.</p>', 403);
        return true;
    }
    Session\login($email);
    Http\redirect(home_url(), 303);
    return true;
}

function require_admin(): ?array
{
    return Session\admin();
}

function do_site(): bool
{
    $admin = require_admin();
    if ($admin === null) return false;
    $status = Updater\status();
    $flash = Session\flash_take();
    $token = token_state();
    $csrf = h(Session\csrf_token());
    $out = '';
    if ($flash) {
        $out .= '<p class="msg ' . ($flash['ok'] ? 'ok' : 'ko') . '">' . h($flash['message']) . '</p>';
    }
    $v = $status['version'];
    $out .= '<section class="card"><h2>Version installée</h2>';
    $out .= $v
        ? '<p class="big"><code>' . h(substr((string) $v['sha'], 0, 7)) . '</code> <span class="muted">(' . h($v['ref'] ?? '') . ')</span></p>'
            . '<p class="small">Installée le ' . h($v['at'] ?? '') . ' par ' . h($v['by'] ?? '') . '</p>'
        : '<p>Inconnue (installation initiale, antérieure à ce système de mise à jour).</p>';
    $out .= '<p class="small">Dépôt : ' . h($status['repo']) . ' · PHP ' . h($status['php']) . ' · extension zip : ' . ($status['zip'] ? 'oui' : 'non') . '</p></section>';

    $out .= '<section class="card"><h2>Mise à jour</h2>';
    if (!$status['update_enabled']) {
        $out .= '<p>Les mises à jour depuis cette page sont désactivées sur ce site.</p>';
    } else {
        $default = $token['tags'][0] ?? 'main';
        $out .= '<form method="post" action="/auth/update" autocomplete="off" class="stack">';
        $out .= '<input type="hidden" name="csrf" value="' . $csrf . '">';
        $out .= '<label>Version (tag, branche ou commit)<input name="ref" value="' . h($default) . '" list="refs" required maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._\/\-]*"></label>';
        $out .= '<datalist id="refs">';
        foreach ($token['tags'] as $tag) $out .= '<option value="' . h($tag) . '">';
        $out .= '<option value="main"></datalist>';
        if (!$token['ok']) {
            $out .= '<label>Jeton d\'accès<input type="password" name="token" autocomplete="off" required maxlength="255"></label>';
        }
        $out .= '<div><button type="submit">Installer</button></div></form>';
        if (!Session\fresh_login()) {
            $out .= '<p class="small">Une nouvelle connexion Google vous sera demandée avant l\'installation.</p>';
        }
    }
    $out .= '</section>';
    if ($status['backup']) {
        $prev = $status['backup']['from']['sha'] ?? null;
        $out .= '<section class="card"><h2>Retour arrière</h2><form method="post" action="/auth/rollback" class="stack">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<p>Version précédente conservée' . ($prev ? ' : <code>' . h(substr((string) $prev, 0, 7)) . '</code>' : '') . '.</p>'
            . '<div><button type="submit" class="secondary">Rétablir la version précédente</button></div></form></section>';
    }
    if ($status['journal']) {
        $out .= '<section class="card"><h2>Historique</h2><div class="tablewrap"><table><thead><tr><th>Date</th><th>Action</th><th>Par</th><th>Résultat</th></tr></thead><tbody>';
        foreach ($status['journal'] as $row) {
            $okRow = (bool) ($row['ok'] ?? false);
            $out .= '<tr><td class="nowrap">' . h($row['at'] ?? '') . '</td><td>' . h($row['action'] ?? '') . ' '
                . h(substr((string) ($row['to'] ?? ''), 0, 7)) . '</td><td>' . h($row['by'] ?? '') . '</td><td>'
                . ($okRow ? '<span class="badge good">ok</span>' : '<span class="badge bad">échec</span>') . '</td></tr>';
        }
        $out .= '</tbody></table></div></section>';
    }
    page('site', 'Administration du site', $out);
    return true;
}

// Le jeton du .env est vérifié auprès de GitHub (résultat gardé 5 min en session); s'il manque ou
// n'est plus accepté, le formulaire affiche simplement un champ pour en saisir un.
function token_state(): array
{
    if (!Config\admin_update_enabled()) return ['ok' => false, 'tags' => []];
    $envToken = Config\env('UPDATE_GITHUB_TOKEN');
    if ($envToken === '') return ['ok' => false, 'tags' => []];
    Session\start();
    $cache = $_SESSION['gh'] ?? null;
    if (!is_array($cache) || $cache['until'] < time()) {
        $ok = Updater\token_valid($envToken);
        $cache = ['ok' => $ok, 'tags' => $ok ? Updater\recent_tags($envToken) : [], 'until' => time() + 300];
        $_SESSION['gh'] = $cache;
    }
    return ['ok' => (bool) $cache['ok'], 'tags' => $cache['tags']];
}

// Contrôles communs des actions qui modifient le site.
function guard_post(bool $needFresh): ?array
{
    $admin = Session\admin();
    if ($admin === null) return null;
    if (!Session\csrf_valid()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        // Réservé à un administrateur connecté : on peut lui dire quoi vérifier.
        echo "Requête refusée.\n\nLa page est peut-être restée ouverte trop longtemps ou a été ouverte dans un autre onglet après une nouvelle connexion : rechargez /auth et recommencez.\n"
            . "Vérifiez aussi que vous utilisez bien l'adresse configurée (SITE_PUBLIC_URL), avec ou sans « www » comme elle.\n";
        exit;
    }
    if ($needFresh && !Session\fresh_login()) {
        Http\redirect(home_url() . '?reauth=1');
        exit;
    }
    return $admin;
}

function do_update(): bool
{
    if (!Config\admin_update_enabled()) return false;
    $admin = guard_post(true);
    if ($admin === null) return false;
    if (!Store\rate_limit_hit('adminupd', Http\client_ip(), 5)) return false;
    $ref = trim((string) ($_POST['ref'] ?? ''));
    $posted = trim((string) ($_POST['token'] ?? ''));
    $envToken = Config\env('UPDATE_GITHUB_TOKEN');
    $token = ($envToken !== '' && token_state()['ok']) ? $envToken : $posted;
    if (!Updater\token_valid($token)) {
        Session\flash_set(['ok' => false, 'message' => 'Le jeton n\'est pas valide.']);
        unset($_SESSION['gh']);
    } else {
        $result = Updater\run($ref, $token, $admin['email']);
        Session\flash_set($result);
        unset($_SESSION['gh']);
    }
    $token = $posted = '';
    Http\redirect(home_url(), 303);
    return true;
}

function do_rollback(): bool
{
    if (!Config\admin_update_enabled()) return false;
    $admin = guard_post(true);
    if ($admin === null) return false;
    if (!Store\rate_limit_hit('adminupd', Http\client_ip(), 5)) return false;
    Session\flash_set(Updater\rollback($admin['email']));
    Http\redirect(home_url(), 303);
    return true;
}

function do_measurements(): bool
{
    if (!Config\admin_measurements_enabled() || Session\admin() === null) return false;
    [$title, $body, $opts] = AdminMeasures\render_tab(AdminMeasures\parse_query($_GET), Config\logging_level());
    page('mesures', $title, $body, $opts + ['wide' => true]);
    return true;
}

function do_measurements_csv(): bool
{
    if (!Config\admin_measurements_enabled() || Session\admin() === null) return false;
    [$csv, $label] = AdminMeasures\csv_for(AdminMeasures\parse_query($_GET));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mesures_' . $label . '.csv"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    return true;
}

// Fichiers statiques de la carte (liste fermée, jamais de chemin construit à partir de la requête).
function do_asset(string $name): bool
{
    static $files = [
        'leaflet.js' => ['application/javascript; charset=utf-8', 'leaflet.js'],
        'leaflet.css' => ['text/css; charset=utf-8', 'leaflet.css'],
        'mesures.js' => ['application/javascript; charset=utf-8', 'mesures.js'],
    ];
    if (Session\admin() === null || !isset($files[$name])) return false;
    $path = Config\base_dir() . '/assets/' . $files[$name][1];
    $content = @file_get_contents($path);
    if ($content === false) return false;
    http_response_code(200);
    header('Content-Type: ' . $files[$name][0]);
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . strlen($content));
    echo $content;
    return true;
}

function do_logout(): bool
{
    if (guard_post(false) === null) return false;
    Session\logout();
    render('Déconnecté', '<p>Vous êtes déconnecté.</p>');
    return true;
}

const CSS = <<<'CSS'
:root{--bg:#eef1f4;--card:#fff;--ink:#14202b;--muted:#5d6b78;--line:#d9e0e6;--brand:#1637b8;--brand-ink:#fff;--good:#1b7a2e;--goodbg:#e3f4e6;--bad:#a32222;--badbg:#fbe6e6;--warn:#8a5a00}
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink);line-height:1.45}
a{color:var(--brand)}code{background:#eef1f4;padding:1px 5px;border-radius:4px}
.top{background:#10223f;color:#fff;display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center;padding:10px 20px}
.brand{font-weight:700;letter-spacing:.2px}.brand small{font-weight:400;opacity:.7;margin-left:8px}
.tabs{display:flex;gap:4px;flex:1}.tab{color:#cfd9ea;text-decoration:none;padding:8px 16px;border-radius:8px}
.tab:hover{background:rgba(255,255,255,.1)}.tab.on{background:#fff;color:#10223f;font-weight:700}
.who{display:flex;gap:10px;align-items:center;font-size:.9rem}.who form{margin:0}.who span{opacity:.85}
main{max-width:900px;margin:22px auto;padding:0 16px}main.wide{max-width:1280px}
h1{font-size:1.55rem;margin:0 0 14px}h2{font-size:1.05rem;margin:0 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin:0 0 16px;box-shadow:0 1px 2px rgba(16,34,63,.05)}
.stack>*{margin:0 0 12px}.stack label{display:block;font-weight:600}
input,select{display:block;width:100%;padding:8px 10px;margin-top:4px;font:inherit;border:1px solid #b9c4cf;border-radius:8px;background:#fff}
button,.btn{display:inline-block;padding:9px 16px;border:0;border-radius:8px;background:var(--brand);color:var(--brand-ink);font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
button.secondary,.btn.secondary{background:#e6ebf0;color:#14202b}.top button.secondary{background:rgba(255,255,255,.14);color:#fff}
.msg{padding:10px 14px;border-radius:8px;margin:0 0 16px}.msg.ok{background:var(--goodbg);border:1px solid #9bd0a6}.msg.ko{background:var(--badbg);border:1px solid #e0a0a0}
.small{font-size:.85rem;color:var(--muted)}.muted{color:var(--muted)}.big{font-size:1.3rem;margin:0 0 4px}.warnt{color:var(--warn);font-weight:600}.nowrap{white-space:nowrap}
.toolbar{display:flex;flex-wrap:wrap;gap:12px 18px;align-items:center;justify-content:space-between;margin-bottom:8px}.toolbar h2{margin:0}
.inline{display:flex;gap:8px;align-items:center;margin:0}.inline label{font-weight:600;font-size:.9rem}.inline select{width:auto;margin:0}
.seg{display:inline-flex;border:1px solid #b9c4cf;border-radius:10px;overflow:hidden;flex-wrap:wrap}
.segbtn{padding:8px 14px;text-decoration:none;color:var(--ink);background:#fff;border-right:1px solid #b9c4cf}.segbtn:last-child{border-right:0}
.segbtn:hover{background:#eef3fb}.segbtn.on{background:var(--brand);color:#fff;font-weight:700}
.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}.filters label{font-weight:600;font-size:.9rem;min-width:170px;flex:1}.filters .reset{margin-left:4px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:0 0 16px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px}.stat .v{font-size:1.6rem;font-weight:700}.stat .vs{font-size:1.05rem;word-break:break-word}
.stat .l{color:var(--muted);font-size:.85rem}.stat.good .v{color:var(--good)}.stat.bad .v{color:var(--bad)}
.cols{display:grid;grid-template-columns:2fr 1fr;gap:16px}@media(max-width:860px){.cols{grid-template-columns:1fr}}
.chart{width:100%;height:210px}.chart .bar{fill:#3f6fd1}.chart .bar:hover{fill:#10223f}.chart .axis{stroke:#9aa7b4;stroke-width:1}.chart .lbl{font-size:11px;fill:#5d6b78}
.hbar{display:grid;grid-template-columns:minmax(90px,1.1fr) 2fr 36px;gap:8px;align-items:center;margin:6px 0;font-size:.88rem}.hbar .name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hbar .track{background:#e8edf2;border-radius:6px;height:12px;overflow:hidden}.hbar .fill{display:block;height:100%;background:#3f6fd1}.hbar .num{text-align:right;color:var(--muted)}
.map{height:440px;border:1px solid var(--line);border-radius:10px;margin-top:8px;z-index:0}
.legend{display:flex;gap:16px;flex-wrap:wrap;font-size:.85rem;color:var(--muted)}.dot{display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:-1px;margin-right:4px}
.c1,.mk.c1{background:#2f6eb7}.c2,.mk.c2{background:#e57f1f}.c3,.mk.c3{background:#b22222}
.mk{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4)}
.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.88rem}
th,td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}th{background:#f4f7f9;position:sticky;top:0;white-space:nowrap}tbody tr:nth-child(even){background:#fafbfc}
.pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#eaf1fc;margin:2px 4px 2px 0;white-space:nowrap;font-size:.8rem}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.78rem;font-weight:700}.badge.good{background:var(--goodbg);color:var(--good)}.badge.bad{background:var(--badbg);color:var(--bad)}
.err{color:var(--bad)}.pager{display:flex;justify-content:space-between;align-items:center;margin-top:12px}
@media(max-width:640px){.top{padding:10px 12px}.tab{padding:7px 11px}.who span{display:none}}
CSS;

// Page complète de l'administration : en-tête, onglets, contenu. $active : 'site' ou 'mesures'.
// Options : 'wide' (page large), 'map' (charge Leaflet et mesures.js : seule page autorisée à exécuter du JavaScript).
function page(string $active, string $title, string $body, array $opts = [], int $status = 200): void
{
    $admin = Session\admin();
    $t = h($title);
    $wide = !empty($opts['wide']) ? ' class="wide"' : '';
    $map = !empty($opts['map']);
    $nav = '';
    if ($admin !== null) {
        $tabs = '<a class="tab' . ($active === 'site' ? ' on' : '') . '" href="/auth"' . ($active === 'site' ? ' aria-current="page"' : '') . '>Site</a>';
        if (Config\admin_measurements_enabled()) {
            $tabs .= '<a class="tab' . ($active === 'mesures' ? ' on' : '') . '" href="/auth/mesures"' . ($active === 'mesures' ? ' aria-current="page"' : '') . '>Mesures</a>';
        }
        $host = (string) parse_url(Config\site_public_url(), PHP_URL_HOST);
        $nav = '<header class="top"><div class="brand">Administration<small>' . h($host) . '</small></div><nav class="tabs" aria-label="Sections">' . $tabs . '</nav>'
            . '<div class="who"><span>' . h($admin['email']) . '</span><form method="post" action="/auth/logout"><input type="hidden" name="csrf" value="' . h(Session\csrf_token()) . '">'
            . '<button type="submit" class="secondary">Se déconnecter</button></form></div></header>';
    }
    $css = CSS;
    $head = $map ? '<link rel="stylesheet" href="/auth/assets/leaflet.css">' : '';
    $scripts = $map ? '<script src="/auth/assets/leaflet.js"></script><script src="/auth/assets/mesures.js"></script>' : '';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>{$t}</title>
<style>{$css}</style>
{$head}
</head>
<body>{$nav}<main{$wide}><h1>{$t}</h1>{$body}</main>{$scripts}</body>
</html>
HTML;
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    // Sans carte : aucun script ni ressource externe. Avec carte : scripts de ce site uniquement (jamais de tiers),
    // et seules les images de tuiles OpenStreetMap en plus ; la politique de référent envoie l'origine du site
    // (exigée par OpenStreetMap pour ses tuiles), jamais le chemin.
    header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        . ($map ? "; script-src 'self'; img-src 'self' data: https://*.tile.openstreetmap.org" : ''));
    // « same-origin » (et non no-referrer, hérité des autres routes) : avec no-referrer les navigateurs envoient
    // « Origin: null » sur les formulaires, et rien ne partait vers l'extérieur de toute façon. Les pages avec
    // carte envoient en plus l'origine du site aux tuiles (exigée par OpenStreetMap), jamais le chemin.
    header('Referrer-Policy: ' . ($map ? 'strict-origin-when-cross-origin' : 'same-origin'));
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: ' . strlen($html));
    echo $html;
}

// Pages sans onglets (avant connexion, après déconnexion).
function render(string $title, string $body, int $status = 200): void
{
    page('', $title, '<section class="card">' . $body . '</section>', [], $status);
}
