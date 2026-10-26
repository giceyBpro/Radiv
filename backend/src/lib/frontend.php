<?php
// Réponses de configuration lues par la page du site (app.js): partagées entre les routes publiques
// (/api/config, /api/public-config) et la page de test SFMN de l'administration, pour qu'elles ne
// divergent jamais.
declare(strict_types=1);

namespace Radiv\Frontend;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use RivEngine as Calculation;
use Radiv\Config;
use Radiv\Maintenance;
use Radiv\Updater;

// $sfmn : true pour la page de test de l'administration seulement (comparaison avec le calcul distant).
function config(bool $sfmn): array
{
    return [
        'isotopes' => Calculation\isotopes(),
        'default_isotope_code' => 'iode131_25_fixation',
        'cure_options_by_isotope' => Calculation\cure_options_by_isotope(),
    ] + (function_exists('RivEngine\\isotope_inputs') ? ['isotope_inputs' => Calculation\isotope_inputs()] : []) // moteur < 2.3.0 (épinglé) : la page retombe sur ses règles intégrées
     + (!$sfmn && Maintenance\mode() !== 'off' ? ['maintenance' => Maintenance\mode()] : []) // absent hors maintenance et sur la page de test
    + ($sfmn ? [
        // Page de test de l'administration seulement : l'API publique ne connaît qu'un calcul.
        'calculation_modes' => ['local', 'sfmn'],
        'default_calculation_mode' => 'sfmn',
        'sfmn_calculator_url' => Config\env('SFMN_CALCULATOR_URL'),
    ] : []);
}

function public_config(): array
{
    return [
        'recaptcha_site_key' => Config\env('RECAPTCHA_SITE_KEY'),
        // Reflète la config de journalisation actuelle: sert à générer une page RGPD qui
        // reste correcte sans édition manuelle à chaque changement de .env.
        'measurement_logging_level' => Config\logging_level(),
        'logs_retention_months' => Config\logs_retention_months(),
        // Affichés par le navigateur (bandeau, copyright): modifiables à chaud dans config/runtime.env.
        'site_name' => Config\site_name(),
        // Adresse de l'outil en ligne de la SFMN (lien affiché sur les pages) : SFMN_CALCULATOR_URL, vide si non configurée.
        'sfmn_tool_url' => Config\sfmn_tool_url(),
        'copyright_owner' => Config\copyright_owner(),
        // Date (AAAA-MM-JJ) de la version installée: change à chaque mise à jour ou retour arrière depuis /auth.
        'last_updated' => Updater\installed_date(),
    ] + (Maintenance\mode() !== 'off' ? ['maintenance' => Maintenance\mode()] : []);
}
