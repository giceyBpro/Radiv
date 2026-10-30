<?php
// Application de l'API PHP, chargée par la façade publique api/index.php. Toutes les URLs publiques
// (/api/calculate, /api/config, /health, /auth...) arrivent ici : le .htaccess de la façade réécrit tout vers
// elle, aucun ".php" n'apparaît côté client.
declare(strict_types=1);

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de la façade api/index.php

// Les avertissements PHP ne doivent jamais s'afficher dans une réponse JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Pas de Content-Type automatique (text/html) sur les réponses sans corps, ex. OPTIONS → 204.
ini_set('default_mimetype', '');

// Moteur de calcul partagé (dossier core/, transférable dans un dépôt indépendant) : installé dans backend/core/,
// ou à la racine du dépôt en développement. Mode d'arrondi : celui du moteur (core/data/settings.json), sauf surcharge par la variable RESTRICTION_ROUNDING_MODE (lue à chaque calcul).
require is_file(__DIR__ . '/../core/src/bootstrap.php') ? __DIR__ . '/../core/src/bootstrap.php' : __DIR__ . '/../../core/src/bootstrap.php';
\RivEngine\set_rounding_mode(static fn () => getenv('RESTRICTION_ROUNDING_MODE') ?: null);
require __DIR__ . '/lib/json.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/geo.php';
require __DIR__ . '/lib/disk.php';
require __DIR__ . '/lib/measurements.php';
require __DIR__ . '/lib/providers.php';
require __DIR__ . '/lib/seo.php';
require __DIR__ . '/lib/maintenance.php';
require __DIR__ . '/lib/xplore_access.php';
require __DIR__ . '/lib/sfmn.php';
require __DIR__ . '/lib/contact.php';
require __DIR__ . '/lib/session.php';
require __DIR__ . '/lib/google.php';
require __DIR__ . '/lib/updater.php';
require __DIR__ . '/lib/core_lab.php';
require __DIR__ . '/lib/frontend.php';
require __DIR__ . '/lib/admin_measures.php';
require __DIR__ . '/lib/admin_sfmn.php';
require __DIR__ . '/lib/admin_core.php';
require __DIR__ . '/lib/admin_maintenance.php';
require __DIR__ . '/lib/admin_xplore.php';
require __DIR__ . '/lib/admin_radiv.php';
require __DIR__ . '/lib/admin_site.php';

use Radiv\AdminSite;
use RivEngine as Calculation;
use Radiv\Config;
use Radiv\Contact;
use Radiv\Frontend;
use Radiv\Geo;
use Radiv\Http;
use Radiv\Json;
use Radiv\Maintenance;
use Radiv\Measurements;
use Radiv\Providers;
use Radiv\Seo;
use Radiv\Sfmn;
use Radiv\Store;
use Radiv\Updater;

Config\boot();
Http\set_cors_headers();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    // Maintenance (pilotée depuis /auth) : l'accueil n'affiche que l'avis de maintenance ; en maintenance complète, l'API de
    // calcul répond aussi 503. Le formulaire de contact, /auth, /health et la configuration en lecture seule restent disponibles.
    if (Maintenance\mode() !== 'off') {
        if (($method === 'GET' || $method === 'HEAD') && Seo\page_for_path($path) === '/') {
            Maintenance\send_page($method === 'HEAD');
            exit;
        }
        if (Maintenance\mode() === 'full' && $method === 'POST' && $path === '/api/calculate') {
            header('Retry-After: 3600');
            Http\send_json(503, Maintenance\api_stopped());
            exit;
        }
    }

    // Pages indexables (accueil, mentions légales) et plan du site : HTML du site complété des balises propres à l'adresse du site
    if (Seo\handle($method, $path)) exit;

    if ($method === 'GET' && $path === '/api/config') {
        Http\send_json(200, Frontend\config(false));
        exit;
    }

    if ($method === 'GET' && $path === '/api/public-config') {
        Http\send_json(200, Frontend\public_config());
        exit;
    }

    if ($method === 'POST' && $path === '/api/calculate') {
        $ip = Http\client_ip();
        $body = Http\read_body();
        if ($body === null) {
            Http\send_json(413, ['ok' => false, 'error' => 'Requête trop volumineuse.']);
            exit;
        }
        // Appelant : pages du site, ou fournisseur identifié par sa clé d'API (obligatoire pour les appels extérieurs)
        $caller = Providers\identify($ip);
        if (isset($caller['error'])) {
            Http\send_json($caller['status'], ['ok' => false, 'error' => $caller['error'], 'code' => $caller['code']]);
            exit;
        }
        $rateKey = $caller['kind'] === 'key' ? 'p:' . $caller['id'] : $ip;
        if (!Store\rate_limit_hit('calculate', $rateKey, $caller['limit'] ?? Config\int_env('CALCULATE_RATE_LIMIT', 60))) {
            Http\send_json(429, ['ok' => false, 'error' => 'Trop de requêtes. Réessayez dans une minute.']);
            exit;
        }
        $payload = [];
        if ($body !== '') {
            $decoded = json_decode($body, true);
            // null = JSON invalide, ou littéral "null" (pas de champs).
            if ($decoded === null) {
                Http\send_json(400, ['error' => 'JSON invalide']);
                exit;
            }
            $payload = is_array($decoded) ? $decoded : []; // scalaire JSON (5, "x"): aucun champ
        }
        // Origine de la requête (site ou logiciel appelant) : retirée du payload pour ne pas fausser le calcul
        $provider = Measurements\clean_provider($payload['provider'] ?? null);
        unset($payload['provider']);
        // Un éventuel « calculation_mode » (ancien paramètre d'API) est ignoré sans erreur : le calcul est toujours celui du moteur.
        unset($payload['calculation_mode']);
        $result = Calculation\calculate($payload);
        $timestamp = Measurements\now_iso();
        Http\send_json(200, $result);

        $level = Config\logging_level();
        if ($level !== 'none') {
            // Réponse déjà partie: la géoIP (jusqu'à 3 appels réseau) ne retarde plus l'appelant.
            Http\finish_response();
            Measurements\maintenance();
            Measurements\append([
                'timestamp' => $timestamp,
                'ip' => $ip,
                'ip_geo' => Geo\resolve($ip),
            ] + ($caller['id'] !== null ? ['provider_id' => $caller['id']] : []) + ($provider !== null ? ['provider' => $provider] : []) + ($level === 'full' ? ['input' => Measurements\sanitize_input($payload), 'result' => $result] : []));
        }
        exit;
    }

    if ($method === 'POST' && $path === '/api/contact') {
        $body = Http\read_body();
        if ($body === null) {
            Http\send_json(413, ['ok' => false, 'error' => 'Requête trop volumineuse.']);
            exit;
        }
        $payload = [];
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if ($decoded === null) {
                Http\send_json(400, ['ok' => false, 'error' => 'JSON invalide']);
                exit;
            }
            $payload = is_array($decoded) ? $decoded : [];
        }
        $email = Contact\clean_field($payload['email'] ?? null);
        $message = Contact\clean_field($payload['message'] ?? null);
        $recaptchaToken = Contact\clean_field($payload['recaptcha_token'] ?? null);
        $ip = Http\client_ip();
        if ($email === '' || $message === '') {
            Http\send_json(400, ['ok' => false, 'error' => 'email et message sont obligatoires.']);
            exit;
        }
        if (!Contact\valid_email_shape($email)) {
            Http\send_json(400, ['ok' => false, 'error' => 'Format email invalide.']);
            exit;
        }
        if (!Store\rate_limit_hit('contact', $ip, 5)) {
            Http\send_json(429, ['ok' => false, 'error' => 'Trop de demandes. Réessayez dans une minute.']);
            exit;
        }
        if (!Contact\verify_recaptcha($recaptchaToken, $ip)) {
            Http\send_json(400, ['ok' => false, 'error' => 'Échec vérification reCAPTCHA.']);
            exit;
        }
        $sent = Contact\send_contact_email($email, $message, $ip, Measurements\now_iso());
        if (!$sent['ok']) {
            Http\send_json(500, ['ok' => false, 'error' => 'Échec envoi email.']);
            exit;
        }
        Http\send_json(200, ['ok' => true]);
        exit;
    }

    // Administration du site (/auth: connexion Google, mesures, mises à jour): 404 générique tant qu'elle n'est pas configurée.
    if (AdminSite\handle($method, $path)) {
        exit;
    }

    if ($method === 'GET' && $path === '/health') {
        Http\send_json(200, ['ok' => true, 'time' => Measurements\now_iso()]);
        exit;
    }

    if (Http\wants_html()) {
        Http\send_html_error(404, 'Page introuvable', 'Cette adresse ne correspond à aucune ressource de l\'API RadIV.');
    } else {
        Http\send_json(404, ['error' => 'Not found']);
    }
} catch (\Throwable $e) {
    error_log('[API] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) Http\send_json(500, ['error' => 'Erreur interne']);
}
