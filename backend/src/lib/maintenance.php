<?php
// Maintenance du site, pilotée depuis l'administration (/auth, onglet Site) et mémorisée dans data/var/maintenance.json
// (créer ou supprimer ce fichier par FTP a le même effet si /auth est inaccessible).
//   'site' : la page d'accueil n'affiche plus que « site en maintenance » avec le lien vers l'outil de la SFMN ;
//            l'API continue de fonctionner.
//   'full' : comme 'site', et l'API de calcul et le formulaire de contact répondent 503 (code MAINTENANCE).
// Jamais touchés : /auth (pour pouvoir lever la maintenance), /health, /api/config et /api/public-config (lecture seule,
// utilisés par les contrôles de fonctionnement des mises à jour).
declare(strict_types=1);

namespace Radiv\Maintenance;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;

const MODES = ['off', 'site', 'full'];
const DEFAULT_SFMN_URL = 'https://www.acoramen.net/index.php?option=com_evictionperiod&Itemid=5141&lang=fr';

function file(): string { return Config\var_dir() . '/maintenance.json'; }

// ['mode' => 'off'|'site'|'full', 'since' => ISO, 'by' => e-mail]
function state(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents(file());
    $data = $raw === false ? null : json_decode($raw, true);
    $mode = is_array($data) && in_array($data['mode'] ?? '', MODES, true) ? $data['mode'] : 'off';
    return $cache = ['mode' => $mode, 'since' => (string) ($data['since'] ?? ''), 'by' => (string) ($data['by'] ?? '')];
}

function mode(): string { return state()['mode']; }

function set_mode(string $mode, string $by): bool
{
    if (!in_array($mode, MODES, true)) return false;
    if ($mode === 'off') return !is_file(file()) || @unlink(file());
    Config\ensure_private_dir(dirname(file()));
    return file_put_contents(file(), json_encode(['mode' => $mode, 'since' => gmdate('Y-m-d\TH:i:s\Z'), 'by' => $by], JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function sfmn_url(): string { return Config\sfmn_tool_url() ?: DEFAULT_SFMN_URL; }

// Page de maintenance complète (HTML autonome : aucune ressource externe).
function page_html(): string
{
    $url = htmlspecialchars(sfmn_url(), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars(Config\site_name(), ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Site en maintenance — {$name}</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fa;color:#1d2733;font:16px/1.5 system-ui,sans-serif}main{max-width:560px;margin:24px;padding:28px;background:#fff;border:1px solid #d5dde6;border-radius:12px}h1{margin-top:0;font-size:1.5rem}a{color:#0b5cad;font-weight:600}.small{font-size:.9rem;color:#566}</style>
</head>
<body><main>
<h1>Site en maintenance</h1>
<p>Le site {$name} est momentanément en maintenance. Merci de votre compréhension.</p>
<p>En attendant, vous pouvez calculer les durées de restriction avec l'outil en ligne de la SFMN : <a href="{$url}" target="_blank" rel="noopener noreferrer">accéder à l'outil de la SFMN</a>.</p>
<p class="small">Ce site est indépendant de la SFMN et de son groupe de travail Radioprotection.</p>
</main></body>
</html>
HTML;
}

// Réponse 503 de la page d'accueil en maintenance.
function send_page(bool $headOnly): void
{
    $body = page_html();
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 3600');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: ' . strlen($body));
    if (!$headOnly) echo $body;
}

// Réponse 503 des appels d'API arrêtés par la maintenance complète.
function api_stopped(): array
{
    return ['ok' => false, 'error' => ['code' => 'MAINTENANCE', 'message' => 'Le site est en maintenance : l\'API est momentanément arrêtée. Réessayez plus tard.']];
}
