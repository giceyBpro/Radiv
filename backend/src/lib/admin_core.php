<?php
// Onglet « Moteur » de l'administration : zone de test du moteur de calcul partagé (voir core_lab.php).
// Télécharger une version candidate, lui faire passer la batterie de tests, lire le compte rendu, puis l'appliquer au
// site (ou la rejeter), avec retour arrière. Mêmes protections que les mises à jour : session, CSRF, connexion Google récente.
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\CoreLab;
use Radiv\Http;
use Radiv\Session;
use Radiv\Store;
use Radiv\Updater;

const CORE_STATUS_LABEL = ['ok' => 'réussi', 'info' => 'évolution', 'warn' => 'remarque', 'fail' => 'bloquant'];
const CORE_STATUS_CLASS = ['ok' => 'good', 'info' => 'info', 'warn' => 'warn', 'fail' => 'bad'];

function core_guard(): ?array
{
    if (!Config\admin_update_enabled()) return null;
    $admin = guard_post(true);
    if ($admin === null) return null;
    return Store\rate_limit_hit('admincore', Http\client_ip(), 10) ? $admin : null;
}

function core_done(array $flash): bool
{
    Session\flash_set($flash);
    Http\redirect(Config\site_public_url() . '/auth/moteur', 303);
    return true;
}

// Dépôt du moteur proposé par défaut : CORE_GITHUB_REPO s'il est valide, sinon le dépôt du site.
function core_default_repo(): string
{
    $repo = Config\env('CORE_GITHUB_REPO');
    return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1 ? $repo : Updater\repo();
}

function do_core(): bool
{
    if (Session\admin() === null) return false;
    $flash = Session\flash_take();
    $csrf = h(Session\csrf_token());
    $out = '';
    if ($flash) $out .= '<p class="msg ' . ($flash['ok'] ? 'ok' : 'ko') . '">' . h($flash['message']) . '</p>';

    $active = CoreLab\active_dir();
    $pin = CoreLab\pin();
    $out .= '<section class="card"><h2>Moteur en service</h2><p class="big"><code>' . h(CoreLab\engine_version($active)) . '</code>'
        . ($pin ? ' <span class="badge warn">épinglé</span>' : '') . '</p>'
        . '<p class="small">Contenu : <code>' . h(substr(CoreLab\engine_hash($active), 0, 12)) . '</code>'
        . ($pin ? ' · appliqué le ' . h($pin['at'] ?? '') . ' par ' . h($pin['by'] ?? '') . ' (' . h(($pin['repo'] ?? '') . '@' . ($pin['ref'] ?? '')) . ')' : ' · fourni par la version du site')
        . '</p>'
        . ($pin ? '<p class="small">Épinglé : les mises à jour du site ne modifient plus le moteur. Retirez l\'épinglage pour revenir à celui de la version du site à la prochaine mise à jour.</p>'
            . '<form method="post" action="/auth/moteur/desepingler"><input type="hidden" name="csrf" value="' . $csrf . '"><button type="submit" class="secondary">Retirer l\'épinglage</button></form>' : '')
        . '</section>';

    if (!Config\admin_update_enabled()) {
        $out .= '<section class="card"><p>La zone de test est désactivée (les mises à jour depuis cette page le sont aussi sur ce site).</p></section>';
        page('moteur', 'Moteur de calcul', $out);
        return true;
    }

    $token = token_state();
    $out .= '<section class="card"><h2>1. Télécharger un candidat</h2><p class="small">Le candidat est seulement téléchargé et mis de côté : le site continue d\'utiliser le moteur en service. Seuls les fichiers du moteur (<code>src/</code>, <code>data/</code>, <code>VERSION</code>) sont conservés. N\'indiquez qu\'un dépôt de confiance : le candidat est du code que le test exécute.</p>'
        . '<form method="post" action="/auth/moteur/telecharger" autocomplete="off" class="stack"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<label>Dépôt (propriétaire/nom)<input name="repo" value="' . h(core_default_repo()) . '" required maxlength="100" pattern="[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+"></label>'
        . '<label>Version (tag, branche ou commit)<input name="ref" value="' . h($token['tags'][0] ?? 'main') . '" required maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._\/\-]*"></label>'
        . '<label>Jeton d\'accès' . ($token['ok'] || Config\env('CORE_GITHUB_TOKEN') !== '' ? ' (facultatif : ' . (Config\env('CORE_GITHUB_TOKEN') !== '' ? 'CORE_GITHUB_TOKEN de la configuration' : 'celui du site') . ' est essayé d\'abord)' : '') . '<input type="password" name="token" autocomplete="off"' . ($token['ok'] || Config\env('CORE_GITHUB_TOKEN') !== '' ? '' : ' required') . ' maxlength="255"></label>'
        . '<div><button type="submit">Télécharger</button></div></form></section>';

    $meta = CoreLab\read_json(CoreLab\candidate_dir() . '/meta.json');
    if ($meta) {
        $report = CoreLab\last_report();
        $out .= '<section class="card"><h2>2. Candidat et tests</h2><p class="big"><code>' . h($meta['version']) . '</code> <span class="muted">(' . h($meta['repo'] . '@' . $meta['ref']) . ' · ' . h(substr($meta['sha'], 0, 7)) . ')</span></p>'
            . '<p class="small">Téléchargé le ' . h($meta['fetched_at']) . ' par ' . h($meta['by']) . '. ' . h((string) count($meta['files'])) . ' fichier(s).</p>'
            . '<form method="post" action="/auth/moteur/tester"><input type="hidden" name="csrf" value="' . $csrf . '"><button type="submit">' . ($report ? 'Relancer les tests' : 'Lancer les tests') . '</button></form></section>';
        if ($report) $out .= core_report_card($report, $csrf);
    }
    if (is_file(CoreLab\previous_dir() . '/meta.json')) {
        $prev = CoreLab\read_json(CoreLab\previous_dir() . '/meta.json');
        $out .= '<section class="card"><h2>Retour arrière</h2><form method="post" action="/auth/moteur/retablir" class="stack"><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<p>Moteur précédent conservé : <code>' . h($prev['version'] ?? '?') . '</code> (remplacé le ' . h($prev['at'] ?? '') . ').</p>'
            . '<div><button type="submit" class="secondary">Rétablir le moteur précédent</button></div></form></section>';
    }
    page('moteur', 'Moteur de calcul', $out);
    return true;
}

function core_report_card(array $r, string $csrf): string
{
    $cls = ['identique' => 'good', 'compatible' => 'good', 'evolution' => 'warn', 'bloquant' => 'bad'][$r['verdict']] ?? 'warn';
    $out = '<section class="card"><h2>3. Compte rendu</h2><p><span class="badge ' . $cls . '">' . h(strtoupper($r['verdict'])) . '</span> ' . h($r['headline']) . '</p>'
        . '<p class="small">Établi le ' . h($r['generated_at']) . ' · ' . h((string) ($r['stats']['cases'] ?? 0)) . ' cas × 2 modes d\'arrondi · ' . h((string) ($r['stats']['duration_ms'] ?? 0)) . ' ms · '
        . '<a href="/auth/moteur/rapport.md">télécharger (Markdown)</a></p>';
    foreach ($r['tests'] as $x) {
        $out .= '<details' . (in_array($x['status'], ['fail', 'warn', 'info'], true) ? ' open' : '') . '><summary><span class="badge ' . CORE_STATUS_CLASS[$x['status']] . '">' . CORE_STATUS_LABEL[$x['status']] . '</span> <strong>' . h($x['title']) . '</strong> — ' . h($x['result']) . '</summary>'
            . '<p class="small">' . h($x['what']) . '</p>';
        if ($x['details']) {
            $out .= '<ul class="small">';
            foreach ($x['details'] as $line) $out .= '<li>' . h($line) . '</li>';
            if ($x['more']) $out .= '<li>… et ' . h((string) $x['more']) . ' autre(s)</li>';
            $out .= '</ul>';
        }
        $out .= '</details>';
    }
    if (in_array($r['verdict'], ['compatible', 'evolution'], true)) {
        $out .= '<form method="post" action="/auth/moteur/appliquer" class="stack" style="margin-top:14px"><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<label><input type="checkbox" name="read" value="1" required> J\'ai lu le compte rendu et je veux appliquer ce moteur au site</label>'
            . '<div><button type="submit">4. Appliquer au site</button></div>'
            . '<p class="small">Le moteur actuel est sauvegardé (retour arrière possible) et le nouveau est épinglé : les mises à jour du site ne le remplaceront plus.</p></form>';
    }
    return $out . '</section>';
}

function do_core_fetch(): bool
{
    $admin = core_guard();
    if ($admin === null) return false;
    $posted = trim((string) ($_POST['token'] ?? ''));
    $repo = trim((string) ($_POST['repo'] ?? ''));
    // Jetons essayés dans l'ordre, le premier qui a accès au dépôt visé sert : CORE_GITHUB_TOKEN (configuration), UPDATE_GITHUB_TOKEN
    // (celui du site), puis celui saisi dans la page (jamais conservé).
    $token = '';
    foreach ([Config\env('CORE_GITHUB_TOKEN'), Config\env('UPDATE_GITHUB_TOKEN'), $posted] as $candidate) {
        if ($candidate !== '' && Updater\token_valid($candidate, $repo)) { $token = $candidate; break; }
    }
    if ($token === '') {
        return core_done(['ok' => false, 'message' => 'Aucun jeton valide pour ce dépôt : saisissez un jeton GitHub (lecture du contenu) ayant accès à ' . ($repo !== '' ? $repo : 'ce dépôt') . '.']);
    }
    return core_done(CoreLab\fetch_candidate($repo, trim((string) ($_POST['ref'] ?? '')), $token, $admin['email']));
}

function do_core_test(): bool
{
    $admin = core_guard();
    if ($admin === null) return false;
    $report = CoreLab\run_tests($admin['email']);
    return core_done(($report['ok'] ?? false)
        ? ['ok' => $report['verdict'] !== 'bloquant', 'message' => 'Tests terminés : ' . $report['headline']]
        : ['ok' => false, 'message' => (string) ($report['message'] ?? 'Tests impossibles.')]);
}

function do_core_apply(): bool
{
    $admin = core_guard();
    if ($admin === null) return false;
    if (($_POST['read'] ?? '') !== '1') return core_done(['ok' => false, 'message' => 'Cochez la case pour confirmer la lecture du compte rendu.']);
    return core_done(CoreLab\apply($admin['email']));
}

function do_core_restore(): bool
{
    $admin = core_guard();
    return $admin === null ? false : core_done(CoreLab\restore_previous_locked($admin['email']));
}

function do_core_unpin(): bool
{
    $admin = core_guard();
    return $admin === null ? false : core_done(CoreLab\unpin($admin['email']));
}

function do_core_report_md(): bool
{
    if (Session\admin() === null) return false;
    $report = CoreLab\last_report();
    if (!$report) return false;
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="compte-rendu-moteur-' . preg_replace('/[^0-9A-Za-z.-]/', '', $report['candidate']['version']) . '.md"');
    header('Cache-Control: no-store');
    echo CoreLab\report_markdown($report);
    return true;
}
