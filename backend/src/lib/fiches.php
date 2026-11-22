<?php
// Fiches d'information par radiopharmaceutique (grand public) : données dans fiches_data.php, rendu HTML ici. Tant qu'une fiche est au
// statut « draft », elle n'est visible que dans l'administration (/auth/fiches, aperçu avec ses points de relecture) : aucune route
// publique ne la sert. La publication (route /informations/<slug>, plan du site) viendra ensuite, avec le même rendu.
declare(strict_types=1);

namespace Radiv\Fiches;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Contact;

function all(): array
{
    static $cache = null;
    return $cache ??= (static function (): array {
        $data = require __DIR__ . '/fiches_data.php';
        return is_array($data) ? $data : [];
    })();
}

function get(string $slug): ?array
{
    if (preg_match('/^[a-z0-9-]{1,60}$/', $slug) !== 1) return null;
    foreach (all() as $fiche) if (($fiche['slug'] ?? '') === $slug) return $fiche;
    return null;
}

function published(array $fiche): bool { return ($fiche['status'] ?? 'draft') === 'published'; }

// Fiches publiées (les brouillons ne sont jamais exposés publiquement).
function published_list(): array
{
    return array_values(array_filter(all(), static fn (array $f): bool => published($f)));
}

// Liste des fiches publiées pour la page /informations : section vide (rien) tant qu'aucune fiche n'est publiée.
function index_html(): string
{
    $list = published_list();
    if (!$list) return '';
    $out = "<h2>Fiches par traitement</h2>\n<ul class=\"fiches\">\n";
    foreach ($list as $fiche) {
        $out .= '<li><a href="/informations/' . esc((string) $fiche['slug']) . '">' . esc((string) $fiche['h1']) . '</a><small>' . esc((string) $fiche['description']) . "</small></li>\n";
    }
    return $out . "</ul>\n";
}

function esc(string $text): string { return Contact\escape_html($text); }

// Contenu (HTML) d'une fiche : introduction, sections, questions fréquentes. Les textes des données sont du HTML simple écrit par
// l'éditeur (strong, em, a) : jamais de saisie d'un visiteur.
function body_html(array $fiche): string
{
    $out = '<p>' . $fiche['lead'] . "</p>\n";
    foreach ($fiche['blocks'] as $block) {
        $level = (int) ($block['level'] ?? 2) === 3 ? 3 : 2;
        $out .= "<h{$level}>" . esc((string) $block['h']) . "</h{$level}>\n";
        foreach ($block['p'] ?? [] as $p) $out .= '<p>' . $p . "</p>\n";
        if (!empty($block['ul'])) $out .= '<ul>' . implode('', array_map(static fn ($li) => '<li>' . $li . '</li>', $block['ul'])) . "</ul>\n";
    }
    if (!empty($fiche['faq'])) {
        $out .= "<h2>Questions fréquentes</h2>\n";
        foreach ($fiche['faq'] as $qa) $out .= '<h3>' . esc((string) $qa['q']) . "</h3>\n<p>" . $qa['a'] . "</p>\n";
    }
    if (!empty($fiche['calc'])) {
        $out .= "<h2 id=\"calcul\">Calculateur de durée de restriction</h2>\n"
            . '<p class="notice">Le calculateur est destiné aux <strong>professionnels de santé</strong>. Les consignes personnalisées vous sont données par votre équipe de médecine nucléaire : elles peuvent différer de celles de ce site, adaptées à votre situation particulière, et ce sont <strong>toujours les consignes de l\'équipe qui doivent être appliquées</strong>.</p>' . "\n<ul>";
        foreach ($fiche['calc'] as $calc) {
            $out .= '<li><a href="/?isotope=' . esc(rawurlencode((string) $calc['code'])) . '">Ouvrir le calculateur : ' . esc((string) $calc['label']) . '</a> (professionnels de santé)</li>';
        }
        $out .= "</ul>\n";
    }
    if (!empty($fiche['links'])) {
        $out .= "<h2>Pour aller plus loin</h2>\n<ul>";
        foreach ($fiche['links'] as $link) {
            $out .= '<li><a href="' . esc((string) $link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc((string) $link['label']) . '</a></li>';
        }
        $out .= "</ul>\n";
    }
    return $out;
}

// Page complète d'une fiche (même présentation que /informations). $preview : bandeau « brouillon » et points de relecture, noindex.
function page_html(array $fiche, bool $preview): string
{
    $title = esc((string) $fiche['title']);
    $desc = esc((string) $fiche['description']);
    $banner = $preview
        ? '<p class="draft"><strong>BROUILLON : non publié.</strong> Cette page n\'est visible que dans l\'administration. <a href="/auth/fiches">← Toutes les fiches</a></p>' : '';
    $review = '';
    if ($preview && !empty($fiche['review'])) {
        $review = '<section class="review"><h2>Points à vérifier (non publiés)</h2><ul>'
            . implode('', array_map(static fn ($r) => '<li>' . esc((string) $r) . '</li>', $fiche['review'])) . '</ul></section>';
    }
    $h1 = esc((string) $fiche['h1']);
    $robots = $preview ? 'noindex, nofollow' : 'index, follow, max-image-preview:large';
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="{$robots}">
<meta name="description" content="{$desc}">
<title>{$title}</title>
<style>
:root{--paper:#fff;--bg:#eef1f3;--link:#1637b8}
body{margin:0;padding:24px;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;line-height:1.55}
.page{max-width:820px;margin:0 auto;background:var(--paper);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);padding:24px 28px}
h1{margin-top:0;font-size:1.55rem;line-height:1.25}
h2{font-size:1.15rem;border-top:1px solid #e2e6e9;padding-top:16px;margin-top:26px}
h3{font-size:1rem;margin:18px 0 4px}
a{color:var(--link)}a:focus-visible{outline:3px solid #1637b8;outline-offset:2px;border-radius:2px}.links{margin-bottom:14px;font-size:.95rem}
.draft{background:#fff4d6;border:1px solid #e6c36a;border-radius:8px;padding:10px 14px;margin:0 0 16px}
.review{background:#f3f6fb;border:1px solid #c4d6f2;border-radius:10px;padding:4px 16px 8px;margin-top:28px}
.small{color:#555;font-size:.9rem;margin-top:22px}
.notice{background:#fff4d6;border:1px solid #e6c36a;border-radius:8px;padding:10px 14px}
@media (max-width:560px){body{padding:10px}.page{padding:16px}}
</style>
</head>
<body>
<main class="page">
{$banner}<div class="links"><a href="/informations">← Informations pour le patient et l'entourage</a></div>
<h1>{$h1}</h1>
HTML
        . body_html($fiche)
        . '<p class="small">Ces informations sont générales et <strong>indicatives</strong> : l\'équipe de médecine nucléaire peut donner des consignes différentes, adaptées au contexte particulier d\'un patient : <strong>ce sont les consignes de l\'équipe qui sont à appliquer</strong>. En cas de doute, contactez-la. <a href="/legal">Mentions légales</a></p>'
        . $review . "\n</main>\n</body>\n</html>\n";
}
