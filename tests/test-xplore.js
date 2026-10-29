// Exécute le script du modèle Xplore (public/downloads/MN_Radiopro_APIRADIV_generique.xml) avec un faux « Tools » et un faux
// « fetch » branché sur le moteur (core/api/handler.php), sans réseau. Vérifie notamment que le script reprend AUTOMATIQUEMENT
// les informations annoncées par /api/config (nombres de cures, saisie attendue) : un isotope ajouté dans les données
// du moteur, avec un profil existant, fonctionne sans modifier le modèle.
// Usage: node tests/test-xplore.js
const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const xml = fs.readFileSync(path.join(root, 'public/downloads/MN_Radiopro_APIRADIV_generique.xml')).toString('latin1');
let pass = 0; let fail = 0;
const t = (name, ok, detail = '') => { if (ok) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };

// Le script est une chaîne JSON dans le XML (deux exemplaires attendus, identiques).
function scripts() {
  const out = [];
  let from = 0;
  for (;;) {
    const i = xml.indexOf('function calculerConsignes', from);
    if (i < 0) break;
    const bound = (pos, step) => { for (;;) { pos += step; if (xml[pos] === '"') { let k = pos - 1; let n = 0; while (xml[k] === '\\') { n += 1; k -= 1; } if (n % 2 === 0) return pos; } } };
    const a = bound(i, -1); const b = bound(i, 1);
    out.push(JSON.parse(`"${xml.slice(a + 1, b).replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')}"`));
    from = b;
  }
  return out;
}
const found = scripts();
t('deux exemplaires du script dans le modèle, identiques', found.length === 2 && found[0] === found[1]);
const source = found[0];
t('le script ne contient plus de référence au mode de calcul', !/calculation_mode|sfmn/i.test(source));

// Copie du moteur (avec un isotope ajouté dans les données) pour vérifier la reprise automatique.
function engineCopy(extra, extraScenarios) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'xplore-core-'));
  for (const sub of ['src', 'data', 'api', 'VERSION']) fs.cpSync(path.join(root, 'core', sub), path.join(dir, sub), { recursive: true });
  if (extra) {
    const f = path.join(dir, 'data/isotopes.json');
    const rows = JSON.parse(fs.readFileSync(f, 'utf8'));
    rows.push(...extra);
    fs.writeFileSync(f, JSON.stringify(rows));
  }
  if (extraScenarios) {
    const f = path.join(dir, 'data/scenarios.json');
    fs.writeFileSync(f, JSON.stringify([...JSON.parse(fs.readFileSync(f, 'utf8')), ...extraScenarios]));
  }
  return dir;
}

// Exécute le script pour un déclencheur donné ; `fields` = valeurs des champs Xplore ; `offline` = config injoignable.
async function run(core, trigger, fields, { offline = false } = {}) {
  const lists = {};
  const values = { ...fields };
  const fakeFetch = async (url, options = {}) => {
    const route = url.replace(/^https:\/\/www\.radiv\.fr\/api\//, '');
    if (offline) throw new Error('réseau coupé');
    const r = JSON.parse(execFileSync('php', [path.join(root, 'tests/xplore-engine-cli.php'), core, options.method || 'GET', route, ...(options.body ? [options.body] : [])]).toString());
    return { ok: r.status < 400, status: r.status, text: async () => r.body };
  };
  const context = vm.createContext({
    trigger, fetch: fakeFetch, console, setTimeout, clearTimeout, AbortController, Promise, JSON, Math, Number, String, Array, Object, isNaN,
    Tools: {
      getValue: (k) => (k in values ? values[k] : null),
      setValue: (k, v) => { values[k] = v; },
      setListeDatasource: (k, v) => { lists[k] = v; },
      getValueLibelleFromDatasource: (k) => { const l = (lists[k] || []).find((x) => x.id === values[k]); return l ? l.libelle : null; },
      jsonToObject: (s) => JSON.parse(s),
    },
  });
  vm.runInContext(source, context);
  for (let i = 0; i < 40; i += 1) await new Promise((ok) => setTimeout(ok, 50)); // laisse les appels asynchrones se terminer
  return { values, lists };
}

(async () => {
  const base = engineCopy(null);
  const extra = [
    { api_code: 'essai_benin', label: 'Essai bénin', period_hours: 122.4, reference: 'essai', remark: '-', situation: '', profile: 'benign_iodine', cures: [1, 2] },
    { api_code: 'essai_standard', label: 'Essai standard', period_hours: 100, reference: 'essai', remark: '-', situation: '', cures: [1, 3] },
  ];
  const withNew = engineCopy(extra);
  const withScenario = engineCopy(null, [{ audience_code: 'voisin_palier', label: 'Contact régulier avec un voisin de palier', exposures: [{ hours: 1, distance: 1, unit_factor_at_1m: true }], limit: 1 }]);
  const size = 1.7;

  let r = await run(base, 'LOAD', { MN_TYPE_MRP: 'radium223' });
  const ids = (r.lists.MN_TYPE_MRP || []).map((x) => x.id);
  t('LOAD: liste des radiopharmaceutiques reprise de /api/config (sans « Personnalisé », qui exige une période)', ids.includes('radium223') && ids.includes('iode131_benin') && !ids.includes('non_defini') && ids.length === 14, ids.join(','));

  r = await run(base, 'MN_TYPE_MRP', { MN_TYPE_MRP: 'psma_177lu', MN_CURE_COUNT: 1 });
  t('choix du PSMA-177Lu: cures 1, 4, 6 (annoncées par RadIV)', (r.lists.MN_CURE_COUNT || []).map((x) => x.id).join() === '1,4,6');

  r = await run(base, 'MN_API_RADIV', { MN_TYPE_MRP: 'radium223', TAILLE: size, 'MN_DOSI-1-M': 100, MN_CURE_COUNT: 1 });
  t('calcul Radium-223 (débit saisi): consignes générées', /Radium/.test(r.values.MN_CONSIGNES_RADIOPRO) && /Débit de dose utilisé : 100/.test(r.values.MN_CONSIGNES_RADIOPRO), String(r.values.MN_CONSIGNES_RADIOPRO).slice(0, 200));
  r = await run(base, 'MN_API_RADIV', { MN_TYPE_MRP: 'radium223', TAILLE: size, MN_CURE_COUNT: 1 });
  t('Radium-223 sans débit: erreur claire', /débit de dose à 1 mètre est obligatoire/.test(r.values.MN_CONSIGNES_RADIOPRO));

  r = await run(base, 'MN_API_RADIV', { MN_TYPE_MRP: 'iode131_benin', TAILLE: size, MN_ACTIVITE_MBQ: 370, MN_TAUX_H24: 20, MN_CURE_COUNT: 1 });
  t('Iode-131-Bénin: activité et fixation exigées par RadIV, calcul et synthèse corrects', /Activité administrée : 370 MBq/.test(r.values.MN_CONSIGNES_RADIOPRO) && /Taux de fixation : 20/.test(r.values.MN_CONSIGNES_RADIOPRO), String(r.values.MN_CONSIGNES_RADIOPRO).slice(0, 200));
  r = await run(base, 'MN_API_RADIV', { MN_TYPE_MRP: 'iode131_benin', TAILLE: size, MN_TAUX_H24: 20, MN_CURE_COUNT: 1 });
  t('Iode-131-Bénin sans activité: erreur claire', /activité en MBq est obligatoire/.test(r.values.MN_CONSIGNES_RADIOPRO));

  // Isotopes ajoutés dans les données du moteur : aucune modification du modèle nécessaire
  r = await run(withNew, 'LOAD', { MN_TYPE_MRP: 'essai_benin' });
  t('isotopes ajoutés dans le moteur: présents dans la liste', (r.lists.MN_TYPE_MRP || []).some((x) => x.id === 'essai_benin') && (r.lists.MN_TYPE_MRP || []).some((x) => x.id === 'essai_standard'));
  r = await run(withNew, 'MN_TYPE_MRP', { MN_TYPE_MRP: 'essai_standard', MN_CURE_COUNT: 1 });
  t('nouvel isotope: nombres de cures repris automatiquement (1, 3)', (r.lists.MN_CURE_COUNT || []).map((x) => x.id).join() === '1,3');
  r = await run(withNew, 'MN_API_RADIV', { MN_TYPE_MRP: 'essai_benin', TAILLE: size, MN_ACTIVITE_MBQ: 370, MN_TAUX_H24: 20, MN_CURE_COUNT: 2 });
  t('nouvel isotope de profil « iode bénin »: saisie activité/fixation appliquée sans modifier le modèle, 2 cures', /Activité administrée : 370 MBq/.test(r.values.MN_CONSIGNES_RADIOPRO) && /2 cures/.test(r.values.MN_CONSIGNES_RADIOPRO), String(r.values.MN_CONSIGNES_RADIOPRO).slice(0, 220));
  r = await run(withNew, 'MN_API_RADIV', { MN_TYPE_MRP: 'essai_benin', TAILLE: size, 'MN_DOSI-1-M': 50, MN_CURE_COUNT: 1 });
  t('…le débit saisi seul ne suffit pas pour ce profil', /activité en MBq est obligatoire/.test(r.values.MN_CONSIGNES_RADIOPRO));
  r = await run(withNew, 'MN_API_RADIV', { MN_TYPE_MRP: 'essai_standard', TAILLE: size, 'MN_DOSI-1-M': 50, MN_CURE_COUNT: 3 });
  t('nouvel isotope standard avec 3 cures: calcul correct', /Calcul effectué pour 3 cures/.test(r.values.MN_CONSIGNES_RADIOPRO), String(r.values.MN_CONSIGNES_RADIOPRO).slice(0, 220));

  // Scénario ajouté dans les données du moteur : une consigne de plus, sans modifier le modèle
  r = await run(withScenario, 'MN_API_RADIV', { MN_TYPE_MRP: 'radium223', TAILLE: size, 'MN_DOSI-1-M': 100, MN_CURE_COUNT: 1 });
  t('scénario ajouté dans le moteur: consigne générique avec son libellé et sa durée, sans modifier le modèle', /Contact régulier avec un voisin de palier, alors pendant \d+ jours?, il faut limiter les contacts/.test(r.values.MN_CONSIGNES_RADIOPRO), String(r.values.MN_CONSIGNES_RADIOPRO).slice(-300));
  t('…les consignes détaillées des scénarios historiques sont conservées', /Si votre conjoint\(e\) a plus de 60 ans, alors pendant 25 jours/.test(r.values.MN_CONSIGNES_RADIOPRO));

  // RadIV injoignable pour la configuration : règles intégrées (isotopes historiques)
  r = await run(base, 'MN_TYPE_MRP', { MN_TYPE_MRP: 'psma_177lu', MN_CURE_COUNT: 1 }, { offline: true });
  t('configuration injoignable: cures du PSMA-177Lu par les règles intégrées', (r.lists.MN_CURE_COUNT || []).map((x) => x.id).join() === '1,4,6');
  r = await run(base, 'LOAD', { MN_TYPE_MRP: 'radium223' }, { offline: true });
  t('configuration injoignable: liste de secours des isotopes', (r.lists.MN_TYPE_MRP || []).length === 14);

  for (const d of [base, withNew, withScenario]) fs.rmSync(d, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`);
  process.exit(fail ? 1 : 0);
})();
