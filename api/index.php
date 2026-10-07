<?php
// Point d'entrée unique de l'API PHP (équivalent de server.js). Toutes les URLs publiques
// restent celles de la version Node (/api/calculate, /api/config, /health...): le
// .htaccess de ce dossier réécrit tout vers ce fichier, aucun ".php" n'apparaît côté client.
declare(strict_types=1);

// Les avertissements PHP ne doivent jamais s'afficher dans une réponse JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Pas de Content-Type automatique (text/html) sur les réponses sans corps, ex. OPTIONS → 204.
ini_set('default_mimetype', '');

require __DIR__ . '/calculation.php';
require __DIR__ . '/lib/json.php';
require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/geo.php';
require __DIR__ . '/lib/measurements.php';
require __DIR__ . '/lib/sfmn.php';

use Radiv\Calculation;
use Radiv\Config;
use Radiv\Geo;
use Radiv\Http;
use Radiv\Measurements;
use Radiv\Sfmn;
use Radiv\Store;

Config\boot();
Http\set_cors_headers();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if ($method === 'GET' && $path === '/api/config') {
        $sfmn = Config\sfmn_mode_enabled();
        Http\send_json(200, [
            'isotopes' => Calculation\isotopes(),
            'default_isotope_code' => 'iode131_25_fixation',
            'cure_options_by_isotope' => Calculation\cure_options_by_isotope(),
            'calculation_modes' => $sfmn ? ['local', 'sfmn'] : ['local'],
            'default_calculation_mode' => $sfmn ? 'sfmn' : 'local',
        ] + ($sfmn ? ['sfmn_calculator_url' => Config\env('SFMN_CALCULATOR_URL')] : [])); // absent (pas vide) si désactivé
        exit;
    }

    if ($method === 'GET' && $path === '/api/public-config') {
        Http\send_json(200, [
            'recaptcha_site_key' => Config\env('RECAPTCHA_SITE_KEY'),
            // Reflète la config de journalisation actuelle: sert à générer une page RGPD qui
            // reste correcte sans édition manuelle à chaque changement de .env.
            'measurement_logging_level' => Config\logging_level(),
            'logs_retention_months' => Config\logs_retention_months(),
        ]);
        exit;
    }

    if ($method === 'POST' && $path === '/api/calculate') {
        $ip = Http\client_ip();
        $body = Http\read_body();
        if ($body === null) {
            Http\send_json(413, ['ok' => false, 'error' => 'Requête trop volumineuse.']);
            exit;
        }
        if (!Store\rate_limit_hit('calculate', $ip, Config\int_env('CALCULATE_RATE_LIMIT', 60))) {
            Http\send_json(429, ['ok' => false, 'error' => 'Trop de requêtes. Réessayez dans une minute.']);
            exit;
        }
        $payload = [];
        if ($body !== '') {
            $decoded = json_decode($body, true);
            // null = JSON invalide, ou littéral "null" (que Node rejetait aussi: pas de champs).
            if ($decoded === null) {
                Http\send_json(400, ['error' => 'JSON invalide']);
                exit;
            }
            $payload = is_array($decoded) ? $decoded : []; // scalaire JSON (5, "x"): aucun champ
        }
        $sfmnEnabled = Config\sfmn_mode_enabled();
        $rawMode = $payload['calculation_mode'] ?? null;
        $mode = ($rawMode === null || $rawMode === false || $rawMode === '' || $rawMode === 0 || $rawMode === 0.0)
            ? ($sfmnEnabled ? 'sfmn' : 'local')
            : mb_strtolower(is_scalar($rawMode) ? Sfmn\js_string($rawMode) : 'object');
        // SFMN désactivé côté serveur: on ne fait pas échouer la requête, on bascule sur le
        // calcul local (formalismes alignés) plutôt que d'imposer à chaque client (RIS, scripts
        // externes) de gérer lui-même ce repli.
        if ($mode === 'sfmn' && !$sfmnEnabled) $mode = 'local';
        $result = $mode === 'sfmn' ? Sfmn\calculate($payload) : Calculation\calculate($payload);
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
            ] + ($level === 'full' ? ['input' => Measurements\sanitize_input($payload), 'result' => $result] : []));
        }
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
