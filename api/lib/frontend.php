<?php
// Réponses de configuration lues par la page du site (app.js): partagées entre les routes publiques
// (/api/config, /api/public-config) et la page de test SFMN de l'administration, pour qu'elles ne
// divergent jamais.
declare(strict_types=1);

namespace Radiv\Frontend;

use Radiv\Calculation;
use Radiv\Config;
use Radiv\Updater;

// $sfmn : SFMN proposé ou non (la page de test le force).
function config(bool $sfmn): array
{
    return [
        'isotopes' => Calculation\isotopes(),
        'default_isotope_code' => 'iode131_25_fixation',
        'cure_options_by_isotope' => Calculation\cure_options_by_isotope(),
        'calculation_modes' => $sfmn ? ['local', 'sfmn'] : ['local'],
        'default_calculation_mode' => $sfmn ? 'sfmn' : 'local',
    ] + ($sfmn ? ['sfmn_calculator_url' => Config\env('SFMN_CALCULATOR_URL')] : []); // absent (pas vide) si désactivé
}

function public_config(): array
{
    return [
        'recaptcha_site_key' => Config\env('RECAPTCHA_SITE_KEY'),
        // Reflète la config de journalisation actuelle: sert à générer une page RGPD qui
        // reste correcte sans édition manuelle à chaque changement de .env.
        'measurement_logging_level' => Config\logging_level(),
        'logs_retention_months' => Config\logs_retention_months(),
        // Affichés par le navigateur (bandeau, copyright): modifiables à chaud dans .runtime.env.
        'site_name' => Config\site_name(),
        'copyright_owner' => Config\copyright_owner(),
        // Date (AAAA-MM-JJ) de la version installée: change à chaque mise à jour ou retour arrière depuis /auth.
        'last_updated' => Updater\installed_date(),
    ];
}
