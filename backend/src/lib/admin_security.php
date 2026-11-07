<?php
// Onglet « Connexion » de l'administration : enregistrer, remplacer ou retirer la connexion alternative identifiant + mot de passe + TOTP
// (voir admin_login.php). Déroulement : (1) identifiant + mot de passe → secret TOTP tiré au hasard, affiché en QR code ; (2) saisie d'un
// code produit par l'application d'authentification, qui prouve qu'elle est bien configurée → seulement alors les réglages sont écrits
// dans config/local.env. Mêmes protections que les mises à jour : session, CSRF, connexion récente.
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\AdminLogin;
use Radiv\Config;
use Radiv\Http;
use Radiv\Session;
use Radiv\Store;

function do_security(): bool
{
    $admin = Session\admin();
    if ($admin === null) return false;
    $flash = Session\flash_take();
    $csrf = '<input type="hidden" name="csrf" value="' . h(Session\csrf_token()) . '">';
    $body = '';
    if ($flash) $body .= '<p class="' . (!empty($flash['ok']) ? 'good' : 'bad') . '" role="status">' . h($flash['message'] ?? '') . '</p>';
    $pending = $_SESSION['login_setup'] ?? null;
    if (is_array($pending) && time() - (int) $pending['at'] > 600) { unset($_SESSION['login_setup']); $pending = null; }

    $body .= '<section class="card"><h2>Connexion par identifiant + mot de passe + code</h2>'
        . '<p class="small">Alternative à la connexion Google : à la page <code>/auth</code>, vous choisissez l\'une ou l\'autre. Le mot de passe n\'est jamais conservé (seule son empreinte l\'est), le code est celui d\'une application d\'authentification (Google Authenticator, Aegis, Authy…).</p>';
    if (AdminLogin\defined_in_runtime_env()) {
        $body .= '<p class="warnt">Ces réglages sont définis dans <code>config/runtime.env</code> : retirez-y les lignes <code>ADMIN_LOGIN_USER</code>, <code>ADMIN_LOGIN_PASSWORD_HASH</code> et <code>ADMIN_TOTP_SECRET</code> pour les gérer ici.</p></section>';
        page('connexion', 'Connexion', $body);
        return true;
    }
    $body .= AdminLogin\enabled()
        ? '<p>État : <strong class="good">activée</strong> pour l\'identifiant <code>' . h(AdminLogin\user()) . '</code>.</p>'
        : '<p>État : <strong>non configurée</strong>.</p>';

    if (is_array($pending)) {
        $uri = AdminLogin\otpauth_uri((string) $pending['user'], (string) $pending['secret'], (string) parse_url(Config\site_public_url(), PHP_URL_HOST));
        $body .= '<h3>Étape 2 : enregistrer dans votre application d\'authentification</h3>'
            . '<p>Scannez ce QR code, ou ajoutez un compte par saisie manuelle de la clé <code>' . h(implode(' ', str_split((string) $pending['secret'], 4))) . '</code> (SHA-1, 6 chiffres, 30 s).</p>'
            . '<p><canvas id="qr" data-uri="' . h($uri) . '" width="200" height="200" aria-label="QR code" style="max-width:260px;width:100%"></canvas></p>'
            . '<form method="post" action="/auth/securite/activer" class="stack" autocomplete="off">' . $csrf
            . '<label>Code à 6 chiffres affiché par l\'application<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>'
            . '<button type="submit">Vérifier et activer</button></form>';
    } else {
        $body .= '<h3>' . (AdminLogin\enabled() ? 'Remplacer le mot de passe et le code' : 'Étape 1 : choisir identifiant et mot de passe') . '</h3>'
            . '<form method="post" action="/auth/securite/preparer" class="stack" autocomplete="off">' . $csrf
            . '<label>Identifiant<input name="login" required maxlength="64" pattern="[A-Za-z0-9._@\-]{3,64}" value="' . h(AdminLogin\user()) . '" autocomplete="username"></label>'
            . '<label>Mot de passe (12 caractères au moins)<input name="password" type="password" required minlength="12" autocomplete="new-password"></label>'
            . '<label>Confirmer le mot de passe<input name="password2" type="password" required minlength="12" autocomplete="new-password"></label>'
            . '<button type="submit">Continuer</button></form>';
        if (AdminLogin\enabled()) {
            $body .= Config\admin_google_enabled()
                ? '<form method="post" action="/auth/securite/retirer" style="margin-top:14px">' . $csrf . '<button type="submit" class="secondary">Retirer cette connexion</button></form>'
                : '<p class="small">Impossible de la retirer : la connexion Google n\'est pas configurée (vous ne pourriez plus accéder à l\'administration).</p>';
        }
    }
    page('connexion', 'Connexion', $body . '</section>', ['scripts' => ['qrcode.js', 'securite.js']]);
    return true;
}

function security_done(bool $ok, string $message): bool
{
    Session\flash_set(['ok' => $ok, 'message' => $message]);
    Http\redirect(Config\site_public_url() . '/auth/securite', 303);
    return true;
}

function do_security_prepare(): bool
{
    $admin = guard_post(true);
    if ($admin === null || AdminLogin\defined_in_runtime_env()) return false;
    if (!Store\rate_limit_hit('adminsecurity', Http\client_ip(), 10)) return false;
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $login) !== 1) return security_done(false, 'Identifiant invalide (3 à 64 caractères : lettres, chiffres, . _ @ -).');
    if (strlen($password) < 12) return security_done(false, 'Mot de passe trop court (12 caractères au moins).');
    if (!hash_equals($password, (string) ($_POST['password2'] ?? ''))) return security_done(false, 'Les deux mots de passe ne sont pas identiques.');
    $_SESSION['login_setup'] = ['user' => $login, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'secret' => AdminLogin\base32_encode(random_bytes(20)), 'at' => time()];
    Http\redirect(Config\site_public_url() . '/auth/securite', 303);
    return true;
}

function do_security_activate(): bool
{
    $admin = guard_post(true);
    if ($admin === null || AdminLogin\defined_in_runtime_env()) return false;
    if (!Store\rate_limit_hit('adminsecurity', Http\client_ip(), 10)) return false;
    $pending = $_SESSION['login_setup'] ?? null;
    if (!is_array($pending) || time() - (int) $pending['at'] > 600) { unset($_SESSION['login_setup']); return security_done(false, 'Délai dépassé : recommencez.'); }
    $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? '')) ?? '';
    if (AdminLogin\totp_step($code, (string) $pending['secret'], time(), 0) === null) return security_done(false, 'Code incorrect : vérifiez l\'heure du téléphone et réessayez.');
    unset($_SESSION['login_setup']);
    $ok = AdminLogin\write_local_env(['ADMIN_LOGIN_USER' => $pending['user'], 'ADMIN_LOGIN_PASSWORD_HASH' => $pending['hash'], 'ADMIN_TOTP_SECRET' => $pending['secret']]);
    if ($ok) {
        @unlink(AdminLogin\last_file()); // nouveau secret : le dernier pas utilisé de l'ancien ne compte plus
        error_log('[ADMIN] Connexion identifiant + TOTP enregistrée par ' . $admin['email']);
    }
    return security_done($ok, $ok ? 'Connexion par identifiant + mot de passe + code activée.' : 'Écriture de config/local.env impossible (droits du dossier config).');
}

function do_security_remove(): bool
{
    $admin = guard_post(true);
    if ($admin === null || AdminLogin\defined_in_runtime_env() || !Config\admin_google_enabled()) return false;
    $ok = AdminLogin\write_local_env(['ADMIN_LOGIN_USER' => null, 'ADMIN_LOGIN_PASSWORD_HASH' => null, 'ADMIN_TOTP_SECRET' => null]);
    if ($ok) error_log('[ADMIN] Connexion identifiant + TOTP retirée par ' . $admin['email']);
    return security_done($ok, $ok ? 'Connexion par identifiant + mot de passe retirée.' : 'Écriture de config/local.env impossible.');
}
