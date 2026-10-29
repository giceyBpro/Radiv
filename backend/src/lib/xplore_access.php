<?php
// Accès aux modèles Xplore (page /xplore et fichiers downloads/*.xml), activable depuis l'administration (onglet Site).
//
// Ces fichiers sont servis directement par Apache : la seule façon fiable de les rendre inaccessibles est une règle dans le
// .htaccess de la racine web. Ce module pose (ou retire) un bloc balisé tout en haut de ce fichier :
//   - bloc présent  → /xplore, /xplore.html et downloads/*.xml répondent 404 ;
//   - bloc absent   → accès normal (état par défaut).
// Le reste du .htaccess n'est jamais modifié. Avant chaque écriture, une copie (.htaccess.bak-xplore) est faite, et le site est
// contrôlé après : en cas de problème, l'ancien contenu est rétabli. Les mises à jour (/auth, update.php) conservent ce bloc.
declare(strict_types=1);

namespace Radiv\Xplore;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Http;
use Radiv\Measurements;
use Radiv\Updater;

const BEGIN = '# >>> Xplore désactivé (administration) v1';
const END = '# <<< Xplore désactivé';
const PUBLIC_PATHS = ['/xplore', '/xplore.html', '/downloads/MN_Radiopro_APIRADIV_generique.xml', '/downloads/MN_Consignes-radioprotection_RadIV.xml'];

// Fichiers XML proposés dans l'administration, même quand l'accès public est coupé (nom => chemin dans la racine web).
function files(): array
{
    $out = [];
    foreach (PUBLIC_PATHS as $path) {
        if (!str_ends_with($path, '.xml')) continue;
        $file = rtrim(Config\web_root(), '/') . $path;
        if (is_file($file)) $out[basename($path)] = $file;
    }
    return $out;
}

function htaccess_file(): string { return rtrim(Config\web_root(), '/') . '/.htaccess'; }
function state_file(): string { return Config\var_dir() . '/xplore.json'; }

function block(): string
{
    return BEGIN . "\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^(xplore(\\.html)?/?|downloads/[^/]+\\.xml)$ - [R=404,L]\n</IfModule>\n" . END . "\n";
}

// Contenu du .htaccess sans le bloc (s'il y est).
function without_block(string $content): string
{
    // Le bloc est posé suivi d'une ligne vide : on retire les deux pour retrouver exactement le contenu d'origine.
    $pattern = '/' . preg_quote(BEGIN, '/') . '.*?' . preg_quote(END, '/') . "(?:\\n\\n|\\n)?/s";
    return (string) preg_replace($pattern, '', $content);
}

// true si l'accès est coupé (bloc présent dans le .htaccess).
function disabled(): bool
{
    $content = @file_get_contents(htaccess_file());
    return $content !== false && str_contains($content, BEGIN);
}

function info(): array
{
    $raw = @file_get_contents(state_file());
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? ['since' => (string) ($data['since'] ?? ''), 'by' => (string) ($data['by'] ?? '')] : ['since' => '', 'by' => ''];
}

function write_atomic(string $file, string $content): bool
{
    $mode = is_file($file) ? (fileperms($file) & 0777) : 0644;
    $tmp = $file . '.tmp' . bin2hex(random_bytes(3));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    @chmod($tmp, $mode);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

// Réponse HTTP d'une adresse publique du site (pour vérifier l'effet de la règle) ; null si l'appel est impossible d'ici.
function public_status(string $path): ?int
{
    $response = Http\fetch(Config\site_public_url() . $path, ['timeout_ms' => 8000, 'connect_timeout_ms' => 4000, 'follow' => false]);
    return $response ? (int) $response['status'] : null;
}

// Active ou coupe l'accès. Retourne ['ok' => bool, 'message' => string].
function set_enabled(bool $enabled, string $by): array
{
    $file = htaccess_file();
    $current = is_file($file) ? (string) @file_get_contents($file) : '';
    if (is_file($file) && !is_writable($file)) return ['ok' => false, 'message' => 'Le fichier .htaccess de la racine web n\'est pas modifiable par le site.'];
    if (!is_file($file) && !is_writable(dirname($file))) return ['ok' => false, 'message' => 'La racine web n\'est pas modifiable par le site.'];
    $clean = without_block($current);
    $new = $enabled ? $clean : block() . "\n" . $clean;
    if ($new === $current) return ['ok' => true, 'message' => $enabled ? 'Les modèles Xplore sont déjà accessibles.' : 'Les modèles Xplore sont déjà inaccessibles.'];

    if ($current !== '') @copy($file, $file . '.bak-xplore');
    if (!write_atomic($file, $new)) return ['ok' => false, 'message' => 'Écriture du .htaccess impossible.'];
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);

    // Contrôle : le site doit répondre ; sinon l'ancien .htaccess est rétabli aussitôt.
    if (Updater\health_check() === 'bad') {
        if ($current !== '') write_atomic($file, $current); else @unlink($file);
        return ['ok' => false, 'message' => 'Le site ne répond plus correctement avec cette règle : l\'ancien .htaccess a été rétabli.'];
    }
    if ($enabled) @unlink(state_file());
    else {
        Config\ensure_private_dir(dirname(state_file()));
        file_put_contents(state_file(), json_encode(['since' => Measurements\now_iso(), 'by' => $by], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    Updater\journal(['action' => 'xplore', 'by' => $by, 'enabled' => $enabled, 'ok' => true]);

    // Effet constaté (sans bloquer) : un hébergement qui ignore les .htaccess laisserait les fichiers accessibles.
    $status = public_status('/xplore.html');
    $effect = $status === null ? ' (effet non vérifiable depuis le serveur : testez l\'adresse /xplore)'
        : ($enabled ? ($status === 404 ? ' Attention : /xplore.html répond toujours 404, vérifiez le .htaccess.' : '')
            : ($status === 404 ? '' : ' Attention : /xplore.html répond encore ' . $status . ' (règles .htaccess ignorées par l\'hébergement ?).'));
    return ['ok' => true, 'message' => ($enabled ? 'Modèles Xplore rendus accessibles.' : 'Modèles Xplore rendus inaccessibles (page et fichiers XML : 404).') . $effect];
}
