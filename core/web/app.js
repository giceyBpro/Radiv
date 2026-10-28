// ===== LOGIQUE DE LA PAGE MINIMALE =====
// Appelle l'API du moteur (GET config, POST calculate) et affiche le résultat. Aucun texte visible ici : voir config.js.
(function () {
  'use strict';
  const C = window.RIV_CONFIG;
  const $ = (id) => document.getElementById(id);
  const base = (C.apiBase || '').replace(/\/$/, '');
  let isotopes = [];
  let cureOptions = {};
  // Saisie attendue par isotope, annoncée par le moteur (GET config, isotope_inputs) : "required" / "optional" / "ignored" pour
  // dose_rate, benign_activity_mbq, benign_fixation_pct, user_period_days. Un isotope ajouté dans les données du moteur avec un
  // profil existant s'affiche donc correctement sans modifier cette page. Repli si le moteur n'annonce rien (ancien moteur).
  let inputs = {};
  function inputsFor(code) {
    if (inputs[code]) return inputs[code];
    const standard = { dose_rate: 'required', benign_activity_mbq: 'ignored', benign_fixation_pct: 'ignored', user_period_days: 'optional' };
    if (code === 'iode131_benin') return Object.assign({}, standard, { dose_rate: 'ignored', benign_activity_mbq: 'required', benign_fixation_pct: 'required' });
    if (code === 'non_defini') return Object.assign({}, standard, { user_period_days: 'required' });
    return standard;
  }

  // Textes issus de config.js (textContent : jamais de HTML injecté).
  document.title = C.title;
  document.querySelectorAll('[data-text]').forEach((el) => { el.textContent = C[el.dataset.text] || ''; });
  document.querySelectorAll('[data-label]').forEach((el) => { el.textContent = C.labels[el.dataset.label] || ''; });
  $('submit').textContent = C.labels.calculate;

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
    const wanted = inputsFor(code);
    $('benignFields').hidden = wanted.benign_activity_mbq === 'ignored' && wanted.benign_fixation_pct === 'ignored';
    $('doseRateField').hidden = wanted.dose_rate === 'ignored';
    // Période effective : libellé « obligatoire » quand le moteur l'exige (isotope sans période par défaut).
    const periodLabel = document.querySelector('[data-label="periodDays"]');
    if (periodLabel) periodLabel.textContent = wanted.user_period_days === 'required' ? C.labels.periodDaysRequired : C.labels.periodDays;
  }

  function num(id) { return $(id).value.trim() === '' ? null : Number($(id).value); }

  function payload() {
    const p = { isotope_code: $('isotope').value, patient_size_cm: num('patientSize'), user_period_days: num('periodDays') };
    if (!$('cureField').hidden) p.cure_count = Number($('cures').value);
    const wanted = inputsFor($('isotope').value);
    if (wanted.benign_activity_mbq !== 'ignored') p.benign_activity_mbq = num('benignActivity');
    if (wanted.benign_fixation_pct !== 'ignored') p.benign_fixation_pct = num('benignFixation');
    if (wanted.dose_rate !== 'ignored') p.dose_rate = num('doseRate');
    Object.assign(p, { user_hours_1: num('customHours1'), user_distance_1: num('customDistance1'), user_hours_2: num('customHours2'), user_limit: num('customLimit') });
    return p;
  }

  function render(result) {
    const body = $('results').querySelector('tbody');
    body.innerHTML = '';
    show(result.ok ? [] : (result.errors && result.errors.length ? result.errors : [result.error && result.error.message]));
    $('results').hidden = !result.ok;
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
      inputs = config.isotope_inputs || {};
      isotopes.forEach((iso) => { const o = document.createElement('option'); o.value = iso.api_code; o.textContent = iso.label; $('isotope').appendChild(o); });
      $('isotope').value = config.default_isotope_code;
      refreshFields();
      show([]);
    } catch (e) { show(C.messages.apiError); }
  }

  $('isotope').addEventListener('change', refreshFields);
  $('form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const sent = payload();
      const response = await fetch(base + '/calculate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sent) });
      render(await response.json());
    } catch (e) { show(C.messages.apiError); $('results').hidden = true; }
  });
  init();
})();
