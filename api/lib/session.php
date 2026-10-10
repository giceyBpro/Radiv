<?php
// Session d'administration: cookie HttpOnly/Secure/SameSite, stockage dans var/sessions (privé),
// expiration par inactivité et absolue, protection CSRF. Aucune donnée sensible (jeton GitHub,
// jetons Google) n'y est jamais conservée: uniquement l'identité de l'administrateur connecté.
declare(strict_types=1);

namespace Radiv\Session;

use Radiv\Config;
use Radiv\Http;

const IDLE_SECONDS = 1800;      // 30 min d'inactivité
const ABSOLUTE_SECONDS = 28800; // 8 h maximum
const FRESH_LOGIN_SECONDS = 600; // action sensible: connexion datant de moins de 10 min

function is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') return true;
    // Derrière un proxy de confiance (même règle que pour X-Forwarded-For).
    $remote = Http\normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return in_array($remote, Config\trusted_proxies(), true)
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

// Les identifiants circulent dans le cookie: HTTPS exigé (ADMIN_ALLOW_INSECURE_HTTP=true réservé
// aux essais en local).
function transport_ok(): bool
{
    return is_https() || strtolower(Config\env('ADMIN_ALLOW_INSECURE_HTTP', 'false')) === 'true';
}

function start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = Config\var_dir() . '/sessions';
    if (!Config\ensure_private_dir($dir)) throw new \RuntimeException('var/sessions inaccessible');
    session_save_path($dir);
    session_name('radiv_admin');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) ABSOLUTE_SECONDS);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/auth',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax', // Lax et non Strict: le retour de Google est une navigation entre sites
    ]);
    session_start();
    $now = time();
    $admin = $_SESSION['admin'] ?? null;
    if ($admin && ($now - ($_SESSION['last'] ?? 0) > IDLE_SECONDS || $now - ($admin['at'] ?? 0) > ABSOLUTE_SECONDS)) {
        unset($_SESSION['admin']);
    }
    $_SESSION['last'] = $now;
}

function admin(): ?array
{
    start();
    return is_array($_SESSION['admin'] ?? null) ? $_SESSION['admin'] : null;
}

function login(string $email): void
{
    start();
    session_regenerate_id(true); // contre la fixation de session
    $_SESSION['admin'] = ['email' => $email, 'at' => time()];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    prune_old_files();
}

function logout(): void
{
    start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/auth', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}

function fresh_login(): bool
{
    $admin = admin();
    return $admin !== null && time() - (int) $admin['at'] <= FRESH_LOGIN_SECONDS;
}

function csrf_token(): string
{
    start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf'];
}

// Contrôles d'une requête qui modifie quelque chose :
//  1. jeton CSRF du formulaire (secret de la session, inconnu d'un autre site) : protection principale ;
//  2. Sec-Fetch-Site, quand le navigateur l'envoie : refus de toute requête venant d'un autre site ;
//  3. Origin (à défaut Referer) : doit être l'adresse du site. Un « Origin: null » n'est pas une adresse : les
//     navigateurs l'envoient quand la politique de référent est no-referrer (ou depuis une page isolée). Il est
//     donc ignoré ici — le jeton et le cookie SameSite=Lax couvrent ce cas — plutôt que de refuser un
//     administrateur légitime (c'était le cas de tous les formulaires de /auth).
function csrf_valid(): bool
{
    start();
    $given = $_POST['csrf'] ?? '';
    if (!is_string($given) || empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $given)) return false;
    return same_origin_request();
}

// Contrôle d'origine seul (sans jeton), pour les appels JSON de la page de test SFMN : Sec-Fetch-Site et
// Origin/Referer doivent désigner ce site quand le navigateur les envoie.
function same_origin_request(): bool
{
    $fetchSite = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) return false;
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') $origin = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $expected = parse_url(Config\site_public_url(), PHP_URL_HOST);
        if (parse_url($origin, PHP_URL_HOST) !== $expected) return false;
    }
    return true;
}

function flash_set(array $message): void
{
    start();
    $_SESSION['flash'] = $message;
}

function flash_take(): ?array
{
    start();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

// Les sessions abandonnées ne sont jamais nettoyées par PHP quand session.save_path est
// personnalisé (le ramasse-miettes dépend de la distribution): on le fait à chaque connexion.
function prune_old_files(): void
{
    $dir = Config\var_dir() . '/sessions';
    foreach (glob($dir . '/sess_*') ?: [] as $file) {
        if (time() - (int) @filemtime($file) > ABSOLUTE_SECONDS) @unlink($file);
    }
}
