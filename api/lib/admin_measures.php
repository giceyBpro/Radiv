<?php
// Onglet « Mesures » de /auth : période (durée d'antériorité ou mois précis), indicateurs, graphiques,
// carte des requêtes, tableau filtrable et export CSV. Les valeurs des journaux viennent d'appelants
// anonymes : tout ce qui est affiché est échappé, et tout ce qui est lu dans l'URL est borné/validé.
declare(strict_types=1);

namespace Radiv\AdminMeasures;

use Radiv\Contact;
use Radiv\Measurements;

const PER_PAGE = 50;
const ROW_CAP = 50000;
const DAY_RANGES = ['1' => '24 h', '7' => '7 jours', '30' => '30 jours', '90' => '90 jours', '365' => '12 mois', 'all' => 'Tout'];
const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const SCENARIO_LABELS = [
    'conjoint_plus_60' => 'Conjoint > 60 ans', 'conjoint_moins_60' => 'Conjoint < 60 ans', 'conjointe_enceinte' => 'Conjointe enceinte',
    'transport_commun' => 'Transport en commun', 'enfant_moins_3_ans' => 'Enfant < 3 ans', 'enfant_3_11_ans' => 'Enfant 3–11 ans',
    'collegues_travail' => 'Collègues', 'scenario_utilisateur' => 'Scénario perso',
];

function h(mixed $value): string
{
    $text = ($value === null || is_scalar($value)) ? (string) $value : (string) json_encode($value);
    return Contact\escape_html($text);
}

function paris(): \DateTimeZone
{
    static $tz = null;
    return $tz ??= new \DateTimeZone('Europe/Paris');
}

// Paramètres de l'URL, tous bornés : durée en jours (1, 7, 30, 90, 365, all) ou mois précis (year[, month]),
// filtres isotope / résultat / texte, page.
function parse_query(array $get): array
{
    // « Mois précis » (liste déroulante) : period=AAAA-M, équivalent de year=AAAA&month=M. Converti AVANT de
    // construire $str, qui copie $get à sa création.
    if (isset($get['period']) && is_string($get['period']) && preg_match('/^(\d{4})-(\d{1,2})$/', $get['period'], $pm)) {
        $get['year'] = $pm[1];
        $get['month'] = $pm[2];
    }
    $str = static fn ($k) => isset($get[$k]) && is_string($get[$k]) ? $get[$k] : '';
    $year = preg_match('/^\d{4}$/', $str('year')) ? $str('year') : null;
    $month = $year !== null && preg_match('/^(0?[1-9]|1[0-2])$/', $str('month')) ? ltrim($str('month'), '0') : null;
    $days = array_key_exists($str('days'), DAY_RANGES) ? $str('days') : '30';
    return [
        'days' => $days, 'year' => $year, 'month' => $month,
        'iso' => mb_substr($str('iso'), 0, 100), 'ok' => in_array($str('ok'), ['1', '0'], true) ? $str('ok') : '',
        'q' => mb_substr(trim($str('q')), 0, 60), 'page' => max(1, min(10000, (int) $str('page'))),
    ];
}

// Intervalle [since, until] en secondes Unix et libellé lisible.
function range_for(array $q): array
{
    $now = time();
    if ($q['year'] !== null) {
        $y = (int) $q['year'];
        if ($q['month'] === null) {
            return [gmmktime(0, 0, 0, 1, 1, $y), gmmktime(0, 0, 0, 1, 1, $y + 1) - 1, 'Année ' . $y];
        }
        $m = (int) $q['month'];
        return [gmmktime(0, 0, 0, $m, 1, $y), gmmktime(0, 0, 0, $m + 1, 1, $y) - 1, ucfirst(MONTHS[$m - 1]) . ' ' . $y];
    }
    if ($q['days'] === 'all') return [0, $now, 'Tout l\'historique'];
    $d = (int) $q['days'];
    return [$now - $d * 86400, $now, $d === 1 ? 'Dernières 24 heures' : 'Derniers ' . $d . ' jours'];
}

// Adresse de l'onglet avec des paramètres modifiés (valeurs par défaut omises), échappée pour un attribut HTML.
function url(array $q, array $override = [], string $path = '/auth/mesures'): string
{
    $q = array_merge($q, $override);
    $params = [];
    if ($q['year'] !== null) {
        $params['year'] = $q['year'];
        if ($q['month'] !== null) $params['month'] = $q['month'];
    } elseif ($q['days'] !== '30') {
        $params['days'] = $q['days'];
    }
    foreach (['iso', 'ok', 'q'] as $k) if ($q[$k] !== '') $params[$k] = $q[$k];
    if ($q['page'] > 1) $params['page'] = $q['page'];
    return h($path . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : ''));
}

function place(object $row): string
{
    $geo = $row->ip_geo ?? null;
    if (!is_object($geo)) return '';
    if (isset($geo->scope)) return 'Réseau local';
    return implode(', ', array_filter([(string) ($geo->city ?? ''), (string) ($geo->region ?? ''), (string) ($geo->country ?? '')], static fn ($s) => $s !== ''));
}

function isotope_of(object $row): string
{
    $input = $row->input ?? null;
    return is_object($input) && is_string($input->isotope_code ?? null) ? $input->isotope_code : '';
}

function result_ok(object $row): ?bool
{
    $result = $row->result ?? null;
    return is_object($result) && is_bool($result->ok ?? null) ? $result->ok : null;
}

function filter_rows(array $rows, array $q): array
{
    $needle = mb_strtolower($q['q']);
    return array_values(array_filter($rows, static function ($row) use ($q, $needle) {
        if ($q['iso'] !== '' && isotope_of($row) !== $q['iso']) return false;
        if ($q['ok'] !== '') {
            $ok = result_ok($row);
            if ($ok === null || $ok !== ($q['ok'] === '1')) return false;
        }
        if ($needle !== '') {
            $hay = mb_strtolower((string) ($row->ip ?? '') . ' ' . place($row));
            if (!str_contains($hay, $needle)) return false;
        }
        return true;
    }));
}

// Tout ce qui est affiché en dehors du tableau : calculé en une passe sur les lignes filtrées.
function compute(array $rows, int $since, int $until): array
{
    $ips = [];
    $places = [];
    $iso = [];
    $withResult = 0;
    $ok = 0;
    $points = [];
    $perDay = [];
    $perMonth = [];
    $minTs = PHP_INT_MAX;
    foreach ($rows as $row) {
        $ts = Measurements\row_ts($row);
        $minTs = min($minTs, $ts);
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone(paris());
        $day = $dt->format('Y-m-d');
        $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        $mon = $dt->format('Y-m');
        $perMonth[$mon] = ($perMonth[$mon] ?? 0) + 1;
        if (is_string($row->ip ?? null)) $ips[$row->ip] = true;
        $pl = place($row);
        if ($pl !== '' && $pl !== 'Réseau local') $places[$pl] = true;
        $code = isotope_of($row);
        if ($code !== '') $iso[$code] = ($iso[$code] ?? 0) + 1;
        $r = result_ok($row);
        if ($r !== null) { $withResult++; if ($r) $ok++; }
        $geo = $row->ip_geo ?? null;
        if (is_object($geo) && is_numeric($geo->latitude ?? null) && is_numeric($geo->longitude ?? null)) {
            $lat = (float) $geo->latitude;
            $lon = (float) $geo->longitude;
            if (abs($lat) <= 90 && abs($lon) <= 180) {
                $key = sprintf('%.3f,%.3f', $lat, $lon);
                $points[$key] ??= ['lat' => round($lat, 3), 'lon' => round($lon, 3), 'count' => 0, 'labels' => []];
                $points[$key]['count']++;
                if ($pl !== '' && count($points[$key]['labels']) < 3) $points[$key]['labels'][$pl] = true;
            }
        }
    }
    arsort($iso);
    return [
        'total' => count($rows), 'ips' => count($ips), 'places' => count($places),
        'withResult' => $withResult, 'ok' => $ok, 'iso' => $iso, 'perDay' => $perDay, 'perMonth' => $perMonth,
        'points' => array_slice(array_map(static fn ($p) => ['lat' => $p['lat'], 'lon' => $p['lon'], 'count' => $p['count'], 'label' => implode(' / ', array_keys($p['labels']))], array_values($points)), 0, 2000),
        'minTs' => $minTs === PHP_INT_MAX ? $until : $minTs,
    ];
}

// Histogramme SVG (aucune bibliothèque) : par jour (Europe/Paris) pour ≤ 100 jours, sinon par mois.
function chart(array $stats, int $since, int $until): string
{
    $start = max($since, $stats['minTs']);
    $byDay = ($until - $start) <= 100 * 86400;
    $buckets = [];
    $counts = $byDay ? $stats['perDay'] : $stats['perMonth'];
    $cursor = (new \DateTimeImmutable('@' . $start))->setTimezone(paris())->setTime(0, 0);
    $end = (new \DateTimeImmutable('@' . $until))->setTimezone(paris());
    if (!$byDay) $cursor = $cursor->modify('first day of this month');
    while ($cursor <= $end && count($buckets) < 400) {
        $key = $cursor->format($byDay ? 'Y-m-d' : 'Y-m');
        $buckets[$key] = $counts[$key] ?? 0;
        $cursor = $cursor->modify($byDay ? '+1 day' : '+1 month');
    }
    if (!$buckets) return '';
    $n = count($buckets);
    $max = max(1, max($buckets));
    $w = 960;
    $hgt = 170;
    $top = 14;
    $bottom = 26;
    $slot = $w / $n;
    $bw = max(1.0, $slot * 0.78);
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($hgt + $top + $bottom) . '" role="img" aria-label="Nombre de mesures par ' . ($byDay ? 'jour' : 'mois') . '" preserveAspectRatio="none">';
    $svg .= '<line class="axis" x1="0" y1="' . ($top + $hgt) . '" x2="' . $w . '" y2="' . ($top + $hgt) . '"/>';
    $svg .= '<text class="lbl" x="2" y="10">' . $max . '</text>';
    $i = 0;
    foreach ($buckets as $key => $count) {
        $bh = $count === 0 ? 0 : max(2, $count / $max * $hgt);
        $x = $i * $slot + ($slot - $bw) / 2;
        $label = $byDay ? (new \DateTimeImmutable($key))->format('d/m/Y') : ucfirst(MONTHS[(int) substr($key, 5, 2) - 1]) . ' ' . substr($key, 0, 4);
        $svg .= '<rect class="bar" x="' . round($x, 2) . '" y="' . round($top + $hgt - $bh, 2) . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '"><title>' . h($label . ' : ' . $count . ' mesure(s)') . '</title></rect>';
        if ($i === 0 || $i === $n - 1 || ($n > 6 && $i === intdiv($n, 2))) {
            $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
            $short = $byDay ? (new \DateTimeImmutable($key))->format('d/m') : substr($key, 5, 2) . '/' . substr($key, 0, 4);
            $svg .= '<text class="lbl" x="' . round($i === 0 ? 0 : ($i === $n - 1 ? $w : $x + $bw / 2), 2) . '" y="' . ($top + $hgt + 18) . '" text-anchor="' . $anchor . '">' . h($short) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

function pills_input(object $row): string
{
    $input = $row->input ?? null;
    if (!is_object($input)) return '<span class="muted">—</span>';
    $parts = [];
    if (isset($input->dose_rate)) $parts[] = 'Débit ' . h($input->dose_rate);
    if (isset($input->patient_size_cm)) $parts[] = 'Taille ' . h($input->patient_size_cm);
    if (!empty($input->user_period_days)) $parts[] = 'Période ' . h($input->user_period_days) . ' j';
    if (!empty($input->benign_activity_mbq)) $parts[] = 'Activité ' . h($input->benign_activity_mbq) . ' MBq';
    if (!empty($input->benign_fixation_pct)) $parts[] = 'Fixation ' . h($input->benign_fixation_pct) . ' %';
    if (isset($input->cure_count) && (int) $input->cure_count > 1) $parts[] = h($input->cure_count) . ' cures';
    return $parts ? implode('', array_map(static fn ($p) => '<span class="pill">' . $p . '</span>', $parts)) : '<span class="muted">—</span>';
}

function pills_result(object $row): string
{
    $result = $row->result ?? null;
    if (!is_object($result)) return '<span class="muted">—</span>';
    $out = '';
    $reco = $result->recommendations_days ?? null;
    if (is_object($reco)) {
        foreach (SCENARIO_LABELS as $code => $label) {
            if (isset($reco->$code) && is_numeric($reco->$code)) $out .= '<span class="pill" title="' . h($label) . '">' . h($label) . ' <b>' . h($reco->$code) . ' j</b></span>';
        }
    }
    if (($result->errors ?? null) && is_array($result->errors)) {
        $out .= '<div class="small err">' . h(implode(' | ', array_map(static fn ($e) => is_string($e) ? $e : '', array_slice($result->errors, 0, 3)))) . '</div>';
    }
    return $out !== '' ? $out : '<span class="muted">—</span>';
}

// Retourne [titre, corps HTML, options de page]. $get = paramètres de l'URL (déjà lus par l'appelant).
function render_tab(array $q, string $loggingLevel): array
{
    [$since, $until, $label] = range_for($q);
    $loaded = Measurements\read_range($since, $until, ROW_CAP);
    $rangeRows = $loaded['rows'];
    $rows = filter_rows($rangeRows, $q);
    $stats = compute($rows, $since, $until);
    $isoOptions = [];
    foreach ($rangeRows as $r) {
        $c = isotope_of($r);
        if ($c !== '') $isoOptions[$c] = true;
    }
    ksort($isoOptions);

    $out = '';
    // --- Sélecteur de période -------------------------------------------------------------------
    $out .= '<section class="card"><div class="toolbar"><div class="seg" role="group" aria-label="Durée d\'antériorité">';
    foreach (DAY_RANGES as $d => $text) {
        $active = $q['year'] === null && $q['days'] === (string) $d;
        $out .= '<a class="segbtn' . ($active ? ' on' : '') . '" href="' . url($q, ['days' => (string) $d, 'year' => null, 'month' => null, 'page' => 1]) . '">' . h($text) . '</a>';
    }
    $out .= '</div>';
    $periods = Measurements\list_periods();
    $out .= '<form method="get" action="/auth/mesures" class="inline"><label for="period">Mois précis</label><select id="period" name="period">';
    $out .= '<option value="">—</option>';
    foreach (array_slice($periods, 0, 36) as $p) {
        $val = $p['year'] . '-' . $p['month'];
        $sel = $q['year'] === (string) $p['year'] && $q['month'] === (string) $p['month'] ? ' selected' : '';
        $out .= '<option value="' . h($val) . '"' . $sel . '>' . h(ucfirst(MONTHS[$p['month'] - 1]) . ' ' . $p['year']) . '</option>';
    }
    $out .= '</select><button type="submit" class="secondary">Afficher</button></form></div>';
    $out .= '<p class="small">Période : <strong>' . h($label) . '</strong> · heure de Paris · niveau de journalisation : <code>' . h($loggingLevel) . '</code>'
        . ($loaded['truncated'] ? ' · <span class="warnt">historique tronqué aux ' . number_format(ROW_CAP, 0, ',', ' ') . ' mesures les plus récentes</span>' : '') . '</p></section>';

    // --- Filtres ---------------------------------------------------------------------------------
    $out .= '<form method="get" action="/auth/mesures" class="card filters">';
    if ($q['year'] !== null) {
        $out .= '<input type="hidden" name="year" value="' . h($q['year']) . '">' . ($q['month'] !== null ? '<input type="hidden" name="month" value="' . h($q['month']) . '">' : '');
    } elseif ($q['days'] !== '30') {
        $out .= '<input type="hidden" name="days" value="' . h($q['days']) . '">';
    }
    $out .= '<label>Isotope<select name="iso"><option value="">Tous</option>';
    foreach (array_keys($isoOptions) as $code) {
        $out .= '<option value="' . h($code) . '"' . ($q['iso'] === (string) $code ? ' selected' : '') . '>' . h($code) . '</option>';
    }
    $out .= '</select></label><label>Résultat<select name="ok"><option value="">Tous</option><option value="1"' . ($q['ok'] === '1' ? ' selected' : '') . '>Réussis</option><option value="0"' . ($q['ok'] === '0' ? ' selected' : '') . '>En erreur</option></select></label>'
        . '<label>IP ou lieu<input type="text" name="q" value="' . h($q['q']) . '" maxlength="60" placeholder="ex. 203.0.113 ou Paris"></label>'
        . '<button type="submit">Filtrer</button>' . ($q['iso'] !== '' || $q['ok'] !== '' || $q['q'] !== '' ? ' <a class="reset" href="' . url($q, ['iso' => '', 'ok' => '', 'q' => '', 'page' => 1]) . '">Réinitialiser</a>' : '') . '</form>';

    // --- Indicateurs -----------------------------------------------------------------------------
    $rate = $stats['withResult'] > 0 ? round($stats['ok'] / $stats['withResult'] * 100) . ' %' : '—';
    $topIso = $stats['iso'] ? array_key_first($stats['iso']) : '—';
    $card = static fn ($v, $l, $cls = '') => '<div class="stat ' . $cls . '"><div class="v">' . $v . '</div><div class="l">' . $l . '</div></div>';
    $out .= '<div class="stats">'
        . $card(number_format($stats['total'], 0, ',', ' '), 'Mesures')
        . $card(h($rate), 'Calculs réussis', 'good')
        . $card($stats['withResult'] > 0 ? number_format($stats['withResult'] - $stats['ok'], 0, ',', ' ') : '—', 'En erreur', 'bad')
        . $card(number_format($stats['ips'], 0, ',', ' '), 'Adresses IP')
        . $card(number_format($stats['places'], 0, ',', ' '), 'Lieux distincts')
        . $card('<span class="vs">' . h($topIso) . '</span>', 'Isotope le plus utilisé')
        . '</div>';

    if ($stats['total'] === 0) {
        $out .= '<section class="card"><p>Aucune mesure sur cette sélection.</p></section>';
    } else {
        // --- Activité + isotopes -----------------------------------------------------------------
        $out .= '<div class="cols"><section class="card"><h2>Activité</h2>' . chart($stats, $since, $until) . '</section>';
        $out .= '<section class="card"><h2>Isotopes</h2>';
        $maxIso = max(1, max($stats['iso'] ?: [1]));
        $shown = 0;
        foreach ($stats['iso'] as $code => $count) {
            if (++$shown > 8) break;
            $out .= '<div class="hbar"><span class="name">' . h($code) . '</span><span class="track"><span class="fill" style="width:' . round($count / $maxIso * 100) . '%"></span></span><span class="num">' . $count . '</span></div>';
        }
        if (!$stats['iso']) $out .= '<p class="muted">Données non journalisées à ce niveau.</p>';
        $out .= '</section></div>';

        // --- Carte -------------------------------------------------------------------------------
        $out .= '<section class="card"><h2>Carte des sollicitations</h2><div class="legend"><span><i class="dot c1"></i> 1–2 requêtes</span><span><i class="dot c2"></i> 3–5 requêtes</span><span><i class="dot c3"></i> 6 requêtes ou plus</span></div>';
        if ($stats['points']) {
            $json = json_encode($stats['points'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $out .= '<div id="map" class="map" role="img" aria-label="Carte des requêtes par localisation"></div>'
                . '<noscript><p class="small">La carte nécessite JavaScript.</p></noscript>'
                . '<script type="application/json" id="map-points">' . $json . '</script>';
        } else {
            $out .= '<p class="muted">Aucune position disponible (adresses locales ou géolocalisation indisponible).</p>';
        }
        $out .= '</section>';

        // --- Tableau -----------------------------------------------------------------------------
        $pages = max(1, (int) ceil($stats['total'] / PER_PAGE));
        $page = min($q['page'], $pages);
        $slice = array_slice($rows, ($page - 1) * PER_PAGE, PER_PAGE);
        $out .= '<section class="card"><div class="toolbar"><h2>Détail</h2><a class="btn secondary" href="' . url($q, ['page' => 1], '/auth/mesures.csv') . '">Exporter en CSV (' . $stats['total'] . ' lignes)</a></div>';
        $out .= '<div class="tablewrap"><table><thead><tr><th>Date</th><th>IP</th><th>Lieu</th><th>Isotope</th><th>Entrées</th><th>Résultats</th><th>État</th></tr></thead><tbody>';
        foreach ($slice as $row) {
            $ok = result_ok($row);
            $dt = (new \DateTimeImmutable('@' . Measurements\row_ts($row)))->setTimezone(paris());
            $out .= '<tr><td class="nowrap">' . h($dt->format('d/m/Y H:i:s')) . '</td><td class="nowrap">' . h($row->ip ?? '') . '</td><td>' . h(place($row)) . '</td>'
                . '<td>' . h(isotope_of($row)) . '</td><td>' . pills_input($row) . '</td><td>' . pills_result($row) . '</td>'
                . '<td>' . ($ok === true ? '<span class="badge good">OK</span>' : ($ok === false ? '<span class="badge bad">Erreur</span>' : '<span class="muted">—</span>')) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
        if ($pages > 1) {
            $out .= '<nav class="pager" aria-label="Pages">'
                . ($page > 1 ? '<a href="' . url($q, ['page' => $page - 1]) . '">&larr; Précédente</a>' : '<span></span>')
                . '<span>Page ' . $page . ' / ' . $pages . '</span>'
                . ($page < $pages ? '<a href="' . url($q, ['page' => $page + 1]) . '">Suivante &rarr;</a>' : '<span></span>') . '</nav>';
        }
        $out .= '</section>';
    }
    return ['Mesures', $out, ['map' => $stats['points'] !== []]];
}

// Export CSV de la sélection (période + filtres), toutes pages confondues.
function csv_for(array $q): array
{
    [$since, $until, ] = range_for($q);
    $rows = filter_rows(Measurements\read_range($since, $until, ROW_CAP)['rows'], $q);
    $label = $q['year'] !== null ? ($q['month'] !== null ? $q['year'] . '-' . str_pad($q['month'], 2, '0', STR_PAD_LEFT) : $q['year']) : ($q['days'] === 'all' ? 'tout' : $q['days'] . 'j');
    return [Measurements\to_csv($rows), $label];
}
