// Volets repliables de la page de test SFMN : comparaison avec le calcul local et échanges SFMN.
// Reçoit le résultat de app.js (événements radiv:result / radiv:clear); aucune valeur n'est insérée comme HTML.
(function () {
  var anchor = document.getElementById('resultsSection');
  if (!anchor) return;
  var box = document.createElement('section');
  box.className = 'admin-details';
  box.hidden = true;
  anchor.after(box);

  function el(tag, text, cls) {
    var e = document.createElement(tag);
    if (text !== undefined && text !== null) e.textContent = String(text);
    if (cls) e.className = cls;
    return e;
  }
  function show(v) { return v === null || v === undefined ? '-' : String(v); }

  function comparison(result, local) {
    var d = el('details');
    d.appendChild(el('summary', 'Comparaison avec le calcul local'));
    if (!local || !result.recommendations_days || result.admin_details.mode === 'local') {
      d.appendChild(el('p', result.admin_details.mode === 'local'
        ? 'Mode « calcul local » sélectionné : rien à comparer.' : 'Pas de résultat à comparer.', 'note'));
      return d;
    }
    var labels = {};
    (local.rows || []).forEach(function (r) { labels[r.audience_code] = r.label; });
    var keys = Object.keys(result.recommendations_days);
    Object.keys(local.recommendations_days || {}).forEach(function (k) { if (keys.indexOf(k) < 0) keys.push(k); });
    var table = el('table');
    var head = el('tr');
    ['Scénario', 'SFMN (jours)', 'Local (jours)', 'Écart'].forEach(function (t, i) { head.appendChild(el('th', t, i ? 'num' : '')); });
    table.appendChild(head);
    var diffs = 0;
    keys.forEach(function (k) {
      var a = result.recommendations_days[k], b = (local.recommendations_days || {})[k];
      var both = typeof a === 'number' && typeof b === 'number';
      var gap = both ? Math.round((a - b) * 100) / 100 : (a === b ? 0 : null);
      var tr = el('tr', null, gap === 0 ? '' : 'diff');
      if (gap !== 0) diffs += 1;
      tr.appendChild(el('td', labels[k] || k));
      tr.appendChild(el('td', show(a), 'num'));
      tr.appendChild(el('td', show(b), 'num'));
      tr.appendChild(el('td', gap === null ? 'différent' : (gap > 0 ? '+' : '') + gap, 'num'));
      table.appendChild(tr);
    });
    d.appendChild(table);
    d.appendChild(el('p', 'Période effective — SFMN : ' + show(result.effective_days) + ' j / ' + show(result.effective_hours) + ' h ; local : '
      + show(local.effective_days) + ' j / ' + show(local.effective_hours) + ' h.', 'note'));
    d.appendChild(el('p', diffs ? diffs + ' écart(s) entre les deux calculs.' : 'Aucun écart entre les deux calculs.', 'note'));
    return d;
  }

  function technical(result) {
    var d = el('details');
    d.appendChild(el('summary', 'Détails techniques SFMN'));
    var dbg = result.admin_details.sfmn_debug;
    d.appendChild(dbg ? el('pre', JSON.stringify(dbg, null, 2)) : el('p', 'Aucun échange SFMN pour ce calcul.', 'note'));
    return d;
  }

  document.addEventListener('radiv:result', function (ev) {
    var result = ev.detail;
    box.textContent = '';
    if (!result || !result.admin_details) { box.hidden = true; return; }
    box.appendChild(comparison(result, result.admin_details.local));
    box.appendChild(technical(result));
    box.hidden = false;
  });
  document.addEventListener('radiv:clear', function () { box.textContent = ''; box.hidden = true; });
})();
