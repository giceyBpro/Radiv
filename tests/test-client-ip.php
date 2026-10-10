<?php
// Contrôle de Http\client_ip() (X-Forwarded-For) : php tests/test-client-ip.php
declare(strict_types=1);
define('RADIV_ENTRY', true);
require __DIR__ . '/../backend/src/lib/config.php';
require __DIR__ . '/../backend/src/lib/http.php';

$fail = 0;
$check = function (string $name, string $expected, string $remote, string $xff, ?string $trusted) use (&$fail): void {
    $_SERVER['REMOTE_ADDR'] = $remote;
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    $trusted === null ? putenv('TRUSTED_PROXIES') : putenv("TRUSTED_PROXIES={$trusted}");
    $got = Radiv\Http\client_ip();
    if ($got !== $expected) { $fail++; echo "ÉCHEC {$name}: attendu {$expected}, obtenu {$got}\n"; } else { echo "OK    {$name}\n"; }
};
$check('sans proxy déclaré, l\'en-tête est ignoré', '203.0.113.9', '203.0.113.9', '1.2.3.4', '');
$check('connexion directe (pas un proxy de confiance)', '203.0.113.9', '203.0.113.9', '1.2.3.4', '127.0.0.1');
$check('proxy local: client réel', '198.51.100.7', '127.0.0.1', '198.51.100.7', '127.0.0.1');
$check('adresse falsifiée à gauche ignorée', '198.51.100.7', '127.0.0.1', '6.6.6.6, 198.51.100.7', '127.0.0.1');
$check('chaîne de deux proxys de confiance', '198.51.100.7', '127.0.0.1', '6.6.6.6, 198.51.100.7, 10.0.0.2', '127.0.0.1,10.0.0.2');
$check('valeur illisible: adresse de la connexion', '127.0.0.1', '127.0.0.1', 'pas-une-ip', '127.0.0.1');
$check('en-tête absent', '127.0.0.1', '127.0.0.1', '', '127.0.0.1');
$check('IPv6 mappée', '198.51.100.7', '::ffff:127.0.0.1', '198.51.100.7', '127.0.0.1');
exit($fail ? 1 : 0);
