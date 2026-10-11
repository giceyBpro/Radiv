<?php
// Surveillance de l'espace disque : sur un petit hébergement (quota fixe, ex. 100 Mo), des journaux qui grossissent finiraient par
// remplir le quota et casser le site. Quand l'espace LIBRE passe sous DISK_MIN_FREE_MB (30 Mo par défaut), les journaux de mesures
// les plus anciens sont supprimés, un par un, jusqu'à retrouver ce minimum plus 10 %. Le mois en cours n'est supprimé qu'en dernier
// recours (son fichier de rotation « .1 » seulement). Sans cron : le contrôle est déclenché par les requêtes (au plus toutes les 10 minutes).
declare(strict_types=1);

namespace Radiv\Disk;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;

const CHECK_INTERVAL_SECONDS = 600;
const SCAN_FILE_LIMIT = 50000;
const HYSTERESIS_MIN_BYTES = 1048576; // marge au-dessus du minimum : 10 % du minimum, 1 Mo au moins

// Taille cumulée des fichiers d'un dossier (liens symboliques ignorés), plafonnée en nombre de fichiers pour rester rapide.
function dir_size(string $dir, int &$count): int
{
    $total = 0;
    try {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $file) {
            if (++$count > SCAN_FILE_LIMIT) break;
            if ($file->isLink() || !$file->isFile()) continue;
            $total += (int) $file->getSize();
        }
    } catch (\Throwable $e) {
        // dossier illisible : compté pour ce qu'on a pu lire
    }
    return $total;
}

// Dossiers à mesurer : la racine web, et le backend et les données quand ils sont ailleurs (sans double compte).
function roots(): array
{
    $roots = [rtrim(Config\web_root(), '/')];
    foreach ([Config\backend_dir(), Config\data_dir()] as $dir) {
        $dir = rtrim($dir, '/');
        $inside = false;
        foreach ($roots as $r) {
            if ($dir === $r || str_starts_with($dir . '/', $r . '/')) { $inside = true; break; }
        }
        if (!$inside) $roots[] = $dir;
    }
    return $roots;
}

// ['used' => octets, 'limit' => octets, 'free' => octets, 'pct' => float, 'source' => 'quota'|'serveur']
// Avec DISK_QUOTA_MB : taille des fichiers du site rapportée à ce quota (le bon réglage sur un hébergement mutualisé, où
// disk_free_space() décrit tout le serveur). Sans lui : occupation du disque qui porte les données.
function usage(): array
{
    $quota = Config\disk_quota_bytes();
    if ($quota !== null) {
        $count = 0;
        $used = 0;
        foreach (roots() as $r) $used += dir_size($r, $count);
        return ['used' => $used, 'limit' => $quota, 'free' => max(0, $quota - $used), 'pct' => $quota > 0 ? 100.0 * $used / $quota : 0.0, 'source' => 'quota'];
    }
    $dir = Config\data_dir();
    while (!is_dir($dir) && dirname($dir) !== $dir) $dir = dirname($dir);
    $total = @disk_total_space($dir);
    $free = @disk_free_space($dir);
    if ($total === false || $free === false || $total <= 0) return ['used' => 0, 'limit' => 0, 'free' => 0, 'pct' => 0.0, 'source' => 'serveur'];
    return ['used' => (int) ($total - $free), 'limit' => (int) $total, 'free' => (int) $free, 'pct' => 100.0 * ($total - $free) / $total, 'source' => 'serveur'];
}

// Journaux de mesures supprimables, du plus ancien au plus récent : les mois passés (fichier et rotation), puis la rotation du mois en cours.
function candidates(): array
{
    $dir = Config\logs_dir();
    $current = gmdate('Y-m');
    $past = [];
    $currentRotation = [];
    foreach (glob($dir . '/measurements-*.jsonl*') ?: [] as $file) {
        if (!preg_match('/measurements-(\d{4}-\d{2})\.jsonl(\.1)?$/', $file, $m)) continue;
        if ($m[1] === $current) {
            if (!empty($m[2])) $currentRotation[] = $file;
        } elseif ($m[1] < $current) {
            $past[] = [$m[1], !empty($m[2]) ? 0 : 1, $file];
        }
    }
    usort($past, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]); // mois croissant, rotation avant fichier principal
    return array_merge(array_map(static fn ($p) => $p[2], $past), $currentRotation);
}

function journal(array $entry): void
{
    $dir = Config\logs_dir();
    if (!Config\ensure_private_dir($dir)) return;
    @file_put_contents($dir . '/updates.jsonl', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

// Contrôle et purge. $force ignore l'intervalle de 10 minutes (essais). Retourne la liste des fichiers supprimés.
function guard(bool $force = false): array
{
    $minFree = Config\disk_min_free_bytes();
    if ($minFree === null) return []; // désactivé
    $dir = Config\logs_dir();
    if (!Config\ensure_private_dir($dir)) return [];
    $marker = $dir . '/.last-disk-check';
    if (!$force && is_file($marker) && time() - (int) filemtime($marker) < CHECK_INTERVAL_SECONDS) return [];
    @touch($marker);
    $u = usage();
    if ($u['limit'] <= 0 || $u['free'] >= $minFree) return [];
    $target = $minFree + max(HYSTERESIS_MIN_BYTES, intdiv($minFree, 10));
    $deleted = [];
    $free = $u['free'];
    foreach (candidates() as $file) {
        if ($free >= $target) break;
        $size = (int) @filesize($file);
        if (@unlink($file)) {
            $free += $size;
            $deleted[] = basename($file);
        }
    }
    $mb = static fn (int $b): float => round($b / 1048576, 1);
    $entry = ['at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'action' => 'disk_purge', 'by' => 'automatique', 'from_free_mb' => $mb($u['free']), 'to_free_mb' => $mb($free), 'min_free_mb' => $mb($minFree), 'deleted' => $deleted, 'ok' => $free >= $minFree];
    journal($entry);
    error_log('[DISK] ' . $mb($u['free']) . ' Mo libres (minimum ' . $mb($minFree) . ') : ' . ($deleted ? implode(', ', $deleted) . ' supprimé(s), ' . $mb($free) . ' Mo libres ensuite' : 'rien à supprimer') . ($free < $minFree ? ' — espace toujours insuffisant' : ''));
    return $deleted;
}
