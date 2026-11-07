<?php
// Prépare la connexion alternative de /auth (identifiant + mot de passe + code TOTP). À lancer sur VOTRE poste, jamais sur le site :
//   php tools/admin-login.php <identifiant> [nom-du-site]
// Le mot de passe est demandé sans écho. Le script affiche trois lignes à ajouter à config/runtime.env (rien n'est écrit, aucun
// mot de passe n'est conservé) et l'adresse otpauth à saisir (ou à transformer en QR code) dans l'application d'authentification.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RADIV_ENTRY', true);
require __DIR__ . '/../backend/src/lib/admin_login.php';

$login = $argv[1] ?? '';
if (preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $login) !== 1) { fwrite(STDERR, "Usage : php tools/admin-login.php <identifiant (3 à 64 caractères)> [nom-du-site]\n"); exit(1); }
$site = $argv[2] ?? 'Radiv';
fwrite(STDERR, 'Mot de passe (12 caractères au moins) : ');
if (DIRECTORY_SEPARATOR === '/') system('stty -echo');
$password = rtrim((string) fgets(STDIN), "\r\n");
if (DIRECTORY_SEPARATOR === '/') system('stty echo');
fwrite(STDERR, "\n");
if (strlen($password) < 12) { fwrite(STDERR, "Mot de passe trop court.\n"); exit(1); }
$secret = Radiv\AdminLogin\base32_encode(random_bytes(20));
echo "ADMIN_LOGIN_USER=\"{$login}\"\n";
echo 'ADMIN_LOGIN_PASSWORD_HASH=\'' . password_hash($password, PASSWORD_DEFAULT) . "'\n";
echo "ADMIN_TOTP_SECRET=\"{$secret}\"\n\n";
echo "Application d'authentification : ajoutez un compte par saisie manuelle de la clé {$secret} (SHA-1, 6 chiffres, 30 s)\n";
echo 'ou par l\'adresse : ' . Radiv\AdminLogin\otpauth_uri($login, $secret, $site) . "\n";
