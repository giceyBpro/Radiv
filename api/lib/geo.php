<?php
// Géolocalisation approximative de l'IP pour la journalisation (même fournisseurs, même ordre
//). Résultats mis en cache (APCu ou fichier, voir store.php).
declare(strict_types=1);

namespace Radiv\Geo;

use Radiv\Http;
use Radiv\Store;

function resolve(string $ip): ?array
{
    $normalized = Http\normalize_ip($ip);
    if ($normalized === '') return null;
    if (Http\is_private_or_local_ip($normalized)) {
        return ['ip' => $normalized, 'scope' => 'private_or_local'];
    }
    $cached = Store\geo_get($normalized);
    if ($cached !== null) return $cached;

    $enc = rawurlencode($normalized);
    $providers = [
        ['url' => "https://ipwho.is/{$enc}", 'parse' => static fn ($d) => (is_array($d) && ($d['success'] ?? false)) ? [
            'ip' => $normalized, 'provider' => 'ipwho.is',
            'country' => $d['country'] ?? null, 'region' => $d['region'] ?? null, 'city' => $d['city'] ?? null,
            'latitude' => $d['latitude'] ?? null, 'longitude' => $d['longitude'] ?? null,
        ] : null],
        ['url' => "https://ipapi.co/{$enc}/json/", 'parse' => static fn ($d) => (is_array($d) && !isset($d['error'])) ? [
            'ip' => $normalized, 'provider' => 'ipapi.co',
            'country' => $d['country_name'] ?? null, 'region' => $d['region'] ?? null, 'city' => $d['city'] ?? null,
            'latitude' => $d['latitude'] ?? null, 'longitude' => $d['longitude'] ?? null,
        ] : null],
        ['url' => "https://ip-api.com/json/{$enc}", 'parse' => static fn ($d) => (is_array($d) && ($d['status'] ?? '') === 'success') ? [
            'ip' => $normalized, 'provider' => 'ip-api.com',
            'country' => $d['country'] ?? null, 'region' => $d['regionName'] ?? null, 'city' => $d['city'] ?? null,
            'latitude' => $d['lat'] ?? null, 'longitude' => $d['lon'] ?? null,
        ] : null],
    ];
    foreach ($providers as $provider) {
        $response = Http\fetch($provider['url'], ['timeout_ms' => 1600, 'connect_timeout_ms' => 1600]);
        if (!$response || $response['status'] < 200 || $response['status'] > 299) continue; // fournisseur suivant
        $data = json_decode($response['body'], true);
        $geo = $provider['parse']($data);
        if ($geo) {
            Store\geo_set($normalized, $geo);
            return $geo;
        }
    }
    return null;
}
