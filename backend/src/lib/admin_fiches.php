<?php
// Onglet « Fiches » de l'administration : liste des fiches d'information par radiopharmaceutique et aperçu de chacune (brouillon non publié,
// avec ses points de relecture). Réservé à une session ouverte ; rien n'est exposé publiquement (voir fiches.php).
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Fiches;
use Radiv\Session;

function do_fiches(): bool
{
    if (Session\admin() === null) return false;
    $out = '<section class="card"><h2>Fiches d\'information (grand public)</h2>'
        . '<p class="small">Une fiche par radiopharmaceutique. Les fiches « brouillon » ne sont <strong>pas publiées</strong> : elles ne sont visibles qu\'ici, pour relecture (l\'aperçu affiche aussi les points à vérifier).</p>'
        . '<div class="tablewrap"><table><thead><tr><th>Fiche</th><th>Adresse prévue</th><th>État</th><th></th></tr></thead><tbody>';
    foreach (Fiches\all() as $fiche) {
        $slug = (string) $fiche['slug'];
        $out .= '<tr><td>' . h($fiche['h1']) . '</td><td class="nowrap"><code>/patient/' . h($slug) . '</code></td><td>'
            . (Fiches\published($fiche) ? '<span class="badge good">publiée</span>' : '<span class="badge warn">brouillon</span>') . '</td>'
            . '<td><a class="btn secondary" href="/auth/fiches/' . h($slug) . '">Ouvrir l\'aperçu</a></td></tr>';
    }
    page('fiches', 'Fiches d\'information', $out . '</tbody></table></div></section>');
    return true;
}

// GET /auth/fiches/<slug> : aperçu complet (page autonome, avec bandeau et points de relecture), jamais indexable.
function do_fiche_preview(string $slug): bool
{
    if (Session\admin() === null) return false;
    $fiche = Fiches\get($slug);
    if ($fiche === null) return false;
    $html = Fiches\page_html($fiche, true);
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'");
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: ' . strlen($html));
    echo $html;
    return true;
}
