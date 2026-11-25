<?php
// Carte « Comparaison automatique avec la SFMN » de l'onglet Site : interrupteur (désactivée par défaut), état du jour, derniers rapports et
// détail de chaque jour (voir sfmn_audit.php). Mêmes protections que les autres actions : session, CSRF, connexion Google récente pour changer l'état.
declare(strict_types=1);

namespace Radiv\AdminSite;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

use Radiv\Config;
use Radiv\Http;
use Radiv\Session;
use Radiv\SfmnAudit;
use Radiv\Store;

function sfmn_audit_card(string $csrf): string
{
    $state = SfmnAudit\load_state();
    $on = !empty($state['enabled']);
    $out = '<section class="card"><h2>Comparaison automatique avec la SFMN</h2><p class="big"><span class="badge ' . ($on ? 'good' : 'warn') . '">' . ($on ? 'activée' : 'désactivée') . '</span></p>'
        . '<p class="small">Chaque jour, un radiopharmaceutique est tiré dans un cycle (chacun repasse tous les ~14 jours) et 4 cas aux valeurs élevées sont calculés par le moteur et par la SFMN. '
        . 'Toute différence est signalée par e-mail (adresse du formulaire de contact). Déclenchée par la première consultation du site après 1 h, un cas à la fois, au moins '
        . SfmnAudit\MIN_GAP_SECONDS . ' s entre deux appels à la SFMN ; les jours manqués sont rattrapés (' . SfmnAudit\MAX_CATCHUP_DAYS . ' au plus).</p>';
    if ($on && $state['enabled_at'] !== '') $out .= '<p class="small">Activée le ' . h($state['enabled_at']) . ' par ' . h($state['enabled_by']) . '.</p>';
    $cron = SfmnAudit\last_cron();
    $out .= '<p class="small">Tâche planifiée (facultative) : ' . ($cron > 0 ? 'dernier passage le ' . h(gmdate('Y-m-d H:i', $cron)) . ' UTC.' : 'aucun passage enregistré (la première visite du site après 1 h déclenche alors le travail).') . '</p>';
    $cur = $state['current'] ?? null;
    if (is_array($cur)) {
        $done = count(array_filter($cur['cases'], static fn ($c) => isset($c['category'])));
        $out .= '<p class="small">Journée en cours : ' . h($cur['date']) . ' · ' . h($cur['isotope']) . ' · ' . $done . '/' . count($cur['cases']) . ' cas calculés'
            . ((int) ($cur['retry_at'] ?? 0) > time() ? ' · nouvel essai programmé' : '') . '.</p>';
    }
    $plan = SfmnAudit\manual_plan();
    $labels = ['today' => 'Lancer maintenant les requêtes d\'aujourd\'hui', 'resume_today' => 'Terminer maintenant les requêtes d\'aujourd\'hui',
        'tomorrow' => 'Lancer maintenant la première requête de demain'];
    $explain = ['today' => 'Les requêtes du jour n\'ont pas encore été lancées : les 4 cas du jour sont calculés l\'un après l\'autre (' . SfmnAudit\MANUAL_GAP_SECONDS . ' s entre deux appels à la SFMN), en arrière-plan.',
        'resume_today' => 'La journée est entamée : les cas restants sont calculés l\'un après l\'autre, en arrière-plan.',
        'tomorrow' => 'Les requêtes d\'aujourd\'hui sont faites : le bouton lance dès maintenant la première requête planifiée demain (isotope du lendemain) ; les autres cas suivront demain, comme d\'habitude.',
        'tomorrow_started' => 'La journée de demain (' . h($plan['date']) . ', ' . h($plan['isotope']) . ') est déjà démarrée : ses autres cas suivront demain.'];
    $out .= '<p class="small" style="margin-top:10px">' . $explain[$plan['mode']] . ' Fonctionne même si la comparaison est désactivée (essai avant activation).</p>';
    $out .= '<div style="display:flex;gap:10px;flex-wrap:wrap"><form method="post" action="/auth/sfmn-audit" style="margin:0"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="' . ($on ? 'disable' : 'enable') . '"><button type="submit"' . ($on ? ' class="secondary"' : '') . '>' . ($on ? 'Désactiver la comparaison automatique' : 'Activer la comparaison automatique') . '</button></form>'
        . (isset($labels[$plan['mode']]) ? '<form method="post" action="/auth/sfmn-audit" style="margin:0"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="run">'
            . '<button type="submit">' . $labels[$plan['mode']] . '</button></form>' : '') . '</div>';
    $runs = SfmnAudit\recent_runs(14);
    if ($runs) {
        $out .= '<div class="tablewrap" style="margin-top:12px"><table><thead><tr><th>Jour</th><th>Radiopharmaceutique</th><th>Résultat</th><th></th></tr></thead><tbody>';
        foreach ($runs as $run) {
            $bad = (int) $run['anomalies'];
            $parts = [];
            foreach (SfmnAudit\CATEGORIES as $key => $label) if (!empty($run['counts'][$key])) $parts[] = (int) $run['counts'][$key] . ' ' . mb_strtolower($label);
            $out .= '<tr><td class="nowrap">' . h($run['date']) . '</td><td>' . h($run['isotope']) . '</td><td>'
                . ($bad ? '<span class="badge bad">' . h(implode(', ', $parts)) . '</span>' . (!empty($run['email']['sent']) ? ' e-mail envoyé' : ' <span class="muted">e-mail non envoyé</span>') : '<span class="badge good">4 cas concordants</span>')
                . '</td><td><a href="/auth/sfmn-audit/' . h($run['date']) . '">Détail</a></td></tr>';
        }
        $out .= '</tbody></table></div>';
    } else {
        $out .= '<p class="small" style="margin-top:12px">Aucun rapport pour le moment.</p>';
    }
    return $out . '</section>';
}

// Onglet « Comparaison SFMN » : carte de la comparaison automatique, à côté de la page de test manuel.
function do_sfmn_audit_page(): bool
{
    if (Session\admin() === null) return false;
    $flash = Session\flash_take();
    $out = $flash ? '<p class="msg ' . (!empty($flash['ok']) ? 'ok' : 'ko') . '">' . h($flash['message']) . '</p>' : '';
    page('comparaison', 'Comparaison automatique avec la SFMN', $out . sfmn_audit_card(h(Session\csrf_token())));
    return true;
}

function do_sfmn_audit(): bool
{
    $action = (string) ($_POST['action'] ?? '');
    $admin = guard_post($action !== 'run');
    if ($admin === null) return false;
    if (!Store\rate_limit_hit('adminsfmnaudit', Http\client_ip(), 20)) return false;
    if ($action === 'enable' || $action === 'disable') {
        $ok = SfmnAudit\set_enabled($action === 'enable', $admin['email']);
        error_log('[ADMIN] Comparaison SFMN ' . ($action === 'enable' ? 'activée' : 'désactivée') . ' par ' . $admin['email']);
        Session\flash_set(['ok' => $ok, 'message' => $ok ? ($action === 'enable' ? 'Comparaison automatique activée.' : 'Comparaison automatique désactivée.') : 'Enregistrement impossible (dossier de données).']);
    } elseif ($action === 'run') {
        if (!Store\rate_limit_hit('adminsfmnaudit-run', Http\client_ip(), 5)) return false;
        $plan = SfmnAudit\manual_plan();
        if ($plan['mode'] === 'tomorrow_started') {
            Session\flash_set(['ok' => true, 'message' => 'La journée de demain est déjà démarrée.']);
        } elseif (function_exists('fastcgi_finish_request')) {
            // La page revient tout de suite ; le travail (plusieurs appels espacés) continue en arrière-plan. La session est libérée avant :
            // sinon elle resterait verrouillée et l'administration attendrait la fin du travail.
            Session\flash_set(['ok' => true, 'message' => 'Lancé en arrière-plan (' . ['today' => 'requêtes du jour', 'resume_today' => 'requêtes restantes du jour', 'tomorrow' => 'première requête de demain'][$plan['mode']] . ') : actualisez cette page dans une minute pour voir le résultat.']);
            Http\redirect(Config\site_public_url() . '/auth/comparaison-sfmn', 303);
            session_write_close();
            fastcgi_finish_request();
            ignore_user_abort(true);
            @set_time_limit(320);
            try { SfmnAudit\manual_run(); } catch (\Throwable $e) { error_log('[SFMN-AUDIT] lancement manuel : ' . $e->getMessage()); }
            return true;
        } else {
            // Sans fastcgi_finish_request : un seul cas, pour ne pas dépasser la durée d'exécution du serveur.
            @set_time_limit(120);
            $r = SfmnAudit\tick(['force' => true, 'even_if_disabled' => true, 'ahead' => $plan['mode'] === 'tomorrow']);
            $texts = ['cas_execute' => 'Cas ' . ($r['index'] ?? '') . ' calculé (' . ($r['isotope'] ?? '') . ') : ' . ($r['category'] ?? ''), 'jour_termine' => 'Journée terminée : ' . ($r['anomalies'] ?? 0) . ' anomalie(s).',
                'rien_a_faire' => 'Rien à faire pour le moment.', 'nouvel_essai_programme' => 'SFMN indisponible : nouvel essai programmé.', 'occupe' => 'Un calcul est déjà en cours.'];
            Session\flash_set(['ok' => in_array($r['status'], ['cas_execute', 'jour_termine', 'rien_a_faire'], true), 'message' => $texts[$r['status']] ?? $r['status']]);
        }
    } else {
        return false;
    }
    Http\redirect(Config\site_public_url() . '/auth/comparaison-sfmn', 303);
    return true;
}

// GET /auth/sfmn-audit/<jour> : rapport complet d'une journée.
function do_sfmn_audit_detail(string $date): bool
{
    if (Session\admin() === null) return false;
    $run = SfmnAudit\load_run($date);
    if ($run === null) return false;
    $out = '<section class="card"><h2>' . h($run['date']) . ' · ' . h($run['isotope']) . '</h2><p class="small">Moteur v' . h($run['engine_version']) . ', arrondi « ' . h($run['rounding'])
        . ' », graine <code>' . h($run['seed']) . '</code>, terminé le ' . h($run['finished_at']) . ($run['retries'] ? ', ' . (int) $run['retries'] . ' nouvel(s) essai(s)' : '') . '. '
        . (!empty($run['email']['sent']) ? 'E-mail envoyé.' : ($run['anomalies'] ? 'E-mail non envoyé' . (!empty($run['email']['error']) ? ' : ' . h($run['email']['error']) : '') . '.' : 'Aucune anomalie.')) . '</p>'
        . '<div class="tablewrap"><table><thead><tr><th>Valeurs saisies</th><th>Résultat</th><th>Détail</th></tr></thead><tbody>';
    foreach ($run['cases'] as $case) {
        $cat = (string) ($case['category'] ?? '?');
        $detail = '';
        if ($cat === 'discordance') {
            foreach ($case['details']['ecarts'] as $e) $detail .= h($e['champ']) . ' : RadIV ' . h(SfmnAudit\show($e['radiv'])) . ' · SFMN ' . h(SfmnAudit\show($e['sfmn'])) . '<br>';
        } else {
            $detail = h((string) ($case['details']['message'] ?? ''));
        }
        $out .= '<tr><td>' . h(SfmnAudit\payload_text($case['payload'])) . '</td><td>' . ($cat === 'ok' ? '<span class="badge good">concordant</span>' : '<span class="badge bad">' . h(SfmnAudit\CATEGORIES[$cat] ?? $cat) . '</span>')
            . '</td><td class="small">' . $detail . '</td></tr>';
    }
    page('comparaison', 'Comparaison SFMN', $out . '</tbody></table></div><p><a href="/auth/comparaison-sfmn">← Retour</a></p></section>');
    return true;
}
