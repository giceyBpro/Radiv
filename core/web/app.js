// ===== LOGIQUE DE LA PAGE MINIMALE =====
// Appelle l'API du moteur (GET config, POST calculate) et affiche le résultat. Aucun texte visible ici : voir config.js.
(function () {
  'use strict';
  const C = window.RIV_CONFIG;
  const $ = (id) => document.getElementById(id);
  const base = (C.apiBase || '').replace(/\/$/, '');
  let isotopes = [];
  let cureOptions = {};
  let lastForm = null;
  let lastSent = null;

  // Textes issus de config.js (textContent : jamais de HTML injecté).
  document.title = C.title;
  document.querySelectorAll('[data-text]').forEach((el) => { el.textContent = C[el.dataset.text] || ''; });
  document.querySelectorAll('[data-label]').forEach((el) => { el.textContent = C.labels[el.dataset.label] || ''; });
  $('submit').textContent = C.labels.calculate;
  $('printSheetBtn').textContent = C.labels.printSheet;
  $('patientSheetBtn').textContent = C.labels.patientSheet;

  function show(message) {
    const list = $('errors');
    list.innerHTML = '';
    (Array.isArray(message) ? message : [message]).filter(Boolean).forEach((m) => {
      const li = document.createElement('li');
      li.textContent = m;
      list.appendChild(li);
    });
    list.hidden = !list.children.length;
  }

  // Champs affichés selon l'isotope choisi : nombre de cures, activité/fixation (iode bénin), débit de dose.
  function refreshFields() {
    const code = $('isotope').value;
    const cures = cureOptions[code] || [];
    $('cures').innerHTML = '';
    cures.forEach((n) => { const o = document.createElement('option'); o.value = n; o.textContent = n; $('cures').appendChild(o); });
    $('cureField').hidden = cures.length < 2;
    $('benignFields').hidden = code !== 'iode131_benin';
    $('doseRateField').hidden = code === 'iode131_benin';
  }

  function num(id) { return $(id).value.trim() === '' ? null : Number($(id).value); }

  function payload() {
    const p = { isotope_code: $('isotope').value, patient_size_cm: num('patientSize'), user_period_days: num('periodDays') };
    if (!$('cureField').hidden) p.cure_count = Number($('cures').value);
    if ($('isotope').value === 'iode131_benin') { p.benign_activity_mbq = num('benignActivity'); p.benign_fixation_pct = num('benignFixation'); }
    else p.dose_rate = num('doseRate');
    Object.assign(p, { user_hours_1: num('customHours1'), user_distance_1: num('customDistance1'), user_hours_2: num('customHours2'), user_limit: num('customLimit') });
    return p;
  }

  function render(result) {
    const body = $('results').querySelector('tbody');
    body.innerHTML = '';
    show(result.ok ? [] : (result.errors && result.errors.length ? result.errors : [result.error && result.error.message]));
    $('results').hidden = !result.ok;
    $('sheetActions').hidden = !result.ok;
    lastSent = result.ok ? { generated_at: new Date().toISOString(), form: lastForm, result } : null;
    if (!result.ok) return;
    result.rows.forEach((row) => {
      if (row.audience_code === 'scenario_utilisateur' && row.value === null) return; // scénario personnalisé non demandé
      const tr = document.createElement('tr');
      [row.label, row.condition, row.value === null ? C.messages.notComputed : String(row.value)].forEach((text) => {
        const td = document.createElement('td');
        td.textContent = text;
        tr.appendChild(td);
      });
      body.appendChild(tr);
    });
  }

  async function init() {
    show(C.messages.loading);
    try {
      const config = await (await fetch(base + '/config')).json();
      isotopes = config.isotopes;
      cureOptions = config.cure_options_by_isotope || {};
      isotopes.forEach((iso) => { const o = document.createElement('option'); o.value = iso.api_code; o.textContent = iso.label; $('isotope').appendChild(o); });
      $('isotope').value = config.default_isotope_code;
      refreshFields();
      show([]);
    } catch (e) { show(C.messages.apiError); }
  }

  // Les fiches (print.html, patient.html) lisent le dernier résultat dans sessionStorage, rien n'est envoyé ailleurs.
  function openSheet(page) {
    if (!lastSent) return;
    try { sessionStorage.setItem('riv_sheet', JSON.stringify(lastSent)); } catch (e) { return; }
    window.open(page, '_blank');
  }
  $('printSheetBtn').addEventListener('click', () => openSheet('print.html'));
  $('patientSheetBtn').addEventListener('click', () => openSheet('patient.html'));
  $('isotope').addEventListener('change', refreshFields);
  $('form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const sent = payload();
      lastForm = Object.assign({ isotope_label: $('isotope').selectedOptions[0].textContent }, sent);
      const response = await fetch(base + '/calculate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sent) });
      render(await response.json());
    } catch (e) { show(C.messages.apiError); $('results').hidden = true; }
  });
  init();
})();
