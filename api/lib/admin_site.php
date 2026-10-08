<?php
// Page d'administration du site: connexion Google, état de la version installée, mise à jour
// depuis GitHub et retour à la version précédente. Rendue côté serveur (formulaires simples, aucun
// JavaScript), sous une politique CSP stricte. Tant que la configuration Google n'est pas complète,
// ou si une requête ne respecte pas les règles, la réponse est le 404 générique de l'API.
declare(strict_types=1);

namespace Radiv\AdminSite;

use Radiv\Config;
use Radiv\Contact;
use Radiv\Google;
use Radiv\Http;
use Radiv\Session;
use Radiv\Store;
use Radiv\Updater;

const ROUTES = [
    'GET /api/admin/login' => 'login',
    'GET /api/admin/oauth/callback' => 'callback',
    'GET /api/admin/site' => 'site',
    'POST /api/admin/update' => 'update',
    'POST /api/admin/rollback' => 'rollback',
    'POST /api/admin/logout' => 'logout',
];

function h(mixed $value): string
{
    return Contact\escape_html((string) $value);
}

function site_url(): string
{
    return Config\api_public_url() . '/admin/site';
}

// true si la requête a été traitée (réponse envoyée), false pour laisser le 404 général s'en charger.
function handle(string $method, string $path): bool
{
    $action = ROUTES["{$method} {$path}"] ?? null;
    if ($action === null || !Config\admin_site_enabled()) return false;
    if (!Session\transport_ok()) {
        error_log('[ADMIN] Page d\'administration refusée: HTTPS requis.');
        return false;
    }
    // Même plafond que les routes de mesures: tentatives comptées (réussies ou non).
    if (!Store\rate_limit_hit('adminsite', Http\client_ip(), 30)) return false;
    return ('Radiv\\AdminSite\\' . 'do_' . $action)();
}

function do_login(): bool
{
    if (!Store\rate_limit_hit('admin', Http\client_ip(), 10)) return false;
    Http\redirect(Google\begin_login(($_GET['reauth'] ?? '') === '1'));
    return true;
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
    Http\redirect(site_url(), 303);
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
    $out = '';
    if ($flash) {
        $out .= '<p class="msg ' . ($flash['ok'] ? 'ok' : 'ko') . '">' . h($flash['message']) . '</p>';
    }
    $v = $status['version'];
    $out .= '<h2>Version installée</h2>';
    $out .= $v
        ? '<p><code>' . h(substr((string) $v['sha'], 0, 7)) . '</code> (' . h($v['ref'] ?? '') . ') — ' . h($v['at'] ?? '') . ' — ' . h($v['by'] ?? '') . '</p>'
        : '<p>Inconnue (installation initiale, antérieure à ce système de mise à jour).</p>';
    $out .= '<p class="small">Dépôt : ' . h($status['repo']) . ' · PHP ' . h($status['php']) . ' · extension zip : ' . ($status['zip'] ? 'oui' : 'non') . '</p>';

    $out .= '<h2>Mise à jour</h2>';
    if (!$status['update_enabled']) {
        $out .= '<p>Les mises à jour depuis cette page sont désactivées sur ce site.</p>';
    } else {
        $default = $token['tags'][0] ?? 'main';
        $out .= '<form method="post" action="' . h(Config\api_public_url() . '/admin/update') . '" autocomplete="off">';
        $out .= '<input type="hidden" name="csrf" value="' . h(Session\csrf_token()) . '">';
        $out .= '<label>Version (tag, branche ou commit)<input name="ref" value="' . h($default) . '" list="refs" required maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._/-]*"></label>';
        $out .= '<datalist id="refs">';
        foreach ($token['tags'] as $tag) $out .= '<option value="' . h($tag) . '">';
        $out .= '<option value="main"></datalist>';
        if (!$token['ok']) {
            $out .= '<label>Jeton d\'accès<input type="password" name="token" autocomplete="off" required maxlength="255"></label>';
        }
        $out .= '<button type="submit">Installer</button></form>';
        if (!Session\fresh_login()) {
            $out .= '<p class="small">Une nouvelle connexion Google vous sera demandée avant l\'installation.</p>';
        }
    }
    if ($status['backup']) {
        $prev = $status['backup']['from']['sha'] ?? null;
        $out .= '<h2>Retour arrière</h2><form method="post" action="' . h(Config\api_public_url() . '/admin/rollback') . '">'
            . '<input type="hidden" name="csrf" value="' . h(Session\csrf_token()) . '">'
            . '<p>Version précédente conservée' . ($prev ? ' : <code>' . h(substr((string) $prev, 0, 7)) . '</code>' : '') . '.</p>'
            . '<button type="submit" class="secondary">Rétablir la version précédente</button></form>';
    }
    if ($status['journal']) {
        $out .= '<h2>Historique</h2><table><tr><th>Date</th><th>Action</th><th>Par</th><th>Résultat</th></tr>';
        foreach ($status['journal'] as $row) {
            $out .= '<tr><td>' . h($row['at'] ?? '') . '</td><td>' . h($row['action'] ?? '') . ' '
                . h(substr((string) ($row['to'] ?? ''), 0, 7)) . '</td><td>' . h($row['by'] ?? '') . '</td><td>'
                . (($row['ok'] ?? false) ? 'ok' : 'échec') . '</td></tr>';
        }
        $out .= '</table>';
    }
    $out .= '<form method="post" action="' . h(Config\api_public_url() . '/admin/logout') . '" class="logout">'
        . '<input type="hidden" name="csrf" value="' . h(Session\csrf_token()) . '">'
        . '<span>' . h($admin['email']) . '</span> <button type="submit" class="secondary">Se déconnecter</button></form>';
    render('Administration du site', $out);
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
        echo 'Requête refusée.';
        exit;
    }
    if ($needFresh && !Session\fresh_login()) {
        Http\redirect(Config\api_public_url() . '/admin/login?reauth=1');
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
    Http\redirect(site_url(), 303);
    return true;
}

function do_rollback(): bool
{
    if (!Config\admin_update_enabled()) return false;
    $admin = guard_post(true);
    if ($admin === null) return false;
    if (!Store\rate_limit_hit('adminupd', Http\client_ip(), 5)) return false;
    Session\flash_set(Updater\rollback($admin['email']));
    Http\redirect(site_url(), 303);
    return true;
}

function do_logout(): bool
{
    if (guard_post(false) === null) return false;
    Session\logout();
    render('Déconnecté', '<p>Vous êtes déconnecté.</p>');
    return true;
}

function render(string $title, string $body, int $status = 200): void
{
    $t = h($title);
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>{$t}</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#eef1f3;color:#111}
main{max-width:720px;margin:24px auto;background:#fff;padding:24px 28px;border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12)}
h1{margin-top:0;font-size:1.5rem}h2{margin:26px 0 8px;font-size:1.1rem}
label{display:block;margin:10px 0;font-weight:bold}label input{display:block;width:100%;box-sizing:border-box;padding:8px;margin-top:4px;font:inherit}
button{padding:9px 18px;border:0;border-radius:6px;background:#1637b8;color:#fff;font:inherit;cursor:pointer}button.secondary{background:#555}
.msg{padding:10px 12px;border-radius:6px}.ok{background:#e3f4e6;border:1px solid #9bd0a6}.ko{background:#fbe6e6;border:1px solid #e0a0a0}
.small{font-size:.85rem;color:#555}table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{border:1px solid #d5dde1;padding:6px;text-align:left}
.logout{margin-top:28px;padding-top:14px;border-top:1px solid #d5dde1}
</style>
</head>
<body><main><h1>{$t}</h1>{$body}</main></body>
</html>
HTML;
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: ' . strlen($html));
    echo $html;
}
