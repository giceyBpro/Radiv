<?php
// Contrôle d'accès des routes /api/admin/* (jeton ADMIN_TOKEN dans l'en-tête X-Admin-Token).
declare(strict_types=1);

namespace Radiv\Admin;

use Radiv\Config;
use Radiv\Http;
use Radiv\Store;

function authorized(): bool
{
    $token = Config\env('ADMIN_TOKEN');
    if ($token === '') return false;
    // Limite les tentatives (jeton correct ou non) avant même la comparaison, pour rendre un
    // brute-force impraticable; la réponse 404 renvoyée par l'appelant ne distingue ni "jeton
    // invalide" de "trop de tentatives", ni ces deux cas d'une route inexistante.
    if (!Store\rate_limit_hit('admin', Http\client_ip(), 10)) return false;
    $provided = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? null;
    if (!is_string($provided)) return false;
    // Comparaison à temps constant pour ne pas divulguer le jeton octet par octet.
    return hash_equals($token, $provided);
}

// URLSearchParams.get(): première valeur, ou null si absente.
function query_param(string $name): ?string
{
    $value = $_GET[$name] ?? null;
    return is_string($value) ? $value : null;
}

// Équivalent du test de vérité JS sur une chaîne: seule la chaîne vide (ou null) est "fausse",
// pas "0" comme en PHP.
function present(?string $value): bool
{
    return $value !== null && $value !== '';
}
