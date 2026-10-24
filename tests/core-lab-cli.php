<?php
// Pilote en ligne de commande de la zone de test du moteur (backend/src/lib/core_lab.php), sans serveur web ni session.
// Usage : php tests/core-lab-cli.php <site_de_test> <action> [dossier_du_candidat]
//   <site_de_test> : dossier contenant backend/ (code + core/ installés) ; les données y sont créées dans data/.
//   actions : stage <dossier> | test | apply | restore | unpin | state
// Sert uniquement à tests/test-core-lab.js (jamais exposé par le serveur web).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[$script, $site, $action] = $argv + [null, null, null];
$arg = $argv[3] ?? null;
putenv('DATA_DIR=' . $site . '/data');
putenv('RATE_LIMIT_BACKEND=file');
define('RADIV_ENTRY', true);
foreach (['json', 'config', 'store', 'http', 'disk', 'measurements', 'contact', 'updater', 'core_lab'] as $f) require "{$site}/backend/src/lib/{$f}.php";
require "{$site}/backend/core/src/bootstrap.php";

use Radiv\CoreLab;

$out = null;
switch ($action) {
    case 'stage':
        Radiv\Updater\rrmdir(CoreLab\candidate_dir());
        mkdir(CoreLab\candidate_dir(), 0777, true);
        CoreLab\copy_engine($arg, CoreLab\candidate_dir());
        // Les fichiers hors liste (ex. un .txt) ne sont pas copiés par copy_engine : seul le contenu reconnu est testé.
        $files = CoreLab\engine_files(CoreLab\candidate_dir());
        CoreLab\write_json(CoreLab\candidate_dir() . '/meta.json', [
            'repo' => 'test/depot', 'ref' => 'essai', 'sha' => str_repeat('a', 40), 'version' => CoreLab\engine_version(CoreLab\candidate_dir()),
            'hash' => CoreLab\engine_hash(CoreLab\candidate_dir()), 'files' => $files, 'fetched_at' => 'maintenant', 'by' => 'test',
        ]);
        $out = ['staged' => $files];
        break;
    case 'test': $out = CoreLab\run_tests('test@example.org'); break;
    case 'apply': $out = CoreLab\apply('test@example.org'); break;
    case 'restore': $out = CoreLab\restore_previous_locked('test@example.org'); break;
    case 'unpin': $out = CoreLab\unpin('test@example.org'); break;
    case 'state': $out = ['version' => CoreLab\engine_version(CoreLab\active_dir()), 'pin' => CoreLab\pin(), 'previous' => is_file(CoreLab\previous_dir() . '/meta.json'), 'installed' => CoreLab\active_is_installed()]; break;
    default: fwrite(STDERR, "action inconnue\n"); exit(2);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
