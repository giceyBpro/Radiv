<?php
// Configuration runtime: même fichiers .env et mêmes variables que server.js (noms inchangés),
// pour qu'un .env existant fonctionne tel quel après bascule.
declare(strict_types=1);

namespace Radiv\Config;

// Dossier de l'API (celui qui contient index.php): .env, logs/ et var/ y vivent, jamais dans
// une zone servie telle quelle (le .htaccess du dossier en interdit l'accès, voir api/.htaccess).
function base_dir(): string
{
    return dirname(__DIR__);
}

// Équivalent de loadDotEnv: l'environnement réel l'emporte sur le fichier si non vide.
function load_dotenv(string $file): void
{
    if (!is_file($file)) return;
    $content = (string) file_get_contents($file);
    foreach (preg_split('/\r?\n/', $content) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') continue;
        $idx = strpos($trimmed, '=');
        if ($idx === false) continue;
        $key = trim(substr($trimmed, 0, $idx));
        $value = trim(substr($trimmed, $idx + 1));
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            // Valeur entre guillemets: tout ce qui suit la guillemet fermante (commentaire
            // compris) est ignoré, sans risque de tronquer un '#' légitime à l'intérieur.
            $closing = strpos($value, $value[0], 1);
            if ($closing !== false) $value = substr($value, 1, $closing - 1);
        } elseif (preg_match('/\s#/', $value, $m, PREG_OFFSET_CAPTURE)) {
            // Commentaire en fin de ligne (KEY=valeur  # commentaire): uniquement un '#'
            // précédé d'un espace, pour ne pas tronquer une URL avec fragment (#ancre).
            $value = trim(substr($value, 0, $m[0][1]));
        }
        $current = getenv($key);
        if ($current === false || $current === '') {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

function boot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    load_dotenv(base_dir() . '/.runtime.env');
    load_dotenv(base_dir() . '/.env');
}

// process.env.X || fallback
function env(string $name, string $fallback = ''): string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $fallback : $value;
}

// String(process.env.X ?? default).toLowerCase() !== 'false'
function flag(string $name, string $default = 'true'): bool
{
    $value = getenv($name);
    return strtolower($value === false ? $default : $value) !== 'false';
}

function int_env(string $name, int $default): int
{
    $raw = env($name);
    return is_numeric($raw) ? (int) $raw : $default;
}

function logging_level(): string
{
    $raw = strtolower(trim(env('MEASUREMENT_LOGGING_LEVEL', 'full')));
    if (!in_array($raw, ['none', 'user', 'full'], true)) {
        error_log("MEASUREMENT_LOGGING_LEVEL invalide ({$raw}); fallback sur \"full\"");
        return 'full';
    }
    return $raw;
}

// Purge automatique des fichiers de logs plus vieux que N mois. Vide/absent = pas de purge.
function logs_retention_months(): ?int
{
    $raw = trim(env('LOGS_RETENTION_MONTHS'));
    if ($raw === '') return null;
    if (!preg_match('/^\d+$/', $raw) || (int) $raw <= 0) {
        error_log("LOGS_RETENTION_MONTHS invalide ({$raw}); purge désactivée");
        return null;
    }
    return (int) $raw;
}

// Proxies autorisés à définir X-Forwarded-For. Absent = reverse-proxy local; défini mais
// vide = l'en-tête est ignoré totalement.
function trusted_proxies(): array
{
    $raw = getenv('TRUSTED_PROXIES');
    if ($raw === false) $raw = '127.0.0.1,::1';
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($e) => $e !== ''));
}

function cors_origin(): string
{
    // API_CORS_ORIGIN est le nom utilisé par .env.example; CORS_ORIGIN reste accepté.
    return env('API_CORS_ORIGIN', env('CORS_ORIGIN', '*'));
}

function sfmn_mode_enabled(): bool
{
    return flag('SFMN_MODE_ENABLED');
}

function admin_measurements_enabled(): bool
{
    return flag('ADMIN_MEASUREMENTS_ENABLED');
}

function logs_dir(): string
{
    return base_dir() . '/logs';
}

// Données d'état partagées entre requêtes (compteurs de rate-limit, cache géoIP) quand APCu
// n'est pas utilisé. Même protection d'accès que logs/.
function var_dir(): string
{
    return base_dir() . '/var';
}

// Crée le dossier s'il manque et y pose un .htaccess qui en interdit l'accès web: ces
// dossiers contiennent des IP et des données de mesure.
function ensure_private_dir(string $dir): bool
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return true;
}

// --- Administration du site (connexion Google, mises à jour) -------------------------------

// URL publique de l'API (ex. https://www.example.org/api), sans slash final. Sert à construire
// l'URL de retour OAuth: jamais déduite de l'en-tête Host, qui est contrôlé par l'appelant.
function api_public_url(): string
{
    return rtrim(env('API_PUBLIC_URL'), '/');
}

// Adresses Google autorisées à administrer le site (liste séparée par des virgules).
function admin_google_emails(): array
{
    $list = array_map(static fn ($e) => strtolower(trim($e)), explode(',', env('ADMIN_GOOGLE_EMAILS')));
    return array_values(array_filter($list, static fn ($e) => $e !== ''));
}

// La page d'administration n'existe (ne répond autre chose que 404) que si tout est configuré.
function admin_site_enabled(): bool
{
    return flag('ADMIN_SITE_ENABLED')
        && env('GOOGLE_CLIENT_ID') !== '' && env('GOOGLE_CLIENT_SECRET') !== ''
        && api_public_url() !== '' && admin_google_emails() !== [];
}

// Les mises à jour depuis l'interface sont désactivées tant qu'on ne les active pas explicitement.
function admin_update_enabled(): bool
{
    return strtolower(env('ADMIN_UPDATE_ENABLED', 'false')) === 'true';
}

function web_root(): string
{
    return dirname(base_dir());
}

// Variante pour les dossiers de préparation et de destination du site (publics ou temporaires):
// pas de .htaccess "Require all denied" à y poser, uniquement la création récursive.
function ensure_dir(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
}
