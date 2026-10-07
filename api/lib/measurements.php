<?php
// Journalisation des mesures (RGPD): mêmes fichiers logs/measurements-AAAA-MM.jsonl que la
// version Node (un fichier par mois, rotation en .1 au-delà de LOGS_MAX_BYTES, purge par
// ancienneté, migration de l'ancien measurements.jsonl). Les fichiers existants restent donc
// lisibles tels quels après bascule.
declare(strict_types=1);

namespace Radiv\Measurements;

use Radiv\Calculation;
use Radiv\Config;
use Radiv\Json;

const LOGGED_INPUT_FIELDS = [
    'calculation_mode', 'isotope_code', 'dose_rate', 'patient_size_cm',
    'user_period_days', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit',
    'benign_activity_mbq', 'benign_fixation_pct', 'cure_count',
];

function now_iso(): string
{
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
}

// Ne journalise que les champs attendus: le payload brut est contrôlé par l'appelant et
// gonflerait le fichier autant qu'il le souhaite. Retourne un objet (jamais une liste) pour
// qu'un input vide reste {} dans le JSON.
function sanitize_input(mixed $payload): object
{
    $safe = [];
    if (!is_array($payload)) return (object) $safe;
    foreach (LOGGED_INPUT_FIELDS as $field) {
        $value = $payload[$field] ?? null;
        if ($value === null) continue;
        if (is_int($value) || is_float($value) || is_bool($value)) {
            $safe[$field] = $value;
        } elseif (is_string($value)) {
            $safe[$field] = mb_substr($value, 0, 100);
        } else {
            $safe[$field] = '[object Object]'; // String(objet) côté JS; tableau/objet: pas d'usage réel
        }
    }
    return (object) $safe;
}

function log_file_for_period(int $year, int $month): string
{
    return sprintf('%s/measurements-%d-%02d.jsonl', Config\logs_dir(), $year, $month);
}

// [['year'=>int,'month'=>int], ...] du plus récent au plus ancien.
function list_periods(): array
{
    $dir = Config\logs_dir();
    if (!is_dir($dir)) return [];
    $periods = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (preg_match('/^measurements-(\d{4})-(\d{2})\.jsonl$/', $name, $m)) {
            $periods[] = ['year' => (int) $m[1], 'month' => (int) $m[2]];
        }
    }
    usort($periods, static fn ($a, $b) => ($b['year'] <=> $a['year']) ?: ($b['month'] <=> $a['month']));
    return $periods;
}

function purge_old_logs(): void
{
    $retention = Config\logs_retention_months();
    $dir = Config\logs_dir();
    if (!$retention || !is_dir($dir)) return;
    // Mois calendaire courant ramené au 1er: l'ancienneté de chaque fichier mensuel se calcule
    // indépendamment du jour du mois où la purge tourne.
    $cutoff = (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC')))
        ->modify("-{$retention} months");
    foreach (scandir($dir) ?: [] as $name) {
        if (!preg_match('/^measurements-(\d{4})-(\d{2})\.jsonl(\.1)?$/', $name, $m)) continue;
        $fileDate = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $m[1], $m[2]), new \DateTimeZone('UTC'));
        if ($fileDate < $cutoff) {
            if (@unlink("{$dir}/{$name}")) {
                error_log("Purge log mesures (rétention {$retention} mois): {$name}");
            } else {
                error_log("Erreur purge log {$name}");
            }
        }
    }
}

// Node purgeait au démarrage puis une fois par jour; sans process persistant, on le fait au
// plus une fois par 24 h, déclenché par une requête (marqueur = mtime de logs/.last-purge).
function maintenance(): void
{
    $dir = Config\logs_dir();
    if (!Config\ensure_private_dir($dir)) return;
    migrate_legacy_file();
    $marker = "{$dir}/.last-purge";
    if (is_file($marker) && time() - (int) filemtime($marker) < 86400) return;
    @touch($marker);
    purge_old_logs();
}

function rotate_if_needed(string $file): void
{
    $max = Config\int_env('LOGS_MAX_BYTES', 50 * 1024 * 1024);
    clearstatcache(true, $file);
    if (is_file($file) && filesize($file) >= $max) {
        if (!@rename($file, "{$file}.1")) error_log('Erreur rotation log mesures');
    }
}

function append(array $entry): void
{
    try {
        $date = new \DateTimeImmutable($entry['timestamp']);
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        $file = log_file_for_period((int) $date->format('Y'), (int) $date->format('n'));
        if (!Config\ensure_private_dir(Config\logs_dir())) throw new \RuntimeException('logs/ inaccessible');
        rotate_if_needed($file);
        if (file_put_contents($file, Json\encode($entry) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('écriture impossible');
        }
    } catch (\Throwable $e) {
        error_log('Erreur log mesures: ' . $e->getMessage());
    }
}

// Migration ponctuelle: avant le découpage mensuel, tout était dans un seul measurements.jsonl.
function migrate_legacy_file(): void
{
    $legacy = Config\logs_dir() . '/measurements.jsonl';
    if (!is_file($legacy)) return;
    try {
        foreach (file($legacy, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line);
            if (!is_object($entry) || empty($entry->timestamp)) continue;
            try { $date = new \DateTimeImmutable((string) $entry->timestamp); } catch (\Throwable) { continue; }
            $date = $date->setTimezone(new \DateTimeZone('UTC'));
            $file = log_file_for_period((int) $date->format('Y'), (int) $date->format('n'));
            file_put_contents($file, Json\encode($entry) . "\n", FILE_APPEND | LOCK_EX);
        }
        rename($legacy, "{$legacy}.migrated");
        error_log('[MIGRATION] logs/measurements.jsonl scindé par mois, archivé en .migrated.');
    } catch (\Throwable $e) {
        error_log('[MIGRATION] Échec migration logs legacy: ' . $e->getMessage());
    }
}

// Lecture en flux (une ligne à la fois): ne charge jamais tout le fichier en mémoire.
// Les lignes sont décodées en objets (pas en tableaux) pour que {} reste {} à la ré-émission.
function read_file(string $file): array
{
    $rows = [];
    $handle = @fopen($file, 'r');
    if (!$handle) return $rows;
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line);
        if (is_object($row)) $rows[] = $row; // ligne corrompue ignorée
    }
    fclose($handle);
    return $rows;
}

function read_logs(?string $year, ?string $month): array
{
    $has = static fn (?string $v) => $v !== null && $v !== ''; // test de vérité JS: "0" est vrai
    if ($has($year) && $has($month)) {
        $files = [log_file_for_period((int) $year, (int) $month)];
    } elseif ($has($year)) {
        $files = array_map(
            static fn ($p) => log_file_for_period($p['year'], $p['month']),
            array_values(array_filter(list_periods(), static fn ($p) => $p['year'] === (int) $year))
        );
    } else {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $files = [log_file_for_period((int) $now->format('Y'), (int) $now->format('n'))];
    }
    $rows = [];
    foreach ($files as $file) {
        array_push($rows, ...read_file($file));
        if (is_file("{$file}.1")) array_push($rows, ...read_file("{$file}.1"));
    }
    $ts = static function ($row): int {
        try { return (int) (new \DateTimeImmutable((string) ($row->timestamp ?? '')))->format('Uv'); } catch (\Throwable) { return 0; }
    };
    usort($rows, static fn ($a, $b) => $ts($b) <=> $ts($a));
    return $rows;
}

// String(value ?? '') côté JS, pour les valeurs issues d'un JSON.
function js_str(mixed $value): string
{
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_int($value)) return (string) $value;
    if (is_float($value)) return Calculation\js_number_to_string($value);
    return is_string($value) ? $value : Json\encode($value);
}

function csv_escape(mixed $value): string
{
    $text = js_str($value);
    // Neutralise l'injection de formules: Excel/LibreOffice interprètent = + - @ et les
    // caractères de contrôle en tête de cellule comme le début d'une formule.
    if (preg_match('/^[=+\-@\t\r]/', $text)) $text = "'" . $text;
    if (preg_match('/[",\r\n]/', $text)) return '"' . str_replace('"', '""', $text) . '"';
    return $text;
}

function to_csv(array $rows): string
{
    $headers = ['timestamp', 'ip', 'ip_geo', 'isotope_code', 'dose_rate', 'patient_size_cm', 'effective_days', 'ok', 'errors', 'rows'];
    $lines = [implode(',', $headers)];
    foreach ($rows as $row) {
        $result = $row->result ?? null;
        $input = $row->input ?? null;
        $errors = is_object($result) && isset($result->errors) && is_array($result->errors) ? $result->errors : [];
        $cells = [
            $row->timestamp ?? null,
            $row->ip ?? null,
            Json\encode($row->ip_geo ?? null),
            is_object($input) ? ($input->isotope_code ?? null) : null,
            is_object($input) ? ($input->dose_rate ?? null) : null,
            is_object($input) ? ($input->patient_size_cm ?? null) : null,
            is_object($result) ? ($result->effective_days ?? null) : null,
            is_object($result) ? ($result->ok ?? null) : null,
            implode(' | ', array_map('Radiv\Measurements\js_str', $errors)),
            Json\encode(is_object($result) ? ($result->rows ?? []) : []),
        ];
        $lines[] = implode(',', array_map('Radiv\Measurements\csv_escape', $cells));
    }
    return implode("\n", $lines);
}
