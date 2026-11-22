// Calculateur intégré aux fiches d'information (/informations/<produit>) : même moteur que la page d'accueil, via l'API du site
// (GET /api/config pour les champs, POST /api/calculate pour le calcul). Aucun service tiers, aucune donnée conservée côté navigateur.
// Balise attendue : <div id="fiche-calc" data-isotopes='[{"code":"psma_177lu","label":"…"}]'></div>
(function () {
  'use strict';
  var root = document.getElementById('fiche-calc');
  if (!root) return;
  var options;
  try { options = JSON.parse(root.getAttribute('data-isotopes') || '[]'); } catch (e) { options = []; }
  if (!options.length) return;
  var base = (window.RADIOPROTECTION_API_URL || '/api').replace(/\/+$/, '');
  var config = null;

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function fieldsFor(code) {
    var inputs = (config.isotope_inputs || {})[code] || {};
    var list = [];
    (config.fields || []).forEach(function (f) {
      // Champs à saisir : obligatoires pour ce produit, plus la taille ; les réglages avancés (période, scénario personnalisé) sont masqués.
      if (inputs[f.code] === 'required') list.push(f);
    });
    return list;
  }

  var form, fieldsBox, select, output;

  function renderFields() {
    var code = select ? select.value : options[0].code;
    fieldsBox.textContent = '';
    fieldsFor(code).forEach(function (f) {
      var label = el('label', { 'for': 'fc-' + f.code }, f.label);
      var input = el('input', { id: 'fc-' + f.code, name: f.code, type: 'number', inputmode: 'decimal', required: 'required', step: String(f.step || 'any') });
      if (f.min !== null && f.min !== undefined) input.setAttribute('min', String(f.min));
      if (f.max !== null && f.max !== undefined) input.setAttribute('max', String(f.max));
      fieldsBox.appendChild(label);
      fieldsBox.appendChild(input);
      if (f.help) fieldsBox.appendChild(el('small', {}, f.help));
    });
    output.textContent = '';
  }

  function showResult(res) {
    output.textContent = '';
    if (!res || !res.ok) {
      var msg = (res && res.errors && res.errors[0]) || (res && res.error && res.error.message) || 'Calcul impossible avec ces valeurs.';
      output.appendChild(el('p', { 'class': 'fc-error', role: 'alert' }, msg));
      return;
    }
    output.appendChild(el('h3', {}, 'Estimation de la durée de restriction'));
    var table = el('table', { 'class': 'fc-table' });
    var head = el('tr');
    head.appendChild(el('th', {}, 'Situation'));
    head.appendChild(el('th', {}, 'Durée'));
    table.appendChild(head);
    (res.rows || []).forEach(function (row) {
      if (row.audience_code === 'scenario_utilisateur' || row.value === null || row.value === undefined) return;
      var tr = el('tr');
      var td = el('td', {}, row.label);
      if (row.condition) td.appendChild(el('small', {}, row.condition));
      tr.appendChild(td);
      tr.appendChild(el('td', { 'class': 'fc-days' }, row.value === 0 ? 'Aucune restriction' : row.value + (row.value > 1 ? ' jours' : ' jour')));
      table.appendChild(tr);
    });
    output.appendChild(table);
    output.appendChild(el('p', { 'class': 'fc-warn' }, "Estimation indicative. Votre équipe de médecine nucléaire peut vous donner des consignes différentes, adaptées à votre situation : ce sont toujours les consignes de l'équipe qui doivent être appliquées."));
  }

  function submit(event) {
    event.preventDefault();
    var payload = { isotope_code: select ? select.value : options[0].code };
    Array.prototype.forEach.call(form.elements, function (input) {
      if (input.name && input.type === 'number' && input.value !== '') payload[input.name] = Number(input.value);
    });
    output.textContent = '';
    output.appendChild(el('p', {}, 'Calcul en cours…'));
    fetch(base + '/calculate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(showResult)
      .catch(function () { output.textContent = ''; output.appendChild(el('p', { 'class': 'fc-error', role: 'alert' }, "Le calcul n'est pas disponible pour le moment. Suivez les consignes de votre équipe.")); });
  }

  function build() {
    root.textContent = '';
    form = el('form', { 'class': 'fc-form', autocomplete: 'off' });
    if (options.length > 1) {
      form.appendChild(el('label', { 'for': 'fc-isotope' }, 'Situation traitée (demandez à votre équipe laquelle correspond à votre cas)'));
      select = el('select', { id: 'fc-isotope' });
      options.forEach(function (o) { select.appendChild(el('option', { value: o.code }, o.label)); });
      select.addEventListener('change', renderFields);
      form.appendChild(select);
    }
    fieldsBox = el('div', { 'class': 'fc-fields' });
    form.appendChild(fieldsBox);
    form.appendChild(el('button', { type: 'submit' }, 'Estimer la durée'));
    output = el('div', { 'class': 'fc-output', 'aria-live': 'polite' });
    root.appendChild(form);
    root.appendChild(output);
    form.addEventListener('submit', submit);
    renderFields();
  }

  fetch(base + '/config', { cache: 'no-store' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (c) {
      if (!c || !c.fields || !c.isotope_inputs) { root.textContent = "Le calculateur n'est pas disponible pour le moment."; return; }
      config = c;
      build();
    })
    .catch(function () { root.textContent = "Le calculateur n'est pas disponible pour le moment."; });
}());
