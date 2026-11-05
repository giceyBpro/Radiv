// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
// Met à jour les résultats attendus de golden-calcul.json (les cas figés) après une évolution VOULUE du moteur : ajout d'un isotope (la liste des
// codes valides figure dans les messages d'erreur), d'un scénario (une ligne de plus dans chaque résultat), changement de libellé, de règle...
// Sans option : montre seulement ce qui changerait (nombre de cas touchés et chemins modifiés), sans rien écrire. Avec --write : réécrit le fichier.
// RELIRE chaque écart avant d'écrire : le fichier est le contrat chiffré du moteur (voir AGENTS.md). Les cas eux-mêmes (entrées) ne changent pas.
// Usage : node core/tests/update-golden.js [--write]
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const file = path.join(__dirname, 'golden-calcul.json');
const golden = JSON.parse(fs.readFileSync(file, 'utf8'));
const write = process.argv.includes('--write');

// Chemins (sans les indices de tableau) qui diffèrent entre deux valeurs JSON.
function diffPaths(a, b, where = '', out = new Set()) {
  if (JSON.stringify(a) === JSON.stringify(b)) return out;
  if (a && b && typeof a === 'object' && typeof b === 'object') {
    for (const key of new Set([...Object.keys(a), ...Object.keys(b)])) diffPaths(a[key], b[key], Array.isArray(a) ? where : `${where}.${key}`, out);
  } else out.add(where.replace(/^\./, '') || '(racine)');
  return out;
}

let touched = 0;
const summary = new Map();
for (const mode of ['round', 'floor']) {
  const output = execFileSync('php', [path.join(__dirname, 'calc-cli.php')], {
    input: JSON.stringify(golden.cases),
    env: { ...process.env, RESTRICTION_ROUNDING_MODE: mode },
    maxBuffer: 1 << 28
  }).toString();
  const got = JSON.parse(output);
  golden.cases.forEach((c, i) => {
    if (JSON.stringify(golden.expected[mode][i]) === JSON.stringify(got[i])) return;
    touched += 1;
    for (const p of diffPaths(golden.expected[mode][i], got[i])) summary.set(p, (summary.get(p) || 0) + 1);
    if (write) golden.expected[mode][i] = got[i];
  });
}
if (!touched) { console.log('Aucun écart : les cas figés sont à jour.'); process.exit(0); }
console.log(`${touched} résultat(s) attendu(s) changent (tous modes d'arrondi confondus). Chemins modifiés :`);
for (const [p, n] of [...summary].sort()) console.log(`  ${p}  (${n})`);
if (write) { fs.writeFileSync(file, JSON.stringify(golden)); console.log('golden-calcul.json réécrit. Relisez le diff, puis mettez à jour la copie du site (tests/golden-core-contract.json) si vous en avez une.'); }
else console.log('Rien n\'a été écrit. Après relecture : node core/tests/update-golden.js --write');
