// Compare calculation.js (référence, Node) et calculation.php (port) sur une batterie de cas
// fixes + un fuzz à graine fixe, dans les deux modes d'arrondi. Égalité exigée sur la
// sérialisation JSON complète (valeurs, ordre des clés, formatage des nombres).
// Usage: node api/tests/compare-node-php.js [nombre_de_cas_aleatoires]
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.join(__dirname, '..', '..');
const fuzzCount = Number(process.argv[2] || 3000);

const base = { dose_rate: 100, patient_size_cm: 150 };
const fixed = [
  // valeurs de référence connues (README / prompt de portage)
  { isotope_code: 'psma_177lu', dose_rate: 100, patient_size_cm: 150, cure_count: 4 },
  { isotope_code: 'radium223', ...base, cure_count: 1 },
  { isotope_code: 'iode131_25_fixation', dose_rate: 20, patient_size_cm: 160 },
  { isotope_code: 'iode131_benin', benign_activity_mbq: 400, benign_fixation_pct: 15, patient_size_cm: 170 },
  { isotope_code: 'mibg_131i', ...base },
  { isotope_code: 'non_defini', ...base, user_period_days: 2.5 },
  { isotope_code: 'lutetium177_net', ...base, cure_count: 4 },
  // scénario utilisateur
  { isotope_code: 'iode131_5_fixation', ...base, user_hours_1: 4, user_distance_1: 0.4, user_hours_2: 2, user_limit: 3 },
  { isotope_code: 'iode131_5_fixation', ...base, user_hours_1: 4, user_distance_1: 0.00001, user_hours_2: 2, user_limit: 3 },
  { isotope_code: 'iode131_5_fixation', ...base, user_hours_1: 1e-7, user_distance_1: 1e21, user_hours_2: 123456789.123, user_limit: 1e-7 },
  { isotope_code: 'iode131_5_fixation', ...base, user_hours_1: 4 },
  // erreurs de validation
  { isotope_code: 'psma_177lu', patient_size_cm: 150 },
  { isotope_code: 'psma_177lu', ...base, cure_count: 2 },
  { isotope_code: 'psma_177lu', ...base, cure_count: 4.5 },
  { isotope_code: 'psma_177lu', ...base, cure_count: '6' },
  { isotope_code: 'nimporte_quoi', ...base },
  { isotope_code: null, ...base },
  { isotope_code: 'é/"x', ...base },
  { isotope_code: 42, ...base },
  {},
  { isotope_code: 'iode131_benin' },
  { isotope_code: 'iode131_benin', benign_activity_mbq: 400, benign_fixation_pct: 150 },
  { isotope_code: 'non_defini', ...base },
  { isotope_code: 'radium223', dose_rate: 1e300, patient_size_cm: 150 },
  { isotope_code: 'non_defini', ...base, user_period_days: 1e20 },
  { isotope_code: 'non_defini', ...base, user_period_days: 1e308 },
  { isotope_code: 'radium223', dose_rate: '1e2', patient_size_cm: ' 150 ' },
  { isotope_code: 'radium223', dose_rate: '0x64', patient_size_cm: true },
  { isotope_code: 'radium223', dose_rate: 'abc', patient_size_cm: '' },
  { isotope_code: 'radium223', dose_rate: 100, patient_size_cm: '   ' },
  { isotope_code: 'radium223', dose_rate: Infinity, patient_size_cm: 150 }
];

// Écart connu et assumé, volontairement non testé: un tableau/objet JSON passé comme nombre
// (dose_rate: [] ou {}). JS: Number([])=0, Number({})=NaN; PHP (json_decode assoc) ne distingue pas
// les deux et renvoie null. Aucun client réel n'envoie ça; le résultat reste une erreur de validation.

// PRNG déterministe (mulberry32): le fuzz est reproductible d'une exécution à l'autre.
let seed = 20260907;
const rnd = () => { seed |= 0; seed = (seed + 0x6D2B79F5) | 0; let t = Math.imul(seed ^ (seed >>> 15), 1 | seed); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
const pick = (arr) => arr[Math.floor(rnd() * arr.length)];
const { isotopes } = require(path.join(root, 'calculation.js'));
const codes = [...isotopes.map((i) => i.api_code), 'inconnu', null];
const num = (lo, hi, dec = 2) => Number((lo + rnd() * (hi - lo)).toFixed(dec));
const maybe = (value, p = 0.15) => (rnd() < p ? pick([null, undefined, '', 'x', -1, 0, true]) : value);
const fuzz = [];
for (let i = 0; i < fuzzCount; i += 1) {
  const p = {
    isotope_code: pick(codes),
    dose_rate: maybe(rnd() < 0.5 ? num(0.1, 500) : String(num(0.1, 500))),
    patient_size_cm: maybe(num(30, 220, rnd() < 0.5 ? 0 : 1)),
    cure_count: maybe(pick([1, 4, 6, '1', '4', 2, 3, 4.0, null]), 0.1),
    benign_activity_mbq: maybe(num(10, 1500), 0.4),
    benign_fixation_pct: maybe(num(1, 100), 0.4),
    user_period_days: maybe(num(0.05, 20, 3), 0.8)
  };
  if (rnd() < 0.3) Object.assign(p, { user_hours_1: maybe(num(0, 24), 0.1), user_distance_1: maybe(num(0.1, 3), 0.1), user_hours_2: maybe(num(0, 24), 0.1), user_limit: maybe(num(0.1, 20), 0.1) });
  for (const k of Object.keys(p)) if (p[k] === undefined) delete p[k];
  fuzz.push(p);
}
const cases = [...fixed, ...fuzz];

let failures = 0;
for (const mode of ['round', 'floor']) {
  process.env.RESTRICTION_ROUNDING_MODE = mode;
  delete require.cache[require.resolve(path.join(root, 'calculation.js'))];
  const { calculate } = require(path.join(root, 'calculation.js'));
  // Passage par JSON: ce que reçoit réellement l'API (Infinity → null, undefined disparaît).
  const wire = cases.map((c) => JSON.parse(JSON.stringify(c)));
  const phpOut = execFileSync('php', [path.join(__dirname, 'calc-cli.php')], {
    input: JSON.stringify(wire), env: { ...process.env }, maxBuffer: 1 << 28
  }).toString();
  const phpResults = JSON.parse(phpOut);
  // Re-sérialisation pour comparer aussi l'ordre des clés; le texte brut PHP est vérifié ensuite.
  const phpRaw = phpOut;
  const nodeRaw = JSON.stringify(wire.map((w) => calculate(w)));
  let modeFailures = 0;
  wire.forEach((w, idx) => {
    const a = JSON.stringify(calculate(w));
    const b = JSON.stringify(phpResults[idx]);
    if (a !== b) {
      modeFailures += 1;
      if (modeFailures <= 5) console.log(`ÉCART [${mode}] cas #${idx}: ${JSON.stringify(w)}\n  node: ${a.slice(0, 600)}\n  php : ${b.slice(0, 600)}`);
    }
  });
  const rawEqual = nodeRaw === phpRaw;
  if (!rawEqual) { let i = 0; while (nodeRaw[i] === phpRaw[i]) i += 1; console.log(`  1er écart brut @${i}:\n  node: ${nodeRaw.slice(Math.max(0, i - 60), i + 60)}\n  php : ${phpRaw.slice(Math.max(0, i - 60), i + 60)}`); }
  console.log(`[${mode}] ${wire.length} cas, ${modeFailures} écart(s) sémantique/ordre; sortie brute ${rawEqual ? 'identique octet pour octet' : 'différente (formatage)'}`);
  failures += modeFailures;
}
process.exit(failures ? 1 : 0);
