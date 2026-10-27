// Zone de test du moteur (backend/src/lib/core_lab.php) : candidat identique, évolution, code dangereux, fonction
// manquante, bug de cohérence, structure cassée ; application au site, épinglage et retour arrière.
// Un faux site est assemblé dans un dossier temporaire (backend/ + moteur installé) ; chaque action tourne dans son
// propre processus PHP (le candidat ne peut être chargé qu'une fois par processus). Aucun réseau.
// Usage: node tests/test-core-lab.js
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const root = path.resolve(__dirname, '..');
const site = fs.mkdtempSync(path.join(os.tmpdir(), 'corelab-'));
let failures = 0;
const t = (name, ok, detail = '') => { console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${name}${ok ? '' : ' — ' + detail}`); if (!ok) failures += 1; };

const copy = (from, to) => fs.cpSync(from, to, { recursive: true });
copy(path.join(root, 'backend/src'), path.join(site, 'backend/src'));
for (const sub of ['src', 'data', 'VERSION']) copy(path.join(root, 'core', sub), path.join(site, 'backend/core', sub));

const php = (action, arg) => JSON.parse(execFileSync('php', [path.join(root, 'tests/core-lab-cli.php'), site, action, ...(arg ? [arg] : [])], { maxBuffer: 1 << 26 }).toString());

// Prépare un candidat dérivé du moteur du dépôt, modifié par `mutate(dossier)`.
let n = 0;
const candidate = (mutate) => {
  const dir = path.join(site, `cand-${n++}`);
  for (const sub of ['src', 'data', 'VERSION']) copy(path.join(root, 'core', sub), path.join(dir, sub));
  mutate(dir);
  php('stage', dir);
  return php('test');
};
const edit = (dir, file, from, to) => {
  const p = path.join(dir, file);
  const s = fs.readFileSync(p, 'utf8');
  if (!s.includes(from)) throw new Error(`motif introuvable dans ${file}: ${from}`);
  fs.writeFileSync(p, s.replace(from, to));
};
const test = (report, id) => report.tests.find((x) => x.id === id);

const baseVersion = fs.readFileSync(path.join(root, 'core/VERSION'), 'utf8').trim(); // version du moteur du dépôt, installée dans le faux site

// 1. Identique
let r = candidate(() => {});
t('candidat identique → verdict « identique »', r.verdict === 'identique' && r.tests.every((x) => x.status === 'ok'), r.headline);
t('…et son application est refusée', php('apply').ok === false);

// 2. Évolution (nouvelle majeure : donnée et version modifiées)
fs.writeFileSync(path.join(site, 'backend/core/data/settings.json'), '{"rounding_mode":"floor"}\n');
r = candidate((d) => {
  fs.writeFileSync(path.join(d, 'VERSION'), '3.0.0\n');
  // Limite du scénario « collègues de travail » portée de 1 à 2 mSv (dernier scénario du fichier)
  const f = path.join(d, 'data/scenarios.json');
  const rows = JSON.parse(fs.readFileSync(f, 'utf8'));
  rows.find((x) => x.audience_code === 'collegues_travail').limit = 2;
  fs.writeFileSync(f, JSON.stringify(rows));
});
t('évolution de données → verdict « evolution »', r.verdict === 'evolution', r.headline);
t('…résultats : écarts détaillés par scénario', test(r, 'resultats').status === 'info' && test(r, 'resultats').details.some((l) => l.startsWith('collegues_travail')), JSON.stringify(test(r, 'resultats').details));
t('…catalogue : scénario modifié signalé', test(r, 'scenarios').status === 'info');
t('…changement de majeure signalé', /majeure/.test(test(r, 'identite').result));
t('…aucun test bloquant', r.tests.every((x) => x.status !== 'fail'));
const applied = php('apply');
t('application au site réussie', applied.ok === true, applied.message);
let state = php('state');
t('moteur remplacé, épinglé, précédent conservé', state.version === '3.0.0' && state.pin && state.pin.version === '3.0.0' && state.previous === true, JSON.stringify(state));
t('réglage local (settings.json) conservé', fs.readFileSync(path.join(site, 'backend/core/data/settings.json'), 'utf8').includes('floor'));
const restored = php('restore');
state = php('state');
t('retour arrière : ancien moteur rétabli, épinglage retiré', restored.ok && state.version === baseVersion && state.pin === null && state.previous === false, JSON.stringify([restored, state]));
t('…dossiers temporaires nettoyés', !fs.existsSync(path.join(site, 'backend/core.new')) && !fs.existsSync(path.join(site, 'backend/core.old')) && !fs.existsSync(path.join(site, 'backend/core.drop')));

// 2 bis. Champ ajouté à la réponse : évolution compatible (sans danger), signalée
r = candidate((d) => edit(d, 'src/calculate.php', "return ['ok' => true] + $common", "return ['ok' => true, 'moteur' => 'x'] + $common"));
t('champ ajouté → évolution compatible signalée', r.verdict === 'evolution' && test(r, 'structure').status === 'info' && test(r, 'structure').details.some((l) => /Champ ajouté/.test(l)), r.headline);

// 2 ter. Scénario d'exposition ajouté dans les données: évolution signalée (ligne de plus), jamais bloquante
r = candidate((d) => {
  const f = path.join(d, 'data/scenarios.json');
  const rows = JSON.parse(fs.readFileSync(f, 'utf8'));
  rows.push({ audience_code: 'voisin_palier', label: 'Voisin de palier', exposures: [{ hours: 1, distance: 1, unit_factor_at_1m: true }], limit: 1 });
  fs.writeFileSync(f, JSON.stringify(rows));
});
t('scénario ajouté dans les données → évolution, ajout signalé', r.verdict === 'evolution' && test(r, 'scenarios').status === 'info' && test(r, 'scenarios').details.some((l) => /Scénario ajouté : voisin_palier/.test(l)), r.headline);
t('…la structure des réponses reste compatible (ligne de plus seulement)', test(r, 'structure').status !== 'fail');

// 3. Code dangereux : refusé, jamais exécuté
r = candidate((d) => edit(d, 'src/physics.php', 'function decay_fraction', "function leak() { return shell_exec('id'); }\nfunction decay_fraction"));
t('appel système → analyse statique bloquante, candidat non exécuté', r.verdict === 'bloquant' && test(r, 'securite').status === 'fail' && test(r, 'chargement').status === 'fail' && !test(r, 'resultats'), r.headline);
t('…application refusée', php('apply').ok === false);

// 4. Fonction attendue par le site manquante
r = candidate((d) => edit(d, 'src/compat.php', 'function js_number_to_string(', 'function js_number_to_text('));
t('fonction manquante → bloquant', r.verdict === 'bloquant' && test(r, 'fonctions').status === 'fail' && /js_number_to_string/.test(test(r, 'fonctions').result), r.headline);

// 5. Incohérence physique (arrondi)
r = candidate((d) => edit(d, 'src/rounding.php', "return rounding_mode() === 'floor' ? floor($day) : js_round($day);", "return rounding_mode() === 'floor' ? ceil($day) : js_round($day);"));
t('bug d\'arrondi → détecté par les tests de cohérence', r.verdict === 'bloquant' && test(r, 'arrondi').status === 'fail', r.headline);

// 6. Structure de réponse cassée (champ supprimé)
r = candidate((d) => edit(d, 'src/calculate.php', "        'effective_days' => $effectiveDays,\n", ''));
t('champ supprimé de la réponse → rupture détectée', r.verdict === 'bloquant' && test(r, 'structure').status === 'fail', r.headline);

// 7. Compte rendu lisible
t('compte rendu : verdict, titres et explications présents', r.headline.length > 20 && r.tests.every((x) => x.title && x.what && x.result));

fs.rmSync(site, { recursive: true, force: true });
process.exit(failures ? 1 : 0);
