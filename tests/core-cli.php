<?php
// Contrat du moteur vu du site : lit sur stdin un tableau de payloads, écrit {version, results} sur stdout.
// Charge le moteur comme le fait le site (backend/core/ installé, sinon core/ du dépôt). Sert à tests/test-core-contract.js.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require is_file("{$root}/backend/core/src/bootstrap.php") ? "{$root}/backend/core/src/bootstrap.php" : "{$root}/core/src/bootstrap.php";
RivEngine\set_rounding_mode(static fn () => getenv('RESTRICTION_ROUNDING_MODE') ?: null);

$payloads = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$results = [];
foreach ($payloads as $payload) $results[] = RivEngine\calculate(is_array($payload) ? $payload : []);
// Même encodage que l'API du site : NaN / ±Infini deviennent null (comme JSON.stringify).
$normalize = static function ($v) use (&$normalize) {
    if (is_float($v)) return is_finite($v) ? $v : null;
    if (is_array($v)) foreach ($v as $k => $x) $v[$k] = $normalize($x);
    return $v;
};
echo json_encode($normalize(['version' => RivEngine\version(), 'results' => $results]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
