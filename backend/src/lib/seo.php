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
    '/patient' => ['patient.html', '0.8', 'monthly', 'Informations pour le patient et l\'entourage après une radiothérapie interne vectorisée (RIV)'],
    '/legal' => ['mentions-legales.html', '0.3', 'yearly', 'Mentions légales, conditions d\'utilisation et données personnelles'],
];

// Adresse demandée => adresse publique de la page (ou null si ce n'est pas une page gérée ici).
function page_for_path(string $path): ?string
{
    return match (rtrim($path, '/') === '' ? '/' : rtrim($path, '/')) {
        '/', '/index.html' => '/',
        '/patient', '/patient.html' => '/patient',
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
    // Ici le site est en service (en maintenance, app.php répond avant d'arriver ici) : le « portail » de maintenance de l'accueil
    // (avis + style qui masque la page, levé par un script) est retiré, pour que les robots sans JavaScript lisent le vrai contenu.
    $html = (string) preg_replace('#<!--MAINTENANCE-GATE-->.*?<!--/MAINTENANCE-GATE-->#s', '', $html);
    // Page d'index des informations : liste des fiches publiées (rien tant qu'aucune n'est publiée).
    $html = str_replace('<!--FICHES-->', \Radiv\Fiches\index_html(), $html);
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
    // Le navigateur garde app.js en cache (jusqu'à une heure) : la version dans l'adresse change dès que le fichier change.
    $app = rtrim(Config\web_root(), '/') . '/app.js';
    if (is_file($app)) $html = preg_replace('#(<script src="app\.js)(?:\?v=[0-9A-Za-z]*)?(")#', '$1?v=' . dechex((int) filemtime($app)) . '$2', $html) ?? $html;
    return $html;
}

// Fiche publiée (statut « published » dans fiches_data.php) demandée à /patient/<slug>, sinon null : un brouillon n'est jamais servi.
function fiche_for_path(string $path): ?array
{
    if (preg_match('#^/patient/([a-z0-9-]{1,60})/?$#', $path, $m) !== 1) return null;
    $fiche = \Radiv\Fiches\get($m[1]);
    return ($fiche !== null && \Radiv\Fiches\published($fiche)) ? $fiche : null;
}

// Page d'une fiche publiée : HTML de fiches.php complété des balises propres à l'adresse du site (canonique, Open Graph, données structurées).
function render_fiche(array $fiche): string
{
    $html = \Radiv\Fiches\page_html($fiche, false);
    $site = Config\site_public_url();
    if ($site === '' || preg_match('#^https?://[^/\s]+$#', $site) !== 1) return $html;
    $url = $site . '/patient/' . $fiche['slug'];
    $name = Config\site_name();
    $graph = [
        ['@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => (string) $fiche['h1'], 'description' => (string) $fiche['description'], 'inLanguage' => 'fr',
         'isPartOf' => ['@type' => 'WebSite', '@id' => $site . '/#website', 'url' => $site . '/', 'name' => $name]],
    ];
    if (!empty($fiche['faq'])) {
        $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(static fn (array $qa): array => [
            '@type' => 'Question', 'name' => (string) $qa['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) $qa['a'])],
        ], $fiche['faq'])];
    }
    $head = '<link rel="canonical" href="' . esc($url) . '" />' . "\n"
        . '<meta property="og:type" content="website" />' . "\n"
        . '<meta property="og:url" content="' . esc($url) . '" />' . "\n"
        . '<meta property="og:site_name" content="' . esc($name) . '" />' . "\n"
        . '<meta property="og:title" content="' . esc((string) $fiche['title']) . '" />' . "\n"
        . '<meta property="og:description" content="' . esc((string) $fiche['description']) . '" />' . "\n"
        . '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . "\n";
    return str_replace('</head>', $head . '</head>', $html);
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
            'description' => 'Calcul des durées de restriction de contact (conjoint, enfants, collègues, transports) après un traitement par radiothérapie interne vectorisée : iode-131, PSMA-177Lu, radium-223, lutétium-177, microsphères.',
            'applicationCategory' => 'HealthApplication', 'operatingSystem' => 'Tous (navigateur web)', 'inLanguage' => 'fr',
            'isAccessibleForFree' => true, 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
            'isPartOf' => ['@id' => $site . '/#website'], 'publisher' => ['@id' => $site . '/#publisher'],
            'dateModified' => $modified !== '' ? $modified : null,
        ], static fn ($v) => $v !== null);
    } else {
        $graph[] = array_filter([
            '@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => PAGES[$publicPath][3] ?? 'Mentions légales, conditions d\'utilisation et données personnelles',
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
    foreach (\Radiv\Fiches\published_list() as $fiche) {
        $out .= '  <url>' . "\n" . '    <loc>' . esc($site . '/patient/' . $fiche['slug']) . '</loc>' . "\n"
            . '    <changefreq>monthly</changefreq>' . "\n" . '    <priority>0.7</priority>' . "\n" . '  </url>' . "\n";
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
        // Ancienne adresse /informations (devenue /patient) : redirection permanente, fiches comprises.
        if (preg_match('#^/informations(?:\.html)?(/[a-z0-9/-]*)?$#', $path, $old) === 1) {
            http_response_code(301);
            header('Location: /patient' . ($old[1] ?? ''));
            header('Cache-Control: public, max-age=3600');
            return true;
        }
        $fiche = fiche_for_path($path);
        if ($fiche !== null) return send('text/html; charset=utf-8', render_fiche($fiche), $method === 'HEAD');
        $page = page_for_path($path);
        if ($page === null) return false;
        return send('text/html; charset=utf-8', render($page), $method === 'HEAD');
    } catch (\Throwable $e) {
        error_log('[SEO] ' . get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}
