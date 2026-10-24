<?php
// Référencement : pages publiques indexables servies avec les balises qui dépendent de l'adresse du site (canonique absolue, Open Graph,
// données structurées JSON-LD, nom du site dans le titre de page) et plan du site (sitemap.xml) à jour. Ces balises ne peuvent pas être écrites
// dans les fichiers HTML (l'adresse du site est propre à chaque installation) : le fichier HTML du site est lu tel quel et complété ici.
// Si l'adresse du site n'est pas configurée ou si le fichier manque, la page est servie sans modification (jamais d'erreur visible).
declare(strict_types=1);

namespace Radiv\Seo;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Updater;

// Pages indexables : adresse publique => [fichier HTML de la racine web, priorité du plan du site, fréquence]
const PAGES = [
    '/' => ['index.html', '1.0', 'monthly'],
    '/legal' => ['mentions-legales.html', '0.3', 'yearly'],
];

// Adresse demandée => adresse publique de la page (ou null si ce n'est pas une page gérée ici).
function page_for_path(string $path): ?string
{
    return match (rtrim($path, '/') === '' ? '/' : rtrim($path, '/')) {
        '/', '/index.html' => '/',
        '/legal', '/mentions-legales.html' => '/legal',
        default => null,
    };
}

function esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Page complète : fichier HTML + balises dépendant de l'adresse du site. null si le fichier est introuvable.
function render(string $publicPath): ?string
{
    $file = rtrim(Config\web_root(), '/') . '/' . (PAGES[$publicPath][0] ?? '');
    $html = is_file($file) ? @file_get_contents($file) : false;
    if (!is_string($html) || $html === '') return null;
    $site = Config\site_public_url();
    if ($site === '' || preg_match('#^https?://[^/\s]+$#', $site) !== 1) return $html;
    $name = Config\site_name();
    $url = $site . ($publicPath === '/' ? '/' : $publicPath);
    $image = $site . '/og-image.png';

    $head = '<link rel="canonical" href="' . esc($url) . '" />' . "\n"
        . '  <meta property="og:url" content="' . esc($url) . '" />' . "\n"
        . '  <meta property="og:site_name" content="' . esc($name) . '" />' . "\n"
        . '  <meta property="og:image" content="' . esc($image) . '" />' . "\n"
        . '  <meta property="og:image:width" content="1200" />' . "\n"
        . '  <meta property="og:image:height" content="630" />' . "\n"
        . '  <meta name="twitter:image" content="' . esc($image) . '" />' . "\n"
        . '  <script type="application/ld+json">' . json_ld($publicPath, $site, $url, $name) . '</script>';
    $html = str_replace('<!--SEO-HEAD-->', $head, $html, $n);
    // Lien vers l'outil en ligne de la SFMN : adresse de SFMN_CALCULATOR_URL (celle du fichier HTML sert de repli)
    $tool = Config\sfmn_tool_url();
    if ($tool !== '') {
        $html = (string) preg_replace_callback('/(data-sfmn-link href=")[^"]*(")/', static fn (array $m): string => $m[1] . esc($tool) . $m[2], $html);
    }
    // Nom du site visible dès le HTML (sans attendre le JavaScript)
    $html = str_replace('<span id="siteNameBanner">[Nom du site]</span>', '<span id="siteNameBanner">' . esc($name) . '</span>', $html);
    return $html;
}

function json_ld(string $publicPath, string $site, string $url, string $name): string
{
    $owner = Config\copyright_owner();
    $modified = Updater\installed_date();
    $publisher = ['@type' => 'Organization', '@id' => $site . '/#publisher', 'name' => $owner, 'url' => $site . '/'];
    $graph = [
        ['@type' => 'WebSite', '@id' => $site . '/#website', 'url' => $site . '/', 'name' => $name, 'inLanguage' => 'fr', 'publisher' => ['@id' => $site . '/#publisher']],
        $publisher,
    ];
    if ($publicPath === '/') {
        $graph[] = array_filter([
            '@type' => 'WebApplication', '@id' => $site . '/#calculateur', 'url' => $url,
            'name' => 'Calculateur des durées de restriction de contact après radiothérapie interne vectorisée (RIV)',
            'description' => 'Calcul gratuit des durées de restriction de contact (conjoint, enfants, collègues, transports) après un traitement par radiothérapie interne vectorisée : iode-131, PSMA-177Lu, radium-223, lutétium-177, microsphères.',
            'applicationCategory' => 'HealthApplication', 'operatingSystem' => 'Tous (navigateur web)', 'inLanguage' => 'fr',
            'isAccessibleForFree' => true, 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
            'isPartOf' => ['@id' => $site . '/#website'], 'publisher' => ['@id' => $site . '/#publisher'],
            'dateModified' => $modified !== '' ? $modified : null,
        ], static fn ($v) => $v !== null);
    } else {
        $graph[] = array_filter([
            '@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => 'Mentions légales, conditions d\'utilisation et données personnelles',
            'inLanguage' => 'fr', 'isPartOf' => ['@id' => $site . '/#website'], 'dateModified' => $modified !== '' ? $modified : null,
        ], static fn ($v) => $v !== null);
    }
    return (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

// sitemap.xml : une entrée par page indexable, avec la date de dernière modification du fichier.
function sitemap(): ?string
{
    $site = Config\site_public_url();
    if ($site === '' || preg_match('#^https?://[^/\s]+$#', $site) !== 1) return null;
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (PAGES as $path => [$file, $priority, $freq]) {
        $full = rtrim(Config\web_root(), '/') . '/' . $file;
        if (!is_file($full)) continue;
        $out .= '  <url>' . "\n" . '    <loc>' . esc($site . ($path === '/' ? '/' : $path)) . '</loc>' . "\n"
            . '    <lastmod>' . gmdate('Y-m-d', (int) filemtime($full)) . '</lastmod>' . "\n"
            . '    <changefreq>' . $freq . '</changefreq>' . "\n" . '    <priority>' . $priority . '</priority>' . "\n" . '  </url>' . "\n";
    }
    return $out . '</urlset>' . "\n";
}

// Envoie une réponse mise en cache côté navigateur (ETag, 304). $body null = rien à servir (la requête continue vers le 404 général).
function send(string $contentType, ?string $body, bool $headOnly): bool
{
    if ($body === null) return false;
    $etag = '"' . substr(sha1($body), 0, 24) . '"';
    // Page web (et non réponse d'API) : ni en-têtes CORS ni politique de l'API ; les en-têtes de sécurité des pages viennent du .htaccess
    foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Headers', 'Access-Control-Allow-Methods', 'Referrer-Policy', 'Strict-Transport-Security'] as $h) header_remove($h);
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=300');
    header('Vary: Accept-Encoding');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return true;
    }
    http_response_code(200);
    header('Content-Length: ' . strlen($body));
    if (!$headOnly) echo $body;
    return true;
}

// Point d'entrée de app.php : true si la requête a été traitée.
function handle(string $method, string $path): bool
{
    if ($method !== 'GET' && $method !== 'HEAD') return false;
    try {
        if ($path === '/sitemap.xml') return send('application/xml; charset=utf-8', sitemap(), $method === 'HEAD');
        $page = page_for_path($path);
        if ($page === null) return false;
        return send('text/html; charset=utf-8', render($page), $method === 'HEAD');
    } catch (\Throwable $e) {
        error_log('[SEO] ' . get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}
