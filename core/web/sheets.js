// ===== FICHES IMPRIMABLES : soignant (print.html) et patient (patient.html) =====
// Lit le dernier résultat (sessionStorage, déposé par la page principale) et construit la fiche. Les textes viennent
// de config.js (sections printSheet et patientSheet) ; aucun texte visible ici. Tout est inséré par textContent.
(function () {
  'use strict';
  const C = window.RIV_CONFIG;
  const kind = document.body.dataset.sheet;            // 'print' ou 'patient'
  const T = kind === 'patient' ? C.patientSheet : C.printSheet;
  const el = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; };
  const $ = (id) => document.getElementById(id);

  let data = null;
  try { data = JSON.parse(sessionStorage.getItem('riv_sheet') || 'null'); } catch (e) { data = null; }

  document.title = T.title;
  $('title').textContent = T.title;
  $('printBtn').textContent = T.printButton;
  $('printBtn').addEventListener('click', () => window.print());
  $('footer').textContent = T.footer || '';
  if (kind === 'patient') $('lead').textContent = T.lead;
  if (!data || !data.result) { $('content').appendChild(el('p', T.noData)); return; }

  const result = data.result;
  const form = data.form || {};
  const hasValue = (v) => v !== null && v !== undefined && String(v).trim() !== '';

  // Lignes avec une durée calculée (le scénario personnalisé non demandé n'a pas de valeur).
  const rows = (result.rows || []).filter((r) => r.value !== null && r.value !== undefined);

  if (kind === 'print') {
    $('date').textContent = `${T.dateLabel} : ${new Date(data.generated_at || Date.now()).toLocaleString('fr-FR')}`;
    $('patientBox').textContent = T.patientBox;

    // Données saisies : libellé de la page principale + valeur.
    const L = C.labels;
    const inputs = [
      [L.isotope, form.isotope_label], [L.doseRate, form.dose_rate], [L.patientSize, form.patient_size_cm],
      [L.benignActivity, form.benign_activity_mbq], [L.benignFixation, form.benign_fixation_pct], [L.cures, form.cure_count],
      [L.periodDays, form.user_period_days], [L.customHours1, form.user_hours_1], [L.customDistance1, form.user_distance_1],
      [L.customHours2, form.user_hours_2], [L.customLimit, form.user_limit]
    ].filter(([, v]) => hasValue(v));
    const grid = $('inputs');
    inputs.forEach(([label, value]) => { grid.appendChild(el('div', label, 'k')); grid.appendChild(el('div', String(value))); });

    $('inputsTitle').textContent = T.inputsTitle;
    $('resultTitle').textContent = T.resultTitle;
    $('effective').textContent = `${T.effectivePeriod} : ${Math.round(result.effective_days * 100) / 100} j (${Math.round(result.effective_hours * 10) / 10} h)`;
    $('reference').textContent = T.reference;
    $('colSituation').textContent = T.colSituation;
    $('colDays').textContent = T.colDays;
    rows.forEach((r) => {
      const tr = el('tr');
      tr.appendChild(el('td', r.label));
      tr.appendChild(el('td', String(r.value)));
      $('rows').appendChild(tr);
    });
    $('warning').textContent = T.warning;
  } else {
    $('treatmentTitle').textContent = T.treatmentTitle;
    $('rulesTitle').textContent = T.rulesTitle;
    const label = form.isotope_label || (result.selected && result.selected.label) || '';
    $('treatment').appendChild(el('li', `${T.treatmentLabel} : ${label}`));
    const rules = rows.length ? rows.map((r) => {
      if (Number(r.value) === 0) return `${r.label} : ${T.noRestriction}`;
      const advice = T.guidance[r.audience_code] || T.genericGuidance;
      const duration = T.duration(Number(r.value));
      return `${r.label} (${duration}) : ${T.withDuration(advice, duration)}`;
    }) : [T.noRule];
    rules.forEach((text) => $('rules').appendChild(el('li', text)));
    $('doubt').textContent = T.doubt;
  }
})();
