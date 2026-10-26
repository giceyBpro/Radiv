<?php
// Zone de test du moteur de calcul partagé (core/) : télécharger une version candidate (typiquement une nouvelle
// majeure), la brancher à côté du moteur en service SANS rien changer au site, lui faire passer une batterie de tests
// automatisés, produire un compte rendu lisible, puis — si l'administrateur le décide — l'appliquer au site (avec
// retour arrière possible).
//
// Principe : le candidat est copié dans var/core-lab/candidate/, puis recopié dans var/core-lab/lab/ avec son espace de
// noms renommé (RivEngine → RivEngineLab) pour cohabiter avec le moteur en service dans le même processus. Aucun
// fichier du site n'est touché avant « appliquer ». Le candidat est du code : une analyse statique refuse de
// l'exécuter s'il appelle des fonctions système, réseau ou d'écriture (un moteur de calcul n'en a pas besoin).
declare(strict_types=1);

namespace Radiv\CoreLab;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Http;
use Radiv\Json;
use Radiv\Measurements;
use Radiv\Updater;

const MAX_FILE_BYTES = 2097152;
const MAX_TOTAL_BYTES = 8388608;

// Fonctions du moteur que le site appelle : le candidat doit toutes les fournir (sinon il casserait le site).
const REQUIRED_FUNCTIONS = [
    'calculate', 'isotopes', 'scenarios', 'cure_options_by_isotope', 'get_isotope', 'normalize_cure_count',
    'to_number_or_null', 'js_number_to_string', 'js_json_stringify', 'set_rounding_mode',
];

// Appels interdits dans le code d'un moteur de calcul (système, réseau, écriture, exécution dynamique).
const FORBIDDEN_CALLS = [
    'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'eval', 'assert', 'create_function',
    'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'socket_create',
    'mail', 'file_put_contents', 'fwrite', 'fputs', 'fopen', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'chown', 'touch', 'tempnam', 'symlink', 'link',
    'unserialize', 'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13', 'phpinfo', 'putenv', 'ini_set', 'ini_alter', 'set_include_path',
    'header', 'setcookie', 'session_start', 'move_uploaded_file', 'dl', 'ob_start', 'register_shutdown_function', 'call_user_func', 'call_user_func_array',
    'file', 'readfile', 'fpassthru', 'glob', 'scandir', 'opendir', 'readdir', 'highlight_file', 'show_source', 'parse_ini_file', 'simplexml_load_file',
];

// --- Emplacements ---------------------------------------------------------------------------

function lab_dir(): string { return Config\var_dir() . '/core-lab'; }
function candidate_dir(): string { return lab_dir() . '/candidate'; }
function report_file(): string { return lab_dir() . '/report.json'; }
function previous_dir(): string { return lab_dir() . '/previous'; }
function pin_file(): string { return Config\var_dir() . '/core-pin.json'; }

// Moteur en service : backend/core/ une fois installé, core/ à la racine du dépôt en développement.
function active_dir(): string
{
    $installed = dirname(__DIR__, 2) . '/core';
    return is_file($installed . '/src/bootstrap.php') ? $installed : dirname(__DIR__, 3) . '/core';
}

// true si le moteur en service est la copie installée (la seule que « appliquer » peut remplacer).
function active_is_installed(): bool { return active_dir() === dirname(__DIR__, 2) . '/core'; }

function read_json(string $file): ?array
{
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function write_json(string $file, array $data): void
{
    Config\ensure_private_dir(dirname($file));
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
}

// Épinglage : tant qu'il existe, les mises à jour du site (/auth et update.php) ne touchent pas à core/.
function pin(): ?array { return read_json(pin_file()); }

function engine_version(string $dir): string
{
    $v = trim((string) @file_get_contents($dir . '/VERSION'));
    return preg_match('/^\d+\.\d+\.\d+$/', $v) === 1 ? $v : '0.0.0';
}

// Empreinte du contenu d'un moteur (src, data, VERSION) : identifie sans ambiguïté la version testée.
function engine_hash(string $dir): string
{
    $parts = [];
    foreach (engine_files($dir) as $rel) $parts[] = $rel . ':' . hash_file('sha256', $dir . '/' . $rel);
    return hash('sha256', implode("\n", $parts));
}

function engine_files(string $dir): array
{
    $files = [];
    foreach (['src' => '/^[A-Za-z0-9_-]+\.php$/', 'data' => '/^[A-Za-z0-9_-]+\.json$/'] as $sub => $pattern) {
        foreach (is_dir("{$dir}/{$sub}") ? (scandir("{$dir}/{$sub}") ?: []) : [] as $name) {
            if (preg_match($pattern, $name) === 1 && is_file("{$dir}/{$sub}/{$name}")) $files[] = "{$sub}/{$name}";
        }
    }
    if (is_file($dir . '/VERSION')) $files[] = 'VERSION';
    sort($files);
    return $files;
}

// --- Téléchargement du candidat ------------------------------------------------------------

// Fichiers conservés d'une archive (chemin relatif au dossier du moteur ; les mêmes que la mise à jour du site installe) ; null pour tout le reste.
function candidate_path(string $rel): ?string
{
    if ($rel === 'VERSION') return $rel;
    if (preg_match('#^src/[A-Za-z0-9_-]+\.php$#', $rel) === 1) return $rel;
    if (preg_match('#^data/[A-Za-z0-9_-]+\.json$#', $rel) === 1) return $rel;
    return null;
}

// Télécharge $repo@$ref (dépôt « propriétaire/nom » ; le moteur est dans core/ ou à la racine) vers candidate/.
function fetch_candidate(string $repo, string $ref, string $token, string $by): array
{
    @set_time_limit(120);
    if (!class_exists('ZipArchive')) return ['ok' => false, 'message' => "L'extension PHP zip n'est pas disponible."];
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) return ['ok' => false, 'message' => 'Dépôt invalide (attendu : propriétaire/nom).'];
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $ref) || str_contains($ref, '..')) return ['ok' => false, 'message' => 'Référence invalide.'];
    $lock = Updater\acquire_lock();
    if (!$lock) return ['ok' => false, 'message' => 'Une opération est déjà en cours.'];
    $tmp = Updater\state_dir() . '/tmp/corelab-' . bin2hex(random_bytes(4));
    try {
        $encoded = str_replace('%2F', '/', rawurlencode($ref));
        $shaResp = Http\fetch(Updater\api_base() . "/repos/{$repo}/commits/{$encoded}", ['headers' => Updater\gh_headers($token, 'application/vnd.github.sha'), 'timeout_ms' => 15000]);
        $sha = $shaResp && $shaResp['status'] === 200 ? strtolower(trim($shaResp['body'])) : '';
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new \RuntimeException('Référence introuvable ou accès refusé.');
        Config\ensure_dir($tmp);
        $zipFile = "{$tmp}/repo.zip";
        $redirect = Http\fetch(Updater\api_base() . "/repos/{$repo}/zipball/{$sha}", ['headers' => Updater\gh_headers($token), 'follow' => false, 'timeout_ms' => 15000]);
        $target = $redirect['location'] ?? null;
        if (!$redirect || $redirect['status'] !== 302 || !is_string($target) || !preg_match('#^https?://#i', $target)) throw new \RuntimeException('Téléchargement impossible.');
        $download = Http\download($target, $zipFile, ['User-Agent: radiv-site-updater'], Updater\MAX_ZIP_BYTES);
        if (!$download || $download['status'] !== 200 || @file_get_contents($zipFile, false, null, 0, 2) !== 'PK') throw new \RuntimeException('Téléchargement impossible.');

        $staging = "{$tmp}/new";
        $files = extract_candidate($zipFile, $staging, $sha);
        if (!in_array('src/bootstrap.php', $files, true) || !in_array('src/calculate.php', $files, true) || !in_array('data/isotopes.json', $files, true)) {
            throw new \RuntimeException('Cette version ne contient pas de moteur de calcul reconnaissable (src/bootstrap.php, src/calculate.php, data/isotopes.json).');
        }
        Updater\lint_php($staging, $files);
        Updater\rrmdir(candidate_dir());
        Updater\rrmdir(lab_dir() . '/lab');
        Config\ensure_private_dir(lab_dir());
        if (!@rename($staging, candidate_dir())) throw new \RuntimeException('Installation du candidat impossible.');
        $meta = [
            'repo' => $repo, 'ref' => $ref, 'sha' => $sha, 'version' => engine_version(candidate_dir()), 'hash' => engine_hash(candidate_dir()),
            'files' => $files, 'fetched_at' => Measurements\now_iso(), 'by' => $by,
        ];
        write_json(candidate_dir() . '/meta.json', $meta);
        @unlink(report_file()); // l'ancien compte rendu concernait un autre candidat
        Updater\journal(['action' => 'core-fetch', 'by' => $by, 'ref' => "{$repo}@{$ref}", 'to' => $sha, 'ok' => true]);
        return ['ok' => true, 'message' => 'Candidat téléchargé : moteur ' . $meta['version'] . ' (' . substr($sha, 0, 7) . '). Lancez maintenant les tests.'];
    } catch (\Throwable $e) {
        $message = $e instanceof \RuntimeException ? $e->getMessage() : 'Erreur interne pendant le téléchargement.';
        if (!$e instanceof \RuntimeException) error_log('[COREFETCH] ' . get_class($e) . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => $message];
    } finally {
        Updater\rrmdir($tmp);
        @rmdir(Updater\state_dir() . '/tmp');
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Extrait le moteur d'une archive GitHub : dossier core/ s'il existe, sinon racine du dépôt. Mêmes protections que la
// mise à jour du site (chemins, liens symboliques, tailles, correspondance avec le commit).
function extract_candidate(string $zipFile, string $staging, string $sha): array
{
    $zip = new \ZipArchive();
    if ($zip->open($zipFile) !== true) throw new \RuntimeException('Archive illisible.');
    if ($zip->numFiles > 5000) throw new \RuntimeException('Archive refusée (trop de fichiers).');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[$i] = (string) $zip->getNameIndex($i);
    $root = explode('/', $names[0] ?? '', 2)[0];
    if ($root === '' || !str_contains($root, substr($sha, 0, 7))) throw new \RuntimeException('Archive ne correspondant pas au commit demandé.');
    $prefix = in_array($root . '/core/src/bootstrap.php', $names, true) ? 'core/' : '';
    $files = [];
    $total = 0;
    foreach ($names as $i => $name) {
        $segments = explode('/', $name, 2);
        if ($segments[0] !== $root) throw new \RuntimeException('Archive invalide (plusieurs dossiers racines).');
        if (str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
            throw new \RuntimeException('Archive refusée (chemin invalide).');
        }
        if (str_ends_with($name, '/') || !isset($segments[1])) continue;
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
            throw new \RuntimeException('Archive refusée (lien symbolique).');
        }
        $rel = $segments[1];
        if ($prefix !== '') {
            if (!str_starts_with($rel, $prefix)) continue;
            $rel = substr($rel, strlen($prefix));
        }
        if (candidate_path($rel) === null) continue;
        $size = (int) (($zip->statIndex($i)['size'] ?? -1));
        $total += $size;
        if ($size < 0 || $size > MAX_FILE_BYTES || $total > MAX_TOTAL_BYTES) throw new \RuntimeException('Archive refusée (taille).');
        $dest = "{$staging}/{$rel}";
        Config\ensure_dir(dirname($dest));
        $content = $zip->getFromIndex($i);
        if ($content === false || file_put_contents($dest, $content) !== $size) throw new \RuntimeException('Extraction impossible.');
        $files[] = $rel;
    }
    $zip->close();
    sort($files);
    return $files;
}

// --- Chargement du candidat à côté du moteur en service -------------------------------------

// Analyse statique : liste des appels interdits trouvés dans les fichiers PHP du candidat.
function forbidden_calls(string $dir): array
{
    $found = [];
    foreach (engine_files($dir) as $rel) {
        if (!str_ends_with($rel, '.php')) continue;
        $tokens = token_get_all((string) file_get_contents("{$dir}/{$rel}"));
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '`') { $found[] = "{$rel} : exécution par accent grave"; continue; }
            if (!is_array($t)) continue;
            if (in_array($t[0], [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true) && $rel !== 'src/bootstrap.php') {
                $found[] = "{$rel} ligne {$t[2]} : " . strtolower(token_name($t[0]) === 'T_EVAL' ? 'eval' : 'inclusion de fichier');
            }
            if ($t[0] === T_STRING && in_array(strtolower($t[1]), FORBIDDEN_CALLS, true)) {
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) $prev--;
                $isMember = $prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
                if (($tokens[$j] ?? null) === '(' && !$isMember) $found[] = "{$rel} ligne {$t[2]} : appel de {$t[1]}()";
            }
        }
    }
    return $found;
}

// Recopie le candidat dans lab/ avec l'espace de noms RivEngineLab, puis le charge. Retourne null si tout va bien,
// sinon le message d'erreur.
function load_candidate(): ?string
{
    if (function_exists('RivEngineLab\\calculate')) return null; // déjà chargé dans ce processus
    $src = candidate_dir();
    $lab = lab_dir() . '/lab';
    Updater\rrmdir($lab);
    Config\ensure_private_dir($lab);
    $bootstrap = (string) @file_get_contents($src . '/src/bootstrap.php');
    if (!preg_match_all("#require(?:_once)?\\s+__DIR__\\s*\\.\\s*'/([A-Za-z0-9_-]+\\.php)'\\s*;#", $bootstrap, $m) || !$m[1]) {
        return "bootstrap.php ne liste aucun fichier à charger (lignes « require __DIR__ . '/x.php'; » attendues).";
    }
    foreach ($m[1] as $name) {
        $code = (string) @file_get_contents("{$src}/src/{$name}");
        if ($code === '') return "Fichier du moteur manquant ou vide : src/{$name}.";
        $code = preg_replace('/\bRivEngine\b/', 'RivEngineLab', $code);
        $code = str_replace(['riv_engine_rounding', '__DIR__'], ['riv_engine_rounding_lab', var_export($src . '/src', true)], $code);
        file_put_contents("{$lab}/{$name}", $code);
    }
    try {
        foreach ($m[1] as $name) require "{$lab}/{$name}";
    } catch (\Throwable $e) {
        return 'Chargement impossible : ' . $e->getMessage();
    }
    return null;
}

// --- Batterie de cas ------------------------------------------------------------------------

// Cas déterministes couvrant chaque isotope, plusieurs débits et tailles, les cures, l'iode bénin, le scénario
// personnalisé et des entrées invalides. Toujours les mêmes d'une exécution à l'autre.
function build_cases(): array
{
    $cases = [];
    $isotopes = \RivEngine\isotopes();
    $cures = \RivEngine\cure_options_by_isotope();
    foreach ($isotopes as $iso) {
        $code = $iso['api_code'];
        foreach ([2, 20, 100, 400] as $rate) {
            foreach ([150, 175] as $size) {
                $base = ['isotope_code' => $code, 'patient_size_cm' => $size];
                if ($code === 'iode131_benin') {
                    foreach ([[370, 5], [1110, 25]] as [$act, $fix]) $cases[] = $base + ['benign_activity_mbq' => $act * $rate / 100, 'benign_fixation_pct' => $fix];
                    continue;
                }
                if ($code === 'non_defini') $base['user_period_days'] = 1.5 + $rate / 100;
                $cases[] = $base + ['dose_rate' => $rate];
                foreach ($cures[$code] ?? [] as $n) if ($n > 1) $cases[] = $base + ['dose_rate' => $rate, 'cure_count' => $n];
            }
        }
    }
    foreach ([['radium223', 100], ['psma_177lu', 30], ['mibg_131i', 60]] as [$code, $rate]) {
        $cases[] = ['isotope_code' => $code, 'dose_rate' => $rate, 'patient_size_cm' => 165, 'user_hours_1' => 6, 'user_distance_1' => 0.5, 'user_hours_2' => 4, 'user_limit' => 2];
        $cases[] = ['isotope_code' => $code, 'dose_rate' => $rate, 'patient_size_cm' => 165, 'user_period_days' => 3.5];
    }
    // Entrées invalides ou inhabituelles : le comportement d'erreur fait partie du contrat.
    $valid = ['isotope_code' => 'radium223', 'dose_rate' => 100, 'patient_size_cm' => 150];
    $cases[] = [];
    $cases[] = ['isotope_code' => 'inconnu'] + $valid;
    $cases[] = ['isotope_code' => null] + $valid;
    $cases[] = ['dose_rate' => 0] + $valid;
    $cases[] = ['dose_rate' => -5] + $valid;
    $cases[] = ['dose_rate' => 'abc'] + $valid;
    $cases[] = ['dose_rate' => '100'] + $valid;
    $cases[] = ['dose_rate' => ' 1e2 '] + $valid;
    $cases[] = ['dose_rate' => true] + $valid;
    $cases[] = ['patient_size_cm' => 0] + $valid;
    $cases[] = ['patient_size_cm' => null] + $valid;
    $cases[] = ['cure_count' => 3] + $valid;
    $cases[] = ['cure_count' => 'x', 'isotope_code' => 'psma_177lu', 'dose_rate' => 30, 'patient_size_cm' => 170];
    $cases[] = ['user_hours_1' => 1] + $valid;
    $cases[] = ['user_hours_1' => 1, 'user_distance_1' => 0, 'user_hours_2' => 1, 'user_limit' => 1] + $valid;
    $cases[] = ['isotope_code' => 'iode131_benin', 'patient_size_cm' => 170];
    $cases[] = ['isotope_code' => 'iode131_benin', 'benign_activity_mbq' => 100, 'benign_fixation_pct' => 150, 'patient_size_cm' => 170];
    $cases[] = ['isotope_code' => 'non_defini', 'dose_rate' => 50, 'patient_size_cm' => 170];
    $cases[] = ['dose_rate' => 1e308] + $valid;
    $cases[] = ['dose_rate' => 1e-300] + $valid;
    $cases[] = ['patient_size_cm' => 1e308] + $valid;
    $cases[] = ['user_period_days' => 1e308, 'isotope_code' => 'non_defini', 'dose_rate' => 100, 'patient_size_cm' => 150];
    return $cases;
}

// Exécute le moteur $ns ('RivEngine' ou 'RivEngineLab') sur les cas, dans le mode d'arrondi donné. Retourne
// ['results' => [...], 'warnings' => [...], 'exceptions' => [...], 'ms' => float].
function run_cases(string $ns, array $cases, string $mode): array
{
    // Le moteur en service peut porter une surcharge du site (variable d'environnement) : elle est restaurée après les essais.
    $key = $ns === 'RivEngine' ? 'riv_engine_rounding' : 'riv_engine_rounding_lab';
    $saved = $GLOBALS[$key] ?? null;
    ($ns . '\\set_rounding_mode')($mode);
    $results = [];
    $warnings = [];
    $exceptions = [];
    $previous = set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$warnings): bool {
        $warnings[] = "{$msg} (ligne {$line})";
        return true;
    });
    $start = microtime(true);
    foreach ($cases as $i => $case) {
        try {
            $results[$i] = ($ns . '\\calculate')($case);
        } catch (\Throwable $e) {
            $results[$i] = null;
            $exceptions[] = 'cas #' . $i . ' : ' . get_class($e) . ' — ' . $e->getMessage();
        }
    }
    $ms = (microtime(true) - $start) * 1000;
    restore_error_handler();
    ($ns . '\\set_rounding_mode')($saved);
    return ['results' => $results, 'warnings' => array_values(array_unique($warnings)), 'exceptions' => $exceptions, 'ms' => $ms];
}

// Résultat normalisé pour la comparaison : NaN/Infini → null, comme l'encodage JSON de l'API.
function canonical(mixed $value): string { return Json\encode($value); }

// Signature de structure : clés et types, sans les valeurs.
function shape(mixed $v): mixed
{
    if (is_array($v)) {
        if ($v === [] || array_keys($v) === range(0, count($v) - 1)) return $v ? [shape($v[0])] : [];
        return array_map(static fn ($x) => shape($x), $v);
    }
    return $v === null ? 'null' : get_debug_type($v);
}

function flatten_shape(mixed $shape, string $path = ''): array
{
    if (!is_array($shape)) return [$path => (string) $shape];
    $out = [];
    foreach ($shape as $k => $v) $out += flatten_shape($v, $path . '/' . $k);
    return $out ?: [$path => 'vide'];
}

// --- Les tests ------------------------------------------------------------------------------

// Un test : statut 'ok' | 'info' (changement attendu d'une évolution) | 'warn' | 'fail' (bloquant).
function t(string $id, string $title, string $what, string $status, string $result, array $details = []): array
{
    return ['id' => $id, 'title' => $title, 'what' => $what, 'status' => $status, 'result' => $result, 'details' => array_slice($details, 0, 40), 'more' => max(0, count($details) - 40)];
}

function run_tests(string $by): array
{
    @set_time_limit(180);
    $meta = read_json(candidate_dir() . '/meta.json');
    if (!$meta) return ['ok' => false, 'message' => 'Aucun candidat téléchargé.'];
    $active = active_dir();
    $cand = candidate_dir();
    $tests = [];
    $started = microtime(true);

    $activeVersion = engine_version($active);
    $candVersion = $meta['version'];
    $activeMajor = (int) explode('.', $activeVersion)[0];
    $candMajor = (int) explode('.', $candVersion)[0];

    // 1. Identité
    $same = engine_hash($active) === $meta['hash'];
    if ($candVersion === '0.0.0') {
        $tests[] = t('identite', 'Identité du candidat', 'Vérifie le fichier VERSION.', 'warn', 'Fichier VERSION absent ou invalide (attendu : 1.2.3).');
    } else {
        $tests[] = t('identite', 'Identité du candidat', 'Compare la version et le contenu du candidat à ceux du moteur en service.', 'ok',
            $same ? 'Le candidat est identique au moteur en service.'
                : "Moteur en service : {$activeVersion} ; candidat : {$candVersion}" . ($candMajor !== $activeMajor ? " — changement de majeure ({$activeMajor} → {$candMajor}) : des écarts de résultats sont possibles." : ($candVersion === $activeVersion ? ' — même numéro de version mais contenu différent : pensez à incrémenter VERSION.' : '.')));
    }

    // 2. Sécurité du code
    $forbidden = forbidden_calls($cand);
    $tests[] = t('securite', 'Analyse statique du code', "Cherche dans le candidat des appels qu'un moteur de calcul n'a pas à faire (système, réseau, écriture de fichiers, exécution dynamique, inclusion de fichiers).",
        $forbidden ? 'fail' : 'ok', $forbidden ? 'Appels interdits trouvés : le candidat n\'est PAS exécuté.' : 'Aucun appel système, réseau ou d\'écriture.', $forbidden);

    // 3. Chargement
    $loadError = $forbidden ? 'Non exécuté (analyse statique en échec).' : load_candidate();
    $tests[] = t('chargement', 'Chargement du moteur', 'Charge le candidat à côté du moteur en service (espace de noms séparé) et vérifie qu\'il démarre.',
        $loadError === null ? 'ok' : 'fail', $loadError ?? 'Le candidat se charge sans erreur.');
    if ($loadError !== null) return finish($meta, $tests, $started, $by, $activeVersion, [], false);

    // 4. Fonctions attendues par le site
    $missing = array_values(array_filter(REQUIRED_FUNCTIONS, static fn ($f) => !function_exists('RivEngineLab\\' . $f)));
    $tests[] = t('fonctions', 'Fonctions utilisées par le site', 'Vérifie que le candidat fournit toutes les fonctions que le site appelle (' . implode(', ', REQUIRED_FUNCTIONS) . ').',
        $missing ? 'fail' : 'ok', $missing ? 'Fonctions manquantes : ' . implode(', ', $missing) . '. Le site cesserait de fonctionner.' : 'Toutes présentes.');
    $hasVersionFn = function_exists('RivEngineLab\\version');
    if (!$hasVersionFn) $tests[] = t('version-fn', 'Fonction version()', 'Le site peut interroger la version du moteur.', 'warn', 'version() absente : la version ne pourra pas être lue.');
    if ($missing) return finish($meta, $tests, $started, $by, $activeVersion, [], false);

    // 5. Catalogue
    $tests = array_merge($tests, catalog_tests());

    // 6. Batterie différentielle (deux modes d'arrondi)
    $cases = build_cases();
    $stats = ['cases' => count($cases), 'runs' => 0];
    $diffs = [];
    $runs = [];
    foreach (['round', 'floor'] as $mode) {
        $a = run_cases('RivEngine', $cases, $mode);
        $b = run_cases('RivEngineLab', $cases, $mode);
        $runs[$mode] = ['a' => $a, 'b' => $b];
        $stats['runs'] += 2 * count($cases);
        foreach ($cases as $i => $case) {
            if (canonical($a['results'][$i]) !== canonical($b['results'][$i])) $diffs[] = ['mode' => $mode, 'i' => $i, 'case' => $case, 'a' => $a['results'][$i], 'b' => $b['results'][$i]];
        }
    }
    $exceptions = array_merge($runs['round']['b']['exceptions'], $runs['floor']['b']['exceptions']);
    $warnings = array_merge($runs['round']['b']['warnings'], $runs['floor']['b']['warnings']);
    $tests[] = t('robustesse', 'Robustesse', 'Exécute le candidat sur ' . count($cases) . ' cas (valides et invalides) dans les deux modes d\'arrondi et surveille exceptions, avertissements et notices PHP.',
        $exceptions ? 'fail' : ($warnings ? 'warn' : 'ok'),
        $exceptions ? count($exceptions) . ' exception(s) : le site afficherait une erreur.' : ($warnings ? count($warnings) . ' avertissement(s) PHP.' : 'Aucune exception ni avertissement.'), array_merge($exceptions, $warnings));

    // 7. Structure des réponses
    $tests[] = shape_test($runs['round']['a']['results'], $runs['round']['b']['results'], $cases);

    // 8. Comparaison des résultats
    $tests[] = diff_test($diffs, count($cases));

    // 9. Cohérence physique du candidat seul
    $tests = array_merge($tests, coherence_tests());

    // 10. Performance
    $msA = $runs['round']['a']['ms'];
    $msB = $runs['round']['b']['ms'];
    $perCall = $msB / max(1, count($cases));
    $tests[] = t('performance', 'Performance', 'Mesure le temps moyen d\'un calcul (candidat comparé au moteur en service).',
        $msB > max(50.0, 5 * $msA) ? 'warn' : 'ok', sprintf('%.3f ms par calcul (moteur en service : %.3f ms).', $perCall, $msA / max(1, count($cases))));

    return finish($meta, $tests, $started, $by, $activeVersion, $stats, true, $diffs);
}

function catalog_tests(): array
{
    $out = [];
    $ia = indexed(\RivEngine\isotopes(), 'api_code');
    $ib = indexed(\RivEngineLab\isotopes(), 'api_code');
    $lines = [];
    foreach (array_diff_key($ia, $ib) as $k => $_) $lines[] = "Isotope RETIRÉ : {$k}";
    foreach (array_diff_key($ib, $ia) as $k => $_) $lines[] = "Isotope ajouté : {$k}";
    foreach (array_intersect_key($ia, $ib) as $k => $row) {
        foreach ($row as $field => $value) {
            if (canonical($value) !== canonical($ib[$k][$field] ?? null)) $lines[] = "{$k} : {$field} passe de " . canonical($value) . ' à ' . canonical($ib[$k][$field] ?? null);
        }
    }
    $removed = (bool) array_diff_key($ia, $ib);
    $out[] = t('isotopes', 'Isotopes et périodes', 'Compare la liste des isotopes, leurs périodes effectives et leurs références.',
        $removed ? 'fail' : ($lines ? 'info' : 'ok'), $removed ? 'Un isotope a disparu : les appelants qui l\'utilisent recevraient une erreur.' : ($lines ? count($lines) . ' différence(s) dans le catalogue.' : 'Catalogue identique.'), $lines);

    $sa = indexed(\RivEngine\scenarios(), 'audience_code');
    $sb = indexed(\RivEngineLab\scenarios(), 'audience_code');
    $lines = [];
    foreach (array_diff_key($sa, $sb) as $k => $_) $lines[] = "Scénario RETIRÉ : {$k}";
    foreach (array_diff_key($sb, $sa) as $k => $_) $lines[] = "Scénario ajouté : {$k}";
    foreach (array_intersect_key($sa, $sb) as $k => $row) {
        foreach (['exposures', 'limit', 'label'] as $field) {
            if (canonical($row[$field] ?? null) !== canonical($sb[$k][$field] ?? null)) $lines[] = "{$k} : {$field} passe de " . canonical($row[$field] ?? null) . ' à ' . canonical($sb[$k][$field] ?? null);
        }
    }
    $removed = (bool) array_diff_key($sa, $sb);
    $out[] = t('scenarios', 'Scénarios d\'exposition', 'Compare les scénarios (durées, distances, limites de dose).',
        $removed ? 'fail' : ($lines ? 'info' : 'ok'), $removed ? 'Un scénario a disparu : une colonne de résultats manquerait.' : ($lines ? count($lines) . ' différence(s) dans les scénarios.' : 'Scénarios identiques.'), $lines);

    $ca = canonical(\RivEngine\cure_options_by_isotope());
    $cb = canonical(\RivEngineLab\cure_options_by_isotope());
    $out[] = t('cures', 'Nombres de cures', 'Compare les nombres de cures autorisés par isotope.', $ca === $cb ? 'ok' : 'info',
        $ca === $cb ? 'Identiques.' : 'Différents.', $ca === $cb ? [] : ["en service : {$ca}", "candidat : {$cb}"]);
    return $out;
}

function indexed(array $rows, string $key): array
{
    $out = [];
    foreach ($rows as $row) $out[(string) $row[$key]] = $row;
    return $out;
}

function shape_test(array $a, array $b, array $cases): array
{
    $problems = [];
    $added = [];
    foreach ($cases as $i => $case) {
        if (!is_array($a[$i] ?? null) || !is_array($b[$i] ?? null)) continue;
        $sa = flatten_shape(shape($a[$i]));
        $sb = flatten_shape(shape($b[$i]));
        foreach ($sa as $path => $type) {
            if (!array_key_exists($path, $sb)) $problems[$path] = "Champ SUPPRIMÉ : {$path}";
            elseif ($sb[$path] !== $type && $type !== 'null' && $sb[$path] !== 'null') $problems[$path] = "Type modifié pour {$path} : {$type} → {$sb[$path]}";
        }
        foreach ($sb as $path => $_) if (!array_key_exists($path, $sa)) $added[$path] = "Champ ajouté : {$path}";
        if (array_keys($a[$i]) !== array_keys($b[$i]) && !$problems) $problems['ordre'] = 'Ordre des clés de premier niveau modifié (sans conséquence pour du JSON, mais visible à l\'œil).';
    }
    $lines = array_merge(array_values($problems), array_values($added));
    $breaking = array_filter($problems, static fn ($p) => str_starts_with($p, 'Champ SUPPRIMÉ') || str_starts_with($p, 'Type modifié'));
    return t('structure', 'Structure des réponses', 'Compare les champs et leurs types dans les réponses (un champ supprimé ou changé de type casserait les appelants ; un champ ajouté est sans risque).',
        $breaking ? 'fail' : ($lines ? 'info' : 'ok'), $breaking ? 'Rupture de compatibilité : ' . count($breaking) . ' champ(s) supprimé(s) ou changé(s) de type.' : ($lines ? 'Évolution compatible : ' . count($lines) . ' différence(s) sans danger.' : 'Structure identique.'), $lines);
}

function diff_test(array $diffs, int $nCases): array
{
    if (!$diffs) return t('resultats', 'Résultats numériques', "Compare, cas par cas et dans les deux modes d'arrondi, les réponses complètes du candidat et du moteur en service.", 'ok',
        "Les {$nCases} cas × 2 modes donnent exactement les mêmes réponses (valeurs, messages, ordre).");
    $perScenario = [];
    $errorChanges = 0;
    $examples = [];
    foreach ($diffs as $d) {
        $ra = is_array($d['a']) ? ($d['a']['rows'] ?? []) : [];
        $rb = is_array($d['b']) ? ($d['b']['rows'] ?? []) : [];
        $valuesDiffer = false;
        foreach ($ra as $k => $row) {
            $va = $row['value'] ?? null;
            $vb = $rb[$k]['value'] ?? null;
            if ($va !== $vb && is_numeric($va) && is_numeric($vb)) {
                $valuesDiffer = true;
                $code = (string) $row['audience_code'];
                $perScenario[$code]['n'] = ($perScenario[$code]['n'] ?? 0) + 1;
                $delta = (float) $vb - (float) $va;
                $perScenario[$code]['max'] = max($perScenario[$code]['max'] ?? 0.0, abs($delta));
                $perScenario[$code]['sum'] = ($perScenario[$code]['sum'] ?? 0.0) + $delta;
            } elseif ($va !== $vb) {
                $valuesDiffer = true;
                $errorChanges++;
            }
        }
        if (!$valuesDiffer) $errorChanges++;
        if (count($examples) < 6) $examples[] = '[' . $d['mode'] . '] ' . canonical($d['case']) . ' → ' . summarize($d['a']) . ' ≠ ' . summarize($d['b']);
    }
    $lines = [];
    foreach ($perScenario as $code => $s) {
        $lines[] = sprintf('%s : %d valeur(s) modifiée(s), écart maximal %s jour(s), écart moyen %+.2f jour(s)', $code, $s['n'], rtrim(rtrim(number_format($s['max'], 2, '.', ''), '0'), '.'), $s['sum'] / $s['n']);
    }
    if ($errorChanges) $lines[] = "{$errorChanges} réponse(s) dont la forme, les erreurs ou les textes changent (hors valeurs en jours).";
    $lines[] = 'Exemples :';
    $lines = array_merge($lines, $examples);
    return t('resultats', 'Résultats numériques', "Compare, cas par cas et dans les deux modes d'arrondi, les réponses complètes du candidat et du moteur en service.", 'info',
        count($diffs) . " réponse(s) sur " . ($nCases * 2) . ' diffèrent. C\'est normal pour une évolution voulue des données ou des formules ; relisez les écarts ci-dessous avant d\'appliquer.', $lines);
}

function summarize(mixed $r): string
{
    if (!is_array($r)) return 'aucune réponse';
    if (!($r['ok'] ?? false)) return 'erreur « ' . ($r['error']['message'] ?? '?') . ' »';
    $vals = [];
    foreach ($r['rows'] ?? [] as $row) if ($row['value'] !== null) $vals[] = $row['value'];
    return '[' . implode(', ', $vals) . ']';
}

// Propriétés physiques que tout moteur correct doit respecter, vérifiées sur le candidat seul.
function coherence_tests(): array
{
    $out = [];
    $calc = static function (array $payload, string $mode = 'round'): array {
        \RivEngineLab\set_rounding_mode($mode);
        $r = \RivEngineLab\calculate($payload);
        \RivEngineLab\set_rounding_mode(null);
        return $r;
    };
    $vals = static fn (array $r): array => array_filter($r['recommendations_days'] ?? [], static fn ($v) => $v !== null);
    $problems = [];

    // Valeurs entières positives ou nulles (mode round)
    $bad = 0;
    foreach (['radium223', 'psma_177lu', 'lutetium177_net', 'microspheres_90y'] as $code) {
        foreach ($vals($calc(['isotope_code' => $code, 'dose_rate' => 80, 'patient_size_cm' => 170])) as $v) if ($v < 0 || floor((float) $v) != $v) $bad++;
    }
    $out[] = t('entiers', 'Durées en jours entiers positifs', 'Chaque durée affichée doit être un nombre entier de jours, jamais négatif.', $bad ? 'fail' : 'ok', $bad ? "{$bad} valeur(s) non entières ou négatives." : 'Toutes les durées sont des entiers ≥ 0.');

    // Monotonie selon le débit de dose
    $viol = [];
    foreach (['radium223', 'psma_177lu', 'iode131_25_fixation'] as $code) {
        $prev = null;
        foreach ([10, 30, 100, 300, 1000] as $rate) {
            $cur = $vals($calc(['isotope_code' => $code, 'dose_rate' => $rate, 'patient_size_cm' => 170]));
            if ($prev !== null) foreach ($cur as $k => $v) if ($v < ($prev[$k] ?? 0)) $viol[] = "{$code} / {$k} : {$prev[$k]} j à débit plus faible, {$v} j à {$rate} µSv/h";
            $prev = $cur;
        }
    }
    $out[] = t('monotonie-debit', 'Plus de débit, plus de restriction', 'Quand le débit de dose mesuré augmente, aucune durée de restriction ne doit diminuer.', $viol ? 'fail' : 'ok', $viol ? count($viol) . ' violation(s).' : 'Durées croissantes avec le débit pour tous les scénarios testés.', $viol);

    // Monotonie selon la limite de dose du scénario personnalisé
    $viol = [];
    $prev = null;
    foreach ([0.5, 1, 3, 15] as $limit) {
        $r = $calc(['isotope_code' => 'radium223', 'dose_rate' => 100, 'patient_size_cm' => 170, 'user_hours_1' => 8, 'user_distance_1' => 0.3, 'user_hours_2' => 3, 'user_limit' => $limit]);
        $v = $r['recommendations_days']['scenario_utilisateur'] ?? null;
        if ($prev !== null && $v !== null && $v > $prev) $viol[] = "limite {$limit} mSv : {$v} j alors que la limite plus basse donnait {$prev} j";
        $prev = $v;
    }
    $out[] = t('monotonie-limite', 'Limite plus haute, restriction plus courte', 'Quand la limite de dose augmente, la durée de restriction ne doit pas augmenter.', $viol ? 'fail' : 'ok', $viol ? 'Violation.' : 'Durées décroissantes avec la limite.', $viol);

    // Cures
    $viol = [];
    foreach (['psma_177lu' => [1, 4, 6], 'lutetium177_net' => [1, 4]] as $code => $counts) {
        $prev = null;
        foreach ($counts as $n) {
            $cur = $vals($calc(['isotope_code' => $code, 'dose_rate' => 40, 'patient_size_cm' => 170, 'cure_count' => $n]));
            if ($prev !== null) foreach ($cur as $k => $v) {
                if ($v < ($prev[$k] ?? 0)) $viol[] = "{$code} / {$k} : {$n} cures donnent {$v} j, moins que {$prev[$k]} j";
                if ($k === 'transport_commun' && $v !== $prev[$k]) $viol[] = "{$code} : transport en commun modifié par le nombre de cures ({$prev[$k]} → {$v})";
            }
            $prev = $cur;
        }
    }
    $out[] = t('cures-coherence', 'Cures multiples', 'Plus de cures planifiées ne doit jamais raccourcir une restriction ; le transport en commun (autres passagers à chaque cure) n\'en dépend pas.', $viol ? 'fail' : 'ok', $viol ? count($viol) . ' violation(s).' : 'Comportement conforme.', $viol);

    // Arrondi
    $viol = [];
    foreach (['radium223', 'psma_177lu', 'mibg_131i', 'iode131_benin'] as $code) {
        $p = ['isotope_code' => $code, 'dose_rate' => 55, 'patient_size_cm' => 168, 'benign_activity_mbq' => 400, 'benign_fixation_pct' => 20];
        $f = $vals($calc($p, 'floor'));
        $r = $vals($calc($p, 'round'));
        foreach ($f as $k => $v) if (!(isset($r[$k]) && $v <= $r[$k] && $r[$k] <= $v + 1)) $viol[] = "{$code} / {$k} : floor={$v}, round=" . ($r[$k] ?? '?');
    }
    $out[] = t('arrondi', 'Modes d\'arrondi', 'La troncature (floor) doit donner au plus la valeur arrondie (round), et jamais plus d\'un jour de moins.', $viol ? 'fail' : 'ok', $viol ? 'Incohérence entre les modes.' : 'Les deux modes sont cohérents.', $viol);

    // Déterminisme et équivalence texte/nombre
    $p = ['isotope_code' => 'radium223', 'dose_rate' => 100, 'patient_size_cm' => 150];
    $same = canonical($calc($p)) === canonical($calc($p)) && canonical($calc($p)) === canonical($calc(['dose_rate' => '100', 'patient_size_cm' => '150'] + $p));
    $out[] = t('determinisme', 'Déterminisme', 'Deux appels identiques donnent la même réponse, et « 100 » (texte) équivaut à 100 (nombre).', $same ? 'ok' : 'fail', $same ? 'Réponses stables.' : 'Réponses différentes pour des entrées équivalentes.');

    // Dose faible : aucune restriction
    $r = $calc(['isotope_code' => 'radium223', 'dose_rate' => 0.01, 'patient_size_cm' => 170]);
    $nonzero = array_filter($vals($r), static fn ($v) => $v > 0);
    $out[] = t('dose-faible', 'Dose très faible', 'Un débit de dose quasi nul ne doit imposer aucune restriction.', $nonzero ? 'fail' : 'ok', $nonzero ? 'Des restrictions sont imposées pour un débit de 0,01 µSv/h.' : 'Aucune restriction, comme attendu.');
    return $out;
}

function finish(array $meta, array $tests, float $started, string $by, string $activeVersion, array $stats, bool $executed, array $diffs = []): array
{
    $fail = array_filter($tests, static fn ($x) => $x['status'] === 'fail');
    $warn = array_filter($tests, static fn ($x) => $x['status'] === 'warn');
    $info = array_filter($tests, static fn ($x) => $x['status'] === 'info');
    $identical = engine_hash(active_dir()) === $meta['hash'];
    if ($fail) {
        $verdict = 'bloquant';
        $headline = "À NE PAS APPLIQUER : " . count($fail) . ' test(s) bloquant(s) (' . implode(', ', array_map(static fn ($x) => $x['title'], $fail)) . ').';
    } elseif ($identical) {
        $verdict = 'identique';
        $headline = 'Le candidat est identique au moteur en service : rien à appliquer.';
    } elseif ($info) {
        $verdict = 'evolution';
        $headline = 'Évolution sans rupture technique : le moteur fonctionne, mais ses résultats ou ses données changent (' . implode(', ', array_map(static fn ($x) => $x['title'], $info)) . '). Relisez les écarts avant d\'appliquer.';
    } else {
        $verdict = 'compatible';
        $headline = 'Compatible : mêmes données, mêmes résultats, mêmes réponses que le moteur en service.' . ($warn ? ' ' . count($warn) . ' remarque(s) à lire.' : '');
    }
    $report = [
        'ok' => true, 'generated_at' => Measurements\now_iso(), 'by' => $by,
        'candidate' => ['repo' => $meta['repo'], 'ref' => $meta['ref'], 'sha' => $meta['sha'], 'version' => $meta['version'], 'hash' => $meta['hash']],
        'active' => ['version' => $activeVersion, 'hash' => engine_hash(active_dir())],
        'verdict' => $verdict, 'headline' => $headline, 'tests' => $tests,
        'stats' => $stats + ['duration_ms' => (int) round((microtime(true) - $started) * 1000), 'executed' => $executed],
    ];
    write_json(report_file(), $report);
    Updater\journal(['action' => 'core-test', 'by' => $by, 'to' => $meta['sha'], 'ok' => $verdict !== 'bloquant', 'verdict' => $verdict]);
    return $report;
}

function last_report(): ?array
{
    $r = read_json(report_file());
    $meta = read_json(candidate_dir() . '/meta.json');
    // Un compte rendu ne vaut que pour le candidat exact qu'il a testé.
    return ($r && $meta && ($r['candidate']['hash'] ?? '') === $meta['hash']) ? $r : null;
}

// Compte rendu en Markdown (téléchargeable, collable dans un ticket).
function report_markdown(array $r): string
{
    $icon = ['ok' => '✅', 'info' => 'ℹ️', 'warn' => '⚠️', 'fail' => '❌'];
    $md = "# Compte rendu de test du moteur RIV\n\n";
    $md .= "- Candidat : `{$r['candidate']['repo']}@{$r['candidate']['ref']}` ({$r['candidate']['version']}, " . substr($r['candidate']['sha'], 0, 7) . ")\n";
    $md .= "- Moteur en service : {$r['active']['version']}\n- Date : {$r['generated_at']}\n\n";
    $md .= "## Verdict\n\n**{$r['headline']}**\n\n## Tests\n\n";
    foreach ($r['tests'] as $x) {
        $md .= "### {$icon[$x['status']]} {$x['title']}\n\n_{$x['what']}_\n\n{$x['result']}\n\n";
        foreach ($x['details'] as $line) $md .= "- {$line}\n";
        if ($x['more']) $md .= "- … et {$x['more']} autre(s)\n";
        $md .= $x['details'] ? "\n" : '';
    }
    return $md;
}

// --- Application au site --------------------------------------------------------------------

function copy_engine(string $from, string $to): void
{
    Config\ensure_dir($to);
    foreach (engine_files($from) as $rel) {
        Config\ensure_dir(dirname("{$to}/{$rel}"));
        if (!@copy("{$from}/{$rel}", "{$to}/{$rel}")) throw new \RuntimeException("Copie impossible : {$rel}");
    }
}

function invalidate(string $dir): void
{
    if (!function_exists('opcache_invalidate')) return;
    foreach (engine_files($dir) as $rel) if (str_ends_with($rel, '.php')) @opcache_invalidate("{$dir}/{$rel}", true);
}

// Remplace le moteur en service par le candidat testé. Refusé sans compte rendu à jour ou avec un verdict bloquant.
function apply(string $by): array
{
    if (!active_is_installed()) return ['ok' => false, 'message' => 'Le moteur en service n\'est pas la copie installée du site (mode développement) : rien à remplacer.'];
    $report = last_report();
    if (!$report) return ['ok' => false, 'message' => 'Lancez d\'abord les tests sur ce candidat.'];
    if ($report['verdict'] === 'bloquant') return ['ok' => false, 'message' => 'Le compte rendu est bloquant : le candidat ne peut pas être appliqué.'];
    if ($report['verdict'] === 'identique') return ['ok' => false, 'message' => 'Le candidat est identique au moteur en service.'];
    $meta = read_json(candidate_dir() . '/meta.json');
    if (!$meta || engine_hash(candidate_dir()) !== $meta['hash']) return ['ok' => false, 'message' => 'Le candidat a changé depuis les tests : relancez-les.'];
    $lock = Updater\acquire_lock();
    if (!$lock) return ['ok' => false, 'message' => 'Une opération est déjà en cours.'];
    $active = active_dir();
    $new = $active . '.new';
    $old = $active . '.old';
    $swapped = false;
    try {
        Updater\rrmdir($new);
        Updater\rrmdir($old);
        copy_engine(candidate_dir(), $new);
        // Réglage local du moteur (arrondi) : conservé s'il existe, c'est un choix du site.
        if (is_file($active . '/data/settings.json')) copy($active . '/data/settings.json', $new . '/data/settings.json');

        // Sauvegarde du moteur actuel (une seule génération) avant tout remplacement.
        Updater\rrmdir(previous_dir());
        copy_engine($active, previous_dir() . '/engine');
        write_json(previous_dir() . '/meta.json', ['at' => Measurements\now_iso(), 'version' => engine_version($active), 'pin' => pin(), 'by' => $by]);

        if (!@rename($active, $old) || !@rename($new, $active)) {
            if (!is_dir($active) && is_dir($old)) @rename($old, $active);
            throw new \RuntimeException('Remplacement impossible (droits d\'écriture sur backend/ ?).');
        }
        $swapped = true;
        invalidate($active);
        write_json(pin_file(), ['version' => $meta['version'], 'sha' => $meta['sha'], 'repo' => $meta['repo'], 'ref' => $meta['ref'], 'at' => Measurements\now_iso(), 'by' => $by]);
        $health = Updater\health_check();
        if ($health === 'bad') {
            restore_previous(false);
            throw new \RuntimeException('Le site ne répond plus correctement avec ce moteur : l\'ancien a été rétabli automatiquement.');
        }
        Updater\rrmdir($old);
        Updater\journal(['action' => 'core-apply', 'by' => $by, 'to' => $meta['sha'], 'ok' => true, 'health' => $health]);
        Updater\notify($by, '[Radioprotection RIV] Moteur de calcul remplacé', "Moteur {$meta['version']} appliqué.");
        return ['ok' => true, 'message' => "Moteur {$meta['version']} appliqué et épinglé : les mises à jour du site ne le changeront plus tant que vous ne retirez pas l'épinglage. Le moteur précédent est conservé (retour arrière possible)."
            . ($health === 'unreachable' ? ' (contrôle de fonctionnement impossible depuis le serveur : vérifiez le site)' : '')];
    } catch (\Throwable $e) {
        if (!$swapped && !is_dir($active) && is_dir($old)) @rename($old, $active);
        $message = $e instanceof \RuntimeException ? $e->getMessage() : 'Erreur interne pendant l\'application.';
        if (!$e instanceof \RuntimeException) error_log('[COREAPPLY] ' . get_class($e) . ': ' . $e->getMessage());
        Updater\journal(['action' => 'core-apply', 'by' => $by, 'ok' => false, 'error' => $message]);
        return ['ok' => false, 'message' => $message];
    } finally {
        Updater\rrmdir($new);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Rétablit le moteur sauvegardé avant la dernière application. $lockless : appelé depuis apply() (verrou déjà pris).
function restore_previous(bool $journal = true, string $by = ''): array
{
    $meta = read_json(previous_dir() . '/meta.json');
    $engine = previous_dir() . '/engine';
    if (!$meta || !is_dir($engine)) return ['ok' => false, 'message' => 'Aucun moteur précédent à rétablir.'];
    $active = active_dir();
    $tmp = $active . '.restore';
    Updater\rrmdir($tmp);
    copy_engine($engine, $tmp);
    $drop = $active . '.drop';
    Updater\rrmdir($drop);
    if (!@rename($active, $drop) || !@rename($tmp, $active)) {
        if (!is_dir($active) && is_dir($drop)) @rename($drop, $active);
        return ['ok' => false, 'message' => 'Rétablissement impossible.'];
    }
    invalidate($active);
    Updater\rrmdir($drop);
    if (!empty($meta['pin'])) write_json(pin_file(), $meta['pin']); else @unlink(pin_file());
    if ($journal) Updater\journal(['action' => 'core-restore', 'by' => $by, 'ok' => true]);
    return ['ok' => true, 'message' => 'Moteur ' . $meta['version'] . ' rétabli.'];
}

function restore_previous_locked(string $by): array
{
    $lock = Updater\acquire_lock();
    if (!$lock) return ['ok' => false, 'message' => 'Une opération est déjà en cours.'];
    try {
        $r = restore_previous(true, $by);
        if ($r['ok'] && Updater\health_check() === 'bad') $r['message'] .= ' Attention : le site ne répond pas correctement.';
        if ($r['ok']) Updater\rrmdir(previous_dir());
        return $r;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function unpin(string $by): array
{
    if (!pin()) return ['ok' => false, 'message' => 'Le moteur n\'est pas épinglé.'];
    @unlink(pin_file());
    Updater\journal(['action' => 'core-unpin', 'by' => $by, 'ok' => true]);
    return ['ok' => true, 'message' => 'Épinglage retiré : la prochaine mise à jour du site réinstallera le moteur de sa version.'];
}
