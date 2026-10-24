// Test de bout en bout de la page d'administration (connexion Google + mises à jour), contre un
// site déployé (Apache + mod_php) et tests/fake-services.js. Préparation: voir README.
// Usage: node test-admin-site.js <racine_web_du_site_de_test> <dossier_zips>
const fs = require('fs'); const path = require('path'); const crypto = require('crypto');
const WWW = process.argv[2]; const BASE = 'http://127.0.0.1:8082'; const G = 'http://127.0.0.1:9201'; const H = 'http://127.0.0.1:9202';
const TOKEN = 'ghp_validtokenvalidtokenvalid1234';
let pass = 0; let fail = 0;
const t = (name, cond, detail = '') => { if (cond) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };

class Client {
  constructor() { this.jar = {}; }
  async req(method, url, { form, headers = {}, manual = true } = {}) {
    const h = { ...headers };
    if (url.startsWith(BASE) && Object.keys(this.jar).length) h.Cookie = Object.entries(this.jar).map(([k, v]) => `${k}=${v}`).join('; ');
    const res = await fetch(url, { method, headers: h, redirect: 'manual', body: form ? new URLSearchParams(form).toString() : undefined, ...(form ? { headers: { ...h, 'Content-Type': 'application/x-www-form-urlencoded' } } : {}) });
    const raw = res.headers.getSetCookie();
    raw.forEach((c) => { const [kv] = c.split(';'); const i = kv.indexOf('='); this.jar[kv.slice(0, i)] = kv.slice(i + 1); });
    return { status: res.status, loc: res.headers.get('location'), text: await res.text(), setCookie: raw, headers: res.headers };
  }
  async reqJson(method, url, obj, headers = {}) {
    const h = { 'Content-Type': 'application/json', ...headers };
    if (Object.keys(this.jar).length) h.Cookie = Object.entries(this.jar).map(([k, v]) => `${k}=${v}`).join('; ');
    const res = await fetch(url, { method, headers: h, redirect: 'manual', body: obj === undefined ? undefined : JSON.stringify(obj) });
    return { status: res.status, text: await res.text(), headers: res.headers };
  }
  async login() {
    const a = await this.req('GET', `${BASE}/auth`);
    const b = await this.req('GET', a.loc); // Google approuve et renvoie vers le callback
    const c = await this.req('GET', b.loc);
    return { a, b, c };
  }
}
const ctl = (q) => fetch(`${G}/ctl?${q}`).then((r) => r.text());
const ghLog = () => fetch(`${H}/ctl/log`).then((r) => r.json());
const resetLimits = () => { for (const f of fs.readdirSync(path.join(WWW, 'backend/data/var'))) if (f.startsWith('ratelimit-')) fs.unlinkSync(path.join(WWW, 'backend/data/var', f)); };
const csrfOf = (html) => (html.match(/name="csrf" value="([0-9a-f]+)"/) || [])[1];
const flash = (html) => ((html.match(/<p class="msg (?:ok|ko)">([^<]*)</) || [])[1] || '').replace(/&#39;/g, "'");

function treeHash(skipRootHt = false) { // tout ce que la mise à jour a le droit de modifier
  const h = crypto.createHash('sha256'); const files = [];
  const walk = (d) => { for (const n of fs.readdirSync(d, { withFileTypes: true }).sort((x, y) => x.name.localeCompare(y.name))) {
    const p = path.join(d, n.name); const rel = path.relative(WWW, p);
    if (skipRootHt && rel === '.htaccess') continue; // l'ordre des blocs de tête peut varier sans conséquence
    if (n.name.startsWith('.update-attempts')) continue; // compteur d'essais de clé d'update.php
    if (rel === 'backend/data/var' || rel === 'backend/data/logs' || rel === 'backend/config/runtime.env') continue;
    n.isDirectory() ? walk(p) : files.push(rel);
  } };
  walk(WWW); files.forEach((f) => { h.update(f); h.update(fs.readFileSync(path.join(WWW, f))); }); return h.digest('hex');
}
const exists = (rel) => fs.existsSync(path.join(WWW, rel));
const read = (rel) => fs.readFileSync(path.join(WWW, rel), 'utf8');
const runtimeEnv = () => fs.readFileSync(path.join(WWW, 'backend/config/runtime.env'), 'utf8');
const setEnv = (key, value) => { const f = path.join(WWW, 'backend/config/runtime.env'); const lines = fs.readFileSync(f, 'utf8').split('\n').filter((l) => !l.startsWith(`${key}=`)); if (value !== null) lines.push(`${key}="${value}"`); fs.writeFileSync(f, lines.join('\n')); };
const tmpClean = () => !exists('backend/data/var/tmp') || fs.readdirSync(path.join(WWW, 'backend/data/var/tmp')).length === 0;

async function update(c, ref, token) {
  const page = await c.req('GET', `${BASE}/auth`);
  const r = await c.req('POST', `${BASE}/auth/update`, { form: { csrf: csrfOf(page.text), ref, ...(token ? { token } : {}) }, headers: { Origin: BASE } });
  const after = await c.req('GET', r.loc || `${BASE}/auth`);
  return { post: r, msg: flash(after.text), html: after.text };
}

(async () => {
  resetLimits(); await ctl('email=admin@example.org&verified=1&aud=test-client-id.apps.googleusercontent.com');
  const base = treeHash(); const envBefore = runtimeEnv();

  console.log('\n— Nom du site et copyright (lus à chaud)');
  const pub = async () => (await fetch(`${BASE}/api/public-config`)).json();
  setEnv('SITE_NAME', null); setEnv('COPYRIGHT_OWNER', null);
  let pc = await pub();
  t('par défaut: nom = domaine de SITE_PUBLIC_URL, propriétaire = nom', pc.site_name === '127.0.0.1' && pc.copyright_owner === '127.0.0.1');
  setEnv('SITE_NAME', 'Mon Site'); pc = await pub();
  t('SITE_NAME pris en compte sans redéploiement, propriétaire suit', pc.site_name === 'Mon Site' && pc.copyright_owner === 'Mon Site');
  setEnv('COPYRIGHT_OWNER', 'Association X'); pc = await pub();
  t('COPYRIGHT_OWNER distinct pris en compte', pc.site_name === 'Mon Site' && pc.copyright_owner === 'Association X');
  setEnv('SITE_NAME', null); setEnv('COPYRIGHT_OWNER', null);

  console.log('\n— Façade publique et protection du backend');
  const denied = await Promise.all(['backend/config/runtime.env', 'backend/src/app.php', 'backend/src/lib/config.php', 'backend/data/var/version.json', 'backend/data/logs/updates.jsonl', 'backend/assets/leaflet.js', 'backend/.htaccess', 'backend/', 'backend'].map((u) => fetch(`${BASE}/${u}`).then((x) => x.status)));
  t('backend illisible depuis le web (config, code, données, ressources)', denied.every((x) => x === 403 || x === 404), denied.join());
  const guardOriginal = read('backend/.htaccess'); fs.unlinkSync(path.join(WWW, 'backend/.htaccess')); await fetch(`${BASE}/api/config`);
  t('.htaccess du backend recréé s\'il manque', exists('backend/.htaccess') && /Require all denied/.test(read('backend/.htaccess')));
  fs.writeFileSync(path.join(WWW, 'backend/.htaccess'), guardOriginal);
  const pointerFile = path.join(WWW, 'api/backend.php');
  fs.writeFileSync(pointerFile, "<?php return '/chemin/inexistant';\n");
  let fr = await fetch(`${BASE}/api/config`); t('backend introuvable (pointeur invalide) → 404 JSON générique', fr.status === 404 && /Not found/.test(await fr.text()));
  fs.writeFileSync(pointerFile, `<?php return '${path.join(WWW, 'backend')}';\n`);
  fr = await fetch(`${BASE}/api/config`); t('pointeur api/backend.php valide → l\'API répond', fr.status === 200 && (await fr.json()).isotopes.length > 0);
  fs.unlinkSync(pointerFile);
  fr = await fetch(`${BASE}/api/config`); t('sans pointeur: backend par défaut <racine>/backend', fr.status === 200);
  t('seule la façade est publique dans api/', fs.readdirSync(path.join(WWW, 'api')).filter((f) => f !== '.htaccess').join() === 'index.php');

  { // Précaution PHP2Shell (octobre 2026) : les envois multipart, qui déclenchent les failles, sont refusés avant PHP
    const fd = new FormData(); fd.append('a', 'b');
    const st = async (u, m) => (await fetch(`${BASE}${u}`, { method: m, body: fd })).status;
    t('envois multipart refusés (403) sur l\'API, jamais traités par PHP', (await st('/api/calculate', 'POST')) === 403 && (await st('/api/contact', 'POST')) === 403 && (await st('/api/config', 'PUT')) === 403);
    { const body = (extra) => JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, ...extra });
      const call = async (extra) => (await fetch(`${BASE}/api/calculate`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: BASE }, body: body(extra) })).json();
      const [plain, sfmn, local, weird] = [await call({}), await call({ calculation_mode: 'sfmn' }), await call({ calculation_mode: 'local' }), await call({ calculation_mode: { x: 1 } })];
      t('calculation_mode ignoré sans erreur (sfmn, local, valeur étrange): même réponse que sans', plain.ok === true && JSON.stringify(plain) === JSON.stringify(sfmn) && JSON.stringify(plain) === JSON.stringify(local) && JSON.stringify(plain) === JSON.stringify(weird));
      t('réponse sans champ calculation_mode ni mention dans les champs attendus', plain.calculation_mode === undefined && !JSON.stringify(await call({ isotope_code: 'x' })).includes('calculation_mode'));
      const cfg = await (await fetch(`${BASE}/api/config`)).json();
      t('GET /api/config sans mode de calcul ni adresse de l\'outil SFMN', cfg.calculation_modes === undefined && cfg.default_calculation_mode === undefined && cfg.sfmn_calculator_url === undefined); }
    t('…les requêtes normales (JSON, GET) passent', (await fetch(`${BASE}/api/config`)).status === 200 && (await fetch(`${BASE}/api/calculate`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: BASE }, body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 'local' }) })).status === 200);
  }

  console.log('\n— Accès sans connexion');
  let c = new Client();
  const entry = await c.req('GET', `${BASE}/auth`);
  t('GET /auth sans session → redirection vers Google', entry.status === 302 && entry.loc.startsWith(G));
  t('GET /auth/mesures sans session → 404', (await c.req('GET', `${BASE}/auth/mesures`)).status === 404);
  t('GET /auth/mesures.csv sans session → 404', (await c.req('GET', `${BASE}/auth/mesures.csv`)).status === 404);
  const oldUrls = ['site', 'login', 'measurements', 'measurements.csv', 'measurements/periods'].map((x) => `${BASE}/api/admin/${x}`);
  t('anciennes adresses /api/admin/* (même avec l\'ancien jeton) → 404', (await Promise.all(oldUrls.map((u) => fetch(u, { headers: { 'X-Admin-Token': 'secret-test' } }).then((r) => r.status)))).every((st) => st === 404));
  t('POST /update sans session → 404', (await c.req('POST', `${BASE}/auth/update`, { form: { ref: 'main' } })).status === 404);
  t('POST /logout sans session → 404', (await c.req('POST', `${BASE}/auth/logout`, { form: {} })).status === 404);

  console.log('\n— Connexion Google: refus');
  for (const [label, q] of [['adresse non autorisée', 'email=intrus@example.org'], ['adresse non vérifiée', 'email=admin@example.org&verified=0'], ['mauvaise audience', 'email=admin@example.org&verified=1&aud=autre-client']]) {
    await ctl(q); c = new Client(); const { c: cb } = await c.login();
    t(`${label} → 403`, cb.status === 403); t(`${label}: pas de session`, (await c.req('GET', `${BASE}/auth/mesures`)).status === 404 && (await c.req('GET', `${BASE}/auth/mesures.csv`)).status === 404);
  }
  await ctl('email=admin@example.org&verified=1&aud=test-client-id.apps.googleusercontent.com');
  resetLimits(); c = new Client(); const a = await c.req('GET', `${BASE}/auth`); const b = await c.req('GET', a.loc);
  t('state falsifié → 403', (await c.req('GET', b.loc.replace(/state=[^&]+/, 'state=faux'))).status === 403);
  resetLimits(); c = new Client(); const a2 = await c.req('GET', `${BASE}/auth`); const b2 = await c.req('GET', a2.loc);
  t('callback sans cookie de session → 403', (await new Client().req('GET', b2.loc)).status === 403);
  t('callback valide → 303', (await c.req('GET', b2.loc)).status === 303);
  t('rejeu du même callback → 403', (await c.req('GET', b2.loc)).status === 403);

  console.log('\n— Connexion Google: succès');
  resetLimits(); c = new Client(); const lg = await c.login();
  t('URL Google: PKCE S256 + nonce + state', /code_challenge_method=S256/.test(lg.a.loc) && /nonce=/.test(lg.a.loc) && /state=/.test(lg.a.loc));
  t('connexion acceptée → 303 vers la page', lg.c.status === 303 && /\/auth$/.test(lg.c.loc));
  const cookie = lg.c.setCookie.join(' | ');
  t('cookie HttpOnly + SameSite=Lax + Path=/auth', /HttpOnly/i.test(cookie) && /SameSite=Lax/i.test(cookie) && /path=\/auth/i.test(cookie), cookie);
  let page = await c.req('GET', `${BASE}/auth`);
  t('page accessible', page.status === 200 && /Administration du site/.test(page.text));
  t('CSP stricte + no-store', /default-src 'none'/.test(page.headers.get('content-security-policy') || '') && /no-store/.test(page.headers.get('cache-control') || ''));
  t("e-mail de l'administrateur affiché", page.text.includes('admin@example.org'));
  t('champ jeton affiché (aucun jeton dans le .env)', /name="token"/.test(page.text));
  const visible = page.text.replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]*>/g, ' ').replace(/Dépôt : \S+/, '').replace(/Jeton d'accès/g, '');
  t("aucune explication sur le jeton dans l'interface", !/github|jeton|token|requis|nécessaire/i.test(visible), visible.slice(0, 300));

  console.log('\n— Mise à jour: jeton');
  let r = await update(c, 'v1.1', 'ghp_mauvaisjetonmauvaisjeton12345');
  t('jeton invalide → refus', /jeton n'est pas valide/.test(r.msg), r.msg); t('site inchangé', treeHash() === base);
  r = await update(c, 'v1.1', 'trop-court');
  t('jeton de forme invalide → refus sans appel GitHub', /jeton n'est pas valide/.test(r.msg));

  console.log('\n— Mise à jour: succès (v1.1)');
  await ghLog(); resetLimits();
  r = await update(c, 'v1.1', TOKEN);
  t('installée', /Mise à jour installée : 1111111/.test(r.msg), r.msg);
  t('app.js remplacé', read('app.js').includes('fixture v1.1')); t('nouveau fichier créé', exists('downloads/ajout-v1_1.xml'));
  const version = JSON.parse(read('backend/data/var/version.json'));
  t('version enregistrée (sha, ref, par)', version.sha === '1'.repeat(40) && version.ref === 'v1.1' && version.by === 'admin@example.org');
  const lu = (await (await fetch(`${BASE}/api/public-config`)).json()).last_updated;
  t('public-config.last_updated suit la date de la mise à jour', /^\d{4}-\d{2}-\d{2}$/.test(lu) && lu === new Date().toLocaleDateString('sv'), lu);
  const log = await ghLog();
  t("jeton envoyé à l'API GitHub", log.filter((l) => l.path.startsWith('/repos')).every((l) => l.auth === 'present'));
  t('jeton NON transmis au téléchargement redirigé', log.filter((l) => l.path.startsWith('/codeload')).every((l) => l.auth === 'absent') && log.some((l) => l.path.startsWith('/codeload')));
  t('archive et préparation supprimées', tmpClean());
  t('.runtime.env intact', runtimeEnv() === envBefore); t('config.js / .htaccess racine intacts', read('config.js').includes('RADIOPROTECTION') && read('.htaccess').includes('RewriteEngine'));
  t('jeton jamais écrit sur le disque', !fs.readdirSync(path.join(WWW, 'backend/data/var')).some((f) => !fs.statSync(path.join(WWW, 'backend/data/var', f)).isDirectory() && read(`backend/data/var/${f}`).includes(TOKEN)) && !fs.readdirSync(path.join(WWW, 'backend/data/logs')).some((f) => read(`backend/data/logs/${f}`).includes(TOKEN)));
  const journal = fs.readFileSync(path.join(WWW, 'backend/data/logs/updates.jsonl'), 'utf8');
  t('opération journalisée', /"action":"update".*"ok":true/.test(journal));
  r = await update(c, 'v1.1', TOKEN); t('même version → déjà installée', /déjà installée/.test(r.msg), r.msg);

  console.log('\n— Archives piégées (le site ne doit pas changer)');
  const afterGood = treeHash();
  for (const [ref, expect, label] of [['t3', /chemin invalide/, 'traversée de chemin (../)'], ['t4', /lien symbolique/, 'lien symbolique'], ['t5', /syntaxe PHP/, 'erreur de syntaxe PHP'], ['t6', /ne correspondant pas/, 'mauvais dossier racine'], ['t7', /incomplète ou trop ancienne/, 'version sans code d\'administration'], ['t8', /taille/, 'fichier trop gros (bombe)'], ['v1.0', /incomplète ou trop ancienne/, 'tag trop ancien'], ['inexistant', /introuvable/, 'référence inexistante'], ['../etc', /invalide/, 'référence malformée']]) {
    resetLimits(); r = await update(c, ref, TOKEN);
    t(`${label} → refusé`, !/installée/.test(r.msg) && expect.test(r.msg), r.msg); t(`${label}: site inchangé`, treeHash() === afterGood); t(`${label}: préparation nettoyée`, tmpClean());
  }
  t('traversée: aucun fichier hors du site', !fs.existsSync(path.join(WWW, '..', 'evil.php')) && !exists('evil.php'));

  console.log('\n— Retour arrière automatique (la nouvelle version ne répond pas)');
  resetLimits(); r = await update(c, 't9', TOKEN);
  t("échec signalé + ancienne version rétablie", /rétablie automatiquement/.test(r.msg), r.msg); t('site restauré à l\'identique', treeHash() === afterGood);
  t('version inchangée', JSON.parse(read('backend/data/var/version.json')).sha === '1'.repeat(40));
  t('API opérationnelle après retour arrière', (await fetch(`${BASE}/api/config`)).status === 200);

  console.log('\n— Mise à jour v1.2 puis retour arrière manuel');
  resetLimits(); r = await update(c, 'main', TOKEN);
  t('v1.2 installée', /2222222/.test(r.msg), r.msg); t('app.js en v1.2', read('app.js').includes('fixture v1.2')); t('fichier retiré par la mise à jour', !exists('downloads/ajout-v1_1.xml'));
  page = await c.req('GET', `${BASE}/auth`); t('bouton de retour arrière proposé', /Rétablir la version précédente/.test(page.text));
  const rb = await c.req('POST', `${BASE}/auth/rollback`, { form: { csrf: csrfOf(page.text) }, headers: { Origin: BASE } });
  const rbPage = await c.req('GET', rb.loc);
  t('retour arrière effectué', /Version précédente rétablie/.test(flash(rbPage.text)), flash(rbPage.text));
  t('état exactement identique à la v1.1', treeHash() === afterGood); t('version = v1.1', JSON.parse(read('backend/data/var/version.json')).sha === '1'.repeat(40));
  page = await c.req('GET', `${BASE}/auth`); t('plus de retour arrière possible', !/Rétablir la version précédente/.test(page.text));

  console.log('\n— Moteur de calcul : zone de test, épinglage');
  t('GET /auth/moteur sans session → 404', (await new Client().req('GET', `${BASE}/auth/moteur`)).status === 404);
  t('POST /auth/moteur/appliquer sans session → 404', (await new Client().req('POST', `${BASE}/auth/moteur/appliquer`, { form: {} })).status === 404);
  page = await c.req('GET', `${BASE}/auth/moteur`);
  t('page Moteur: moteur en service, formulaire de téléchargement', page.status === 200 && /Moteur en service/.test(page.text) && /Télécharger un candidat/.test(page.text), page.text.slice(0, 200));
  t('onglet Moteur dans la navigation', /href="\/auth\/moteur"/.test(page.text));
  { const versionFile = path.join(WWW, 'backend/core/VERSION'); const pinFile = path.join(WWW, 'backend/data/var/core-pin.json');
    const original = fs.readFileSync(versionFile, 'utf8');
    fs.writeFileSync(versionFile, '9.9.9\n'); fs.writeFileSync(pinFile, '{"version":"9.9.9"}');
    page = await c.req('GET', `${BASE}/auth/moteur`); t('moteur épinglé affiché', /épinglé/.test(page.text) && /Retirer l'épinglage/.test(page.text));
    resetLimits(); r = await update(c, 'main', TOKEN);
    t('moteur épinglé: la mise à jour du site ne le touche pas', /2222222/.test(r.msg) && fs.readFileSync(versionFile, 'utf8') === '9.9.9\n' && read('app.js').includes('fixture v1.2'), r.msg);
    page = await c.req('GET', `${BASE}/auth`);
    const rb2 = await c.req('POST', `${BASE}/auth/rollback`, { form: { csrf: csrfOf(page.text) }, headers: { Origin: BASE } });
    t('…ni le retour arrière', fs.readFileSync(versionFile, 'utf8') === '9.9.9\n' && /Version précédente rétablie/.test(flash((await c.req('GET', rb2.loc)).text)));
    fs.unlinkSync(pinFile); fs.writeFileSync(versionFile, original);
    t('état revenu à la v1.1', treeHash() === afterGood);
    fs.writeFileSync(versionFile, '9.9.9\n');
    resetLimits(); r = await update(c, 'main', TOKEN);
    t('sans épinglage: la mise à jour réinstalle le moteur de la version', /2222222/.test(r.msg) && fs.readFileSync(versionFile, 'utf8') === original, r.msg);
    page = await c.req('GET', `${BASE}/auth`);
    await c.req('POST', `${BASE}/auth/rollback`, { form: { csrf: csrfOf(page.text) }, headers: { Origin: BASE } });
    fs.writeFileSync(versionFile, original);
    t('état de nouveau identique à la v1.1', treeHash() === afterGood);
  }

  console.log('\n— Maintenance (accueil seul, puis complète)');
  { t('POST /auth/maintenance sans session → 404', (await new Client().req('POST', `${BASE}/auth/maintenance`, { form: { mode: 'full' } })).status === 404);
    const setMode = async (mode) => { resetLimits(); const pg = await c.req('GET', `${BASE}/auth`); const r = await c.req('POST', `${BASE}/auth/maintenance`, { form: { csrf: csrfOf(pg.text), mode }, headers: { Origin: BASE } }); return flash((await c.req('GET', r.loc || `${BASE}/auth`)).text); };
    const calc = () => fetch(`${BASE}/api/calculate`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: BASE }, body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150 }) });
    page = await c.req('GET', `${BASE}/auth`);
    t('carte Maintenance: état « en service » et boutons', /Maintenance<\/h2>/.test(page.text) && /en service/.test(page.text) && /Activer la maintenance/.test(page.text) && /Maintenance complète/.test(page.text));
    let home = await fetch(`${BASE}/`); t('hors maintenance: accueil normal (200)', home.status === 200 && !/<title>Site en maintenance/.test(await home.text()));
    let msg = await setMode('site'); t('maintenance activée', /Maintenance activée/.test(msg), msg);
    home = await fetch(`${BASE}/`); const html = await home.text();
    t('accueil: 503, avis de maintenance et lien vers l\'outil de la SFMN', home.status === 503 && /Site en maintenance/.test(html) && /acoramen\.net/.test(html) && home.headers.get('retry-after') === '3600' && !/id="doseRate"/.test(html), `${home.status}`);
    t('…mentions légales toujours servies', (await fetch(`${BASE}/legal`)).status === 200);
    let r2 = await calc(); t('maintenance simple: l\'API de calcul continue de fonctionner', r2.status === 200 && (await r2.json()).ok === true);
    t('…/api/config annonce la maintenance', (await (await fetch(`${BASE}/api/config`)).json()).maintenance === 'site');
    page = await c.req('GET', `${BASE}/auth`); t('…carte: état maintenance, depuis quand et par qui', /maintenance \(accueil\)/.test(page.text) && /admin@example\.org/.test(page.text));
    msg = await setMode('full'); t('maintenance complète activée', /Maintenance complète activée/.test(msg), msg);
    r2 = await calc(); const body2 = await r2.json();
    t('maintenance complète: calcul → 503 MAINTENANCE avec Retry-After', r2.status === 503 && body2.ok === false && body2.error.code === 'MAINTENANCE' && r2.headers.get('retry-after') === '3600');
    t('…formulaire de contact → 503', (await fetch(`${BASE}/api/contact`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: BASE }, body: '{}' })).status === 503);
    t('…/health, /api/config et /auth restent disponibles', (await fetch(`${BASE}/health`)).status === 200 && (await fetch(`${BASE}/api/config`)).status === 200 && (await c.req('GET', `${BASE}/auth`)).status === 200);
    t('…accueil toujours en maintenance', (await fetch(`${BASE}/`)).status === 503);
    msg = await setMode('off'); t('remise en service', /remis en service/.test(msg), msg);
    r2 = await calc(); home = await fetch(`${BASE}/`);
    t('en service: calcul 200, accueil 200, plus d\'annonce dans /api/config', r2.status === 200 && home.status === 200 && (await (await fetch(`${BASE}/api/config`)).json()).maintenance === undefined);
    t('…fichier d\'état supprimé', !exists('backend/data/var/maintenance.json')); }

  console.log('\n— Fournisseurs de l\'API et clés d\'API (clé obligatoire)');
  { resetLimits();
    const regFile = path.join(WWW, 'backend/config/providers.json');
    const calc = (headers = {}, extra = '') => fetch(`${BASE}/api/calculate${extra}`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 'local', provider: 'MonRIS 2.1' }) }).then(async (x) => ({ status: x.status, body: await x.json().catch(() => ({})) }));
    const adminPost = async (action, form) => { const pg = await c.req('GET', `${BASE}/auth/fournisseurs`); return c.req('POST', `${BASE}/auth/fournisseurs/${action}`, { form: { csrf: csrfOf(pg.text), ...form }, headers: { Origin: BASE } }); };
    let pg = await c.req('GET', `${BASE}/auth/fournisseurs`);
    t('onglet Fournisseurs (liste vide, formulaire de création)', pg.status === 200 && /Nouveau fournisseur/.test(pg.text) && /Aucun fournisseur/.test(pg.text) && /<a class="tab on"[^>]*>Fournisseurs/.test(pg.text));
    t('sans session : onglet inaccessible (404 générique)', (await fetch(`${BASE}/auth/fournisseurs`)).status === 404);
    let r = await c.req('POST', `${BASE}/auth/fournisseurs/creer`, { form: { id: 'monris', name: 'MonRIS' }, headers: { Origin: BASE } });
    t('création sans jeton CSRF → refusée', r.status === 403 && !exists('backend/config/providers.json'));
    r = await adminPost('creer', { id: 'Mauvais Id!', name: 'x' }); t('identifiant invalide refusé', /Identifiant invalide/.test(flash((await c.req('GET', r.loc)).text)) || /Identifiant invalide/.test((await c.req('GET', `${BASE}/auth/fournisseurs`)).text));
    r = await adminPost('creer', { id: 'monris', name: 'MonRIS <b>CHU</b>' }); await c.req('GET', r.loc);
    r = await adminPost('cle', { id: 'monris', days: '30' });
    const keyPage = (await c.req('GET', r.loc)).text; const KEY = (keyPage.match(/rdv_[0-9a-f]{8}_[A-Za-z0-9_-]{43}/) || [])[0];
    t('clé générée et affichée une seule fois', !!KEY && /ne sera plus jamais affichée/.test(keyPage) && !/rdv_[0-9a-f]{8}_[A-Za-z0-9_-]{43}/.test((await c.req('GET', `${BASE}/auth/fournisseurs`)).text));
    const reg = fs.readFileSync(regFile, 'utf8');
    t('registre : empreinte SHA-256 seulement, jamais la clé, droits 600, nom assaini', !reg.includes(KEY) && reg.includes(crypto.createHash('sha256').update(KEY).digest('hex')) && (fs.statSync(regFile).mode & 0o077) === 0 && !reg.includes('<b>'));
    t('registre et dossier de configuration illisibles depuis le web', (await fetch(`${BASE}/backend/config/providers.json`)).status !== 200);
    let x = await calc(); t('par défaut : clé facultative, appel extérieur sans clé accepté', x.status === 200 && x.body.ok === true);
    x = await calc({ 'X-API-Key': KEY.slice(0, -1) + (KEY.endsWith('A') ? 'B' : 'A') }); t('…mais une clé invalide reste refusée', x.status === 401 && x.body.code === 'API_KEY_INVALID');
    t('onglet : état « facultative » et bouton pour la rendre obligatoire', /badge bad">facultative/.test((await c.req('GET', `${BASE}/auth/fournisseurs`)).text) && /Rendre la clé obligatoire/.test((await c.req('GET', `${BASE}/auth/fournisseurs`)).text));
    r = await c.req('POST', `${BASE}/auth/fournisseurs/obligation`, { form: { required: '1' }, headers: { Origin: BASE } }); t('changement sans jeton CSRF refusé', r.status === 403);
    r = await adminPost('obligation', { required: '1' }); await c.req('GET', r.loc);
    t('clé rendue obligatoire depuis le backend', /badge good">obligatoire/.test((await c.req('GET', `${BASE}/auth/fournisseurs`)).text) && JSON.parse(fs.readFileSync(regFile, 'utf8')).required === true);
    x = await calc(); t('appel extérieur sans clé → 401 API_KEY_REQUIRED', x.status === 401 && x.body.code === 'API_KEY_REQUIRED' && x.body.ok === false);
    x = await calc({ 'X-API-Key': KEY }); t('avec la clé (X-API-Key) → 200', x.status === 200 && x.body.ok === true);
    x = await calc({ Authorization: `Bearer ${KEY}` }); t('avec la clé (Authorization: Bearer) → 200', x.status === 200 && x.body.ok === true);
    x = await calc({}, `?api_key=${KEY}`); t('clé dans l\'adresse ignorée → 401', x.status === 401);
    x = await calc({ 'X-API-Key': KEY.slice(0, -1) + (KEY.endsWith('A') ? 'B' : 'A') }); t('clé altérée → 401 API_KEY_INVALID', x.status === 401 && x.body.code === 'API_KEY_INVALID');
    x = await calc({ 'X-API-Key': 'n\'importe quoi' }); t('clé de forme invalide → 401 API_KEY_INVALID', x.status === 401 && x.body.code === 'API_KEY_INVALID');
    x = await calc({ Origin: BASE }); t('pages du site : dispensées de clé', x.status === 200);
    x = await calc({ Origin: 'https://evil.example' }); t('autre origine sans clé → 401', x.status === 401);
    x = await calc({ Origin: 'https://evil.example', 'X-API-Key': KEY }); t('autre origine avec clé valide → 200', x.status === 200);
    const pre = await fetch(`${BASE}/api/calculate`, { method: 'OPTIONS', headers: { Origin: 'https://exemple.org', 'Access-Control-Request-Headers': 'x-api-key,content-type' } });
    t('préalable CORS : X-API-Key autorisé', pre.status === 204 && /X-API-Key/i.test(pre.headers.get('access-control-allow-headers') || ''));
    await new Promise((ok) => setTimeout(ok, 700));
    const lf = path.join(WWW, 'backend/data/logs', `measurements-${new Date().toISOString().slice(0, 7)}.jsonl`);
    const rows = fs.readFileSync(lf, 'utf8').trim().split('\n').map((l) => { try { return JSON.parse(l); } catch (e) { return null; } }).filter(Boolean);
    const mine = rows.filter((w) => w.provider_id === 'monris'); const site = rows.filter((w) => w.provider_id === '127.0.0.1');
    t('journal : fournisseur identifié (provider_id) distinct du nom déclaré (provider)', mine.length >= 3 && mine.every((w) => w.provider === 'MonRIS 2.1') && site.length >= 1);
    t('journal : aucune clé écrite', !fs.readFileSync(lf, 'utf8').includes(KEY) && !fs.readFileSync(path.join(WWW, 'backend/data/logs/updates.jsonl'), 'utf8').includes(KEY));
    const mes = (await c.req('GET', `${BASE}/auth/mesures?days=1&q=monris`)).text;
    t('onglet Mesures : colonne Origine avec le fournisseur identifié', /<strong>monris<\/strong>/.test(mes));
    t('page Site : zone Configuration signale les clés obligatoires et 1 fournisseur actif', /Clés d'API : obligatoires, 1 fournisseur\(s\) actif\(s\)/.test((await c.req('GET', `${BASE}/auth`)).text));
    // Plafond propre au fournisseur
    await adminPost('creer', { id: 'lent', name: 'Plafonné', rate: '2' }); r = await adminPost('cle', { id: 'lent', days: '' });
    const LENT = ((await c.req('GET', r.loc)).text.match(/rdv_[0-9a-f]{8}_[A-Za-z0-9_-]{43}/) || [])[0];
    resetLimits(); const codes = []; for (let i = 0; i < 4; i++) codes.push((await calc({ 'X-API-Key': LENT })).status);
    t('plafond par fournisseur (2/min) → 429 au-delà, sans gêner les autres', codes.join() === '200,200,429,429' && (await calc({ 'X-API-Key': KEY })).status === 200, codes.join());
    // Révocation, suspension
    pg = (await c.req('GET', `${BASE}/auth/fournisseurs`)).text; const prefix = KEY.split('_')[1];
    r = await adminPost('revoquer', { id: 'monris', prefix }); resetLimits();
    x = await calc({ 'X-API-Key': KEY }); t('clé révoquée → refusée immédiatement', x.status === 401 && x.body.code === 'API_KEY_INVALID' && /révoquée/.test((await c.req('GET', `${BASE}/auth/fournisseurs`)).text));
    r = await adminPost('cle', { id: 'monris', days: '' }); const KEY2 = ((await c.req('GET', r.loc)).text.match(/rdv_[0-9a-f]{8}_[A-Za-z0-9_-]{43}/) || [])[0];
    t('rotation : nouvelle clé valable', (await calc({ 'X-API-Key': KEY2 })).status === 200);
    r = await adminPost('etat', { id: 'monris', status: 'suspended' }); await c.req('GET', r.loc); resetLimits();
    t('fournisseur suspendu → toutes ses clés refusées', (await calc({ 'X-API-Key': KEY2 })).status === 401);
    r = await adminPost('etat', { id: 'monris', status: 'active' }); await c.req('GET', r.loc);
    t('réactivé → la clé refonctionne', (await calc({ 'X-API-Key': KEY2 })).status === 200);
    // Expiration
    const regObj = JSON.parse(fs.readFileSync(regFile, 'utf8')); regObj.providers.find((q) => q.id === 'monris').keys.find((k) => !k.revoked).expires = '2020-01-01'; fs.writeFileSync(regFile, JSON.stringify(regObj));
    x = await calc({ 'X-API-Key': KEY2 }); t('clé expirée → 401', x.status === 401);
    // Limitation des essais de clés
    resetLimits(); const bad = []; for (let i = 0; i < 22; i++) bad.push((await calc({ 'X-API-Key': 'rdv_00000000_' + 'a'.repeat(43) })).status);
    t('essais de clés limités (429 après 20 refus par minute)', bad[0] === 401 && bad[bad.length - 1] === 429, bad.join());
    // Obligation désactivée depuis le backend, puis variable d'environnement
    resetLimits(); r = await adminPost('obligation', { required: '0' }); await c.req('GET', r.loc);
    x = await calc(); t('obligation désactivée depuis le backend : appel sans clé accepté', x.status === 200 && JSON.parse(fs.readFileSync(regFile, 'utf8')).required === false);
    t('…signalé dans la zone Configuration', /Clés d'API : <strong>facultatives/.test((await c.req('GET', `${BASE}/auth`)).text) && /Choix fait ici/.test((await c.req('GET', `${BASE}/auth/fournisseurs`)).text));
    t('…les fournisseurs et clés sont conservés', JSON.parse(fs.readFileSync(regFile, 'utf8')).providers.length === 2);
    setEnv('API_KEY_REQUIRED', 'true'); x = await calc(); t('le choix du backend l\'emporte sur API_KEY_REQUIRED=true', x.status === 200);
    { const o = JSON.parse(fs.readFileSync(regFile, 'utf8')); delete o.required; fs.writeFileSync(regFile, JSON.stringify(o)); }
    x = await calc(); t('sans choix du backend : API_KEY_REQUIRED=true s\'applique', x.status === 401 && x.body.code === 'API_KEY_REQUIRED');
    setEnv('API_KEY_REQUIRED', null); x = await calc(); t('sans choix ni variable : facultative', x.status === 200);
    fs.rmSync(regFile, { force: true }); resetLimits(); }

  console.log('\n— Origine des requêtes API (provider)');
  { const lf = path.join(WWW, 'backend/data/logs', `measurements-${new Date().toISOString().slice(0, 7)}.jsonl`);
    const post = async (extra) => { await fetch(`${BASE}/api/calculate`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: BASE }, body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 'local', ...extra }) }); await new Promise((ok) => setTimeout(ok, 700)); const l = fs.readFileSync(lf, 'utf8').trim().split('\n'); return JSON.parse(l[l.length - 1]); };
    resetLimits();
    let row = await post({ provider: 'MonRIS 2.1 <script>"x"</script>' });
    t('provider enregistré, assaini (caractères hors jeu sûr retirés)', row.provider === 'MonRIS 2.1 scriptx/script', JSON.stringify(row.provider));
    row = await post({ provider: 'radiv.fr' }); t('provider du site', row.provider === 'radiv.fr' && row.input.provider === undefined);
    row = await post({ provider: 'x'.repeat(200) }); t('provider borné à 64 caractères', row.provider.length === 64);
    row = await post({}); t('sans provider : champ absent (non déclaré)', !('provider' in row));
    row = await post({ provider: { a: 1 } }); t('provider non textuel ignoré', !('provider' in row));
    const resp = await (await fetch(`${BASE}/api/calculate`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: BASE }, body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 'local', provider: 'abc' }) })).json();
    t('réponse de l\'API inchangée par le provider', resp.ok === true);
    const mes = (await c.req('GET', `${BASE}/auth/mesures?days=1&q=radiv.fr`)).text;
    t('onglet Mesures : colonne Origine et recherche par provider', /<th>Origine<\/th>/.test(mes) && mes.includes('radiv.fr'));
    t('app.js du site envoie provider', /provider: window\.RADIOPROTECTION_SITE_NAME/.test(read('app.js')));
    resetLimits(); }

  console.log('\n— Espace disque : purge des journaux les plus anciens sous 30 Mo libres');
  const envDisk = runtimeEnv(); const logsDir = path.join(WWW, 'backend/data/logs');
  const treeBytes = (d) => fs.readdirSync(d, { withFileTypes: true }).reduce((n, e) => n + (e.isDirectory() ? treeBytes(path.join(d, e.name)) : fs.statSync(path.join(d, e.name)).size), 0);
  for (const f of ['measurements-2025-01.jsonl', 'measurements-2025-02.jsonl']) fs.writeFileSync(path.join(logsDir, f), 'x'.repeat(250 * 1024));
  const quotaMb = Math.ceil(treeBytes(WWW) / 1048576) + 10; // il reste ≈ 10 Mo libres, sous le minimum de 30 Mo
  setEnv('DISK_QUOTA_MB', String(quotaMb)); resetLimits(); try { fs.unlinkSync(path.join(logsDir, '.last-disk-check')); } catch (e) { /* absent */ }
  const diskPage = await c.req('GET', `${BASE}/auth`);
  t('page Site : occupation du quota affichée', /Espace disque/.test(diskPage.text) && /quota de l'hébergement/.test(diskPage.text) && /\d+(,\d)? %/.test(diskPage.text) && /libres/.test(diskPage.text) && /Sous 30 Mo libres/.test(diskPage.text));
  t('page Site : zone « Configuration lue » (groupes, champs facultatifs non définis)', /Configuration lue/.test(diskPage.text) && /Connexion Google \(\/auth\)\s*:\s*complet/.test(diskPage.text) && /Réglages facultatifs non définis/.test(diskPage.text));
  { const e0 = runtimeEnv(); setEnv('SMTP_HOST', null); setEnv('RECAPTCHA_SECRET_KEY', null);
    const cfgPage = (await c.req('GET', `${BASE}/auth`)).text;
    t('champs manquants listés avec leur conséquence', /Formulaire de contact<\/strong> — manque : [^\n]*<code>RECAPTCHA_SECRET_KEY<\/code>, <code>SMTP_HOST<\/code>/.test(cfgPage) && /envoi des messages refusé/.test(cfgPage) && !/RECAPTCHA_SECRET_KEY<\/code> valeur/.test(cfgPage), cfgPage.replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]*>/g, ' ').match(/Configuration lue[\s\S]{0,700}/)?.[0]);
    fs.writeFileSync(path.join(WWW, 'backend/config/runtime.env'), e0); }
  const before = fs.readdirSync(logsDir).filter((f) => f.startsWith('measurements-')).sort();
  const diskCalc = await fetch(`${BASE}/api/calculate`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: BASE }, body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 'local' }) });
  await new Promise((ok) => setTimeout(ok, 800)); // la purge s'exécute après l'envoi de la réponse
  const after = fs.readdirSync(logsDir).filter((f) => f.startsWith('measurements-')).sort();
  t('calcul normal malgré l\'espace saturé', diskCalc.status === 200);
  t('les journaux les plus anciens sont supprimés en premier', before.includes('measurements-2025-01.jsonl') && !after.includes('measurements-2025-01.jsonl'), `${before.join()} → ${after.join()}`);
  t('le mois en cours est conservé', after.some((f) => f === `measurements-${new Date().toISOString().slice(0, 7)}.jsonl`));
  t('opération journalisée (disk_purge)', /"action":"disk_purge"/.test(fs.readFileSync(path.join(logsDir, 'updates.jsonl'), 'utf8')));
  fs.writeFileSync(path.join(WWW, 'backend/config/runtime.env'), envDisk);
  for (const f of fs.readdirSync(logsDir)) if (/^measurements-2025-/.test(f)) fs.unlinkSync(path.join(logsDir, f));
  resetLimits();

  console.log('\n— update.php (mise à jour de secours autonome, idempotente, qui se supprime)');
  const UKEY = 'cle-de-test-update-0123456789abcdef';
  const dropUpdate = (key = UKEY) => { fs.writeFileSync(path.join(WWW, 'update.php'), fs.readFileSync(path.join(__dirname, '..', 'update.php'), 'utf8').replace("'CHANGEZ-MOI'", `'${key}'`).replace("const GITHUB_API = 'https://api.github.com';", "const GITHUB_API = 'http://127.0.0.1:9202';")); fs.chmodSync(path.join(WWW, 'update.php'), 0o644); };
  const up = (fields) => fetch(`${BASE}/update.php`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields) }).then(async (x) => ({ status: x.status, text: await x.text() }));
  const noTmp = () => !fs.readdirSync(WWW).some((n) => n.startsWith('.update-tmp') || n.startsWith('.install-tmp'));
  dropUpdate('CHANGEZ-MOI'); let u = await fetch(`${BASE}/update.php`); t('clé non personnalisée → 403', u.status === 403);
  dropUpdate('courte'); u = await fetch(`${BASE}/update.php`); t('clé trop courte → 403', u.status === 403);
  dropUpdate(); resetLimits(); u = await fetch(`${BASE}/update.php`); const uForm = await u.text();
  t('formulaire affiché (clé, version, jeton)', u.status === 200 && /name="key"/.test(uForm) && /name="ref"/.test(uForm) && /name="token"/.test(uForm));
  t('état affiché : backend, dépôt, version installée', uForm.includes(path.join(WWW, 'backend')) && uForm.includes('giceyBpro/Radiv') && uForm.includes('1111111'));
  const treeBefore = treeHash();
  u = await up({ key: 'mauvaise-cle', ref: 'main', token: TOKEN }); t('mauvaise clé → 403, site inchangé, fichier conservé', u.status === 403 && treeHash() === treeBefore && exists('update.php'));
  resetLimits(); u = await up({ key: UKEY, ref: 'main', token: 'ghp_mauvaisjetonmauvaisjeton12345' }); t('jeton refusé → échec, fichier conservé, site inchangé', u.status === 500 && /jeton n(?:'|&#0?39;)est pas valide/.test(u.text) && exists('update.php') && treeHash() === treeBefore && noTmp());
  resetLimits(); u = await up({ key: UKEY, ref: '../x', token: TOKEN }); t('version invalide → refusée, fichier conservé', u.status === 400 && /Version invalide/.test(u.text) && exists('update.php'));
  resetLimits(); u = await up({ key: UKEY, ref: 't3', token: TOKEN }); t('archive piégée → échec, site inchangé, fichier conservé, aucun résidu', u.status === 500 && exists('update.php') && treeHash() === treeBefore && noTmp());
  resetLimits(); u = await up({ key: UKEY, ref: 't5', token: TOKEN }); t('erreur de syntaxe PHP → échec avant toute écriture', u.status === 500 && /syntaxe PHP/.test(u.text) && treeHash() === treeBefore);
  resetLimits(); u = await up({ key: UKEY, ref: 't9', token: TOKEN });
  t('la nouvelle version ne répond pas → retour arrière automatique, site identique, fichier conservé', u.status === 500 && /rétablie automatiquement/.test(u.text) && treeHash() === treeBefore && exists('update.php') && noTmp(), u.text.replace(/<style[\s\S]*?<\/style>/, '').replace(/<[^>]+>/g, ' ').slice(0, 300) + ` tree=${treeHash() === treeBefore} kept=${exists('update.php')} tmp=${noTmp()}`);
  t('…et la version enregistrée est restée celle d\'avant', JSON.parse(read('backend/data/var/version.json')).sha === '1'.repeat(40) && !exists('backend/data/var/backup/prev/backup.json'));
  t('API opérationnelle après ce retour arrière', (await fetch(`${BASE}/api/config`)).status === 200);
  resetLimits(); u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('mise à jour réussie', u.status === 200 && /2222222/.test(u.text), u.text.slice(0, 200)); t('app.js en v1.2', read('app.js').includes('fixture v1.2'));
  t('update.php supprimé', !exists('update.php')); t('version enregistrée par update.php', JSON.parse(read('backend/data/var/version.json')).by === 'update.php');
  t('jeton jamais écrit sur le disque', !fs.readdirSync(path.join(WWW, 'backend/data/var')).some((f) => !fs.statSync(path.join(WWW, 'backend/data/var', f)).isDirectory() && read(`backend/data/var/${f}`).includes(TOKEN)) && noTmp());
  t('opération journalisée', /"by":"update.php".*"ok":true/.test(fs.readFileSync(path.join(WWW, 'backend/data/logs/updates.jsonl'), 'utf8')));
  const afterRescue = treeHash(true);
  // Idempotence : même version relancée = même état, et le retour arrière de /auth vers la version d'avant est conservé
  dropUpdate(); resetLimits(); u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('relance avec la même version : succès, signalée comme réparation', u.status === 200 && /déjà installée/.test(u.text) && !exists('update.php'));
  t('état identique après la relance (idempotent)', treeHash(true) === afterRescue);
  t('retour arrière de /auth toujours possible vers la version précédente', /Rétablir la version précédente/.test((await c.req('GET', `${BASE}/auth`)).text));
  // Secours : code du site endommagé, éléments de protection manquants
  fs.writeFileSync(path.join(WWW, 'backend/src/lib/updater.php'), '<?php this is not php {{{');
  fs.unlinkSync(path.join(WWW, 'backend/.htaccess')); fs.unlinkSync(path.join(WWW, 'backend/data/.htaccess')); fs.unlinkSync(path.join(WWW, 'app.js'));
  fs.writeFileSync(path.join(WWW, '.htaccess'), read('.htaccess').replace(/# \/backend \(backend : accès refusé\) v1\n[^\n]*\n[^\n]*\n\n/, ''));
  dropUpdate(); resetLimits(); u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('secours : le site endommagé est réparé (le script ne dépend pas du code installé)', u.status === 200 && /2222222/.test(u.text) && !exists('update.php'), u.text.replace(/<[^>]+>/g, ' ').slice(0, 300));
  t('…fichiers de la version, .htaccess du backend et des données restaurés', read('app.js').includes('fixture v1.2') && /Require all denied/.test(read('backend/.htaccess')) && !read('backend/src/lib/updater.php').includes('this is not php') && /Require all denied/.test(read('backend/data/.htaccess')));
  t('…règle d\'interdiction du backend rétablie dans le .htaccess racine, une seule fois', (read('.htaccess').match(/backend : accès refusé/g) || []).length === 1);
  const toxRes = await fetch(`${BASE}/tox`, { redirect: 'manual' });
  t('…/tox redirigé vers tox.radiv.fr (la règle est passée avant l\'ancienne règle /tox du site)', toxRes.status === 301 && toxRes.headers.get('location') === 'https://tox.radiv.fr/' && (read('.htaccess').match(/# \/tox \(redirection vers tox\.radiv\.fr\) v1/g) || []).length === 1);
  t('…règles du référencement et du cache réparées dans le .htaccess, accueil avec canonique absolue', /# \/seo \(accueil/.test(read('.htaccess')) && /# Compression et cache v1/.test(read('.htaccess')) && (await (await fetch(`${BASE}/`)).text()).includes(`<link rel="canonical" href="${BASE}/" />`) && /<loc>.*<\/loc>/.test(await (await fetch(`${BASE}/sitemap.xml`)).text()));
  t('…état identique à avant l\'incident (hors ordre des blocs de tête du .htaccess)', treeHash(true) === afterRescue);
  t('…et le site fonctionne', (await fetch(`${BASE}/api/config`)).status === 200 && (await c.req('GET', `${BASE}/auth`)).status === 200);
  // Jeton conservé sur le serveur : jamais redemandé
  const envKeep = runtimeEnv(); setEnv('UPDATE_GITHUB_TOKEN', TOKEN); dropUpdate(); resetLimits();
  const uForm2 = await (await fetch(`${BASE}/update.php`)).text();
  t('jeton de config/runtime.env valide : champ jeton masqué', !/name="token"/.test(uForm2) && !uForm2.includes(TOKEN));
  u = await up({ key: UKEY, ref: 'v1.1' });
  t('mise à jour sans saisir de jeton (celui de la configuration)', u.status === 200 && /1111111/.test(u.text) && JSON.parse(read('backend/data/var/version.json')).sha === '1'.repeat(40));
  fs.writeFileSync(path.join(WWW, 'backend/config/runtime.env'), envKeep);
  // Backend introuvable
  fs.writeFileSync(path.join(WWW, 'api/backend.php'), "<?php return '/chemin/inexistant';\n"); dropUpdate();
  u = await fetch(`${BASE}/update.php`); const lost = await u.text(); t('backend introuvable → message clair orientant vers install.php', u.status === 500 && /install\.php/.test(lost), `${u.status} ${lost.replace(/<style[\s\S]*?<\/style>/, '').replace(/<[^>]+>/g, ' ').slice(0, 200)}`);
  fs.unlinkSync(path.join(WWW, 'api/backend.php')); fs.unlinkSync(path.join(WWW, 'update.php'));
  // Retour arrière de /auth après le secours (sauvegarde au même format)
  page = await c.req('GET', `${BASE}/auth`);
  t('retour arrière proposé après le secours', /Rétablir la version précédente/.test(page.text));
  const rb2 = await c.req('POST', `${BASE}/auth/rollback`, { form: { csrf: csrfOf(page.text) }, headers: { Origin: BASE } });
  const rbText = (await c.req('GET', rb2.loc)).text;
  t('…et il rétablit l\'état précédent', rbText.includes('Version précédente rétablie'), flash(rbText));

  // Migration de l'ancienne structure (tout dans api/) vers public/ + backend/
  console.log('\n— update.php : migration de l\'ancienne structure (api/ → backend/)');
  const legacyMake = () => {
    const w = (rel, c) => { fs.mkdirSync(path.dirname(path.join(WWW, rel)), { recursive: true }); fs.writeFileSync(path.join(WWW, rel), c); };
    const mv = (a, b) => { fs.mkdirSync(path.dirname(path.join(WWW, b)), { recursive: true }); fs.renameSync(path.join(WWW, a), path.join(WWW, b)); };
    mv('backend/config/runtime.env', 'api/.runtime.env'); mv('backend/data/logs', 'api/logs'); mv('backend/data/var', 'api/var');
    fs.rmSync(path.join(WWW, 'backend'), { recursive: true, force: true });
    w('api/.env', 'LOCAL_ONLY=1\n'); w('api/lib/config.php', '<?php // ancien code\n'); w('api/lib/old.php', '<?php // ancien code\n'); w('api/assets/old.js', '//\n'); w('api/calculation.php', '<?php // ancien\n');
    w('api/logs/measurements-2026-01.jsonl', '{"x":1}\n'); w('api/var/sessions-marker', 'garder'); w('api/var/backup/prev/backup.json', '{"ancien":true}');
    fs.writeFileSync(path.join(WWW, 'api/var/manifest.json'), JSON.stringify({ files: ['index.html', 'api/lib/old.php'] }));
    fs.chmodSync(path.join(WWW, 'api/.runtime.env'), 0o640);
    require('child_process').spawnSync('chown', ['-R', 'www-data:www-data', WWW]);
  };
  legacyMake(); dropUpdate();
  for (const f of fs.readdirSync(WWW)) if (f.startsWith('.update-attempts')) fs.unlinkSync(path.join(WWW, f));
  u = await fetch(`${BASE}/update.php`); const lgForm = await u.text();
  t('ancienne structure détectée et annoncée', u.status === 200 && /Ancienne structure détectée/.test(lgForm), lgForm.replace(/<[^>]+>/g, ' ').slice(0, 200));
  const lgBefore = fs.readFileSync(path.join(WWW, 'api/.runtime.env'), 'utf8');
  u = await up({ key: UKEY, ref: 't5', token: TOKEN });
  t('migration : échec de validation → rien n\'est déplacé', u.status === 500 && exists('api/.runtime.env') && exists('api/lib/old.php') && exists('api/logs/measurements-2026-01.jsonl') && !exists('backend') && exists('update.php') && noTmp());
  u = await up({ key: UKEY, ref: 't9', token: TOKEN });
  t('migration : nouvelle version défaillante → tout est rétabli (configuration, journaux, sessions, ancien code)', u.status === 500 && /rétablie automatiquement/.test(u.text) && exists('api/.runtime.env') && exists('api/.env') && exists('api/var/sessions-marker') && exists('api/logs/measurements-2026-01.jsonl') && exists('api/lib/old.php') && !exists('backend') && !exists('api/backend.php') && /ancien code/.test(read('api/lib/config.php')) && read('api/var/backup/prev/backup.json').includes('ancien') && read('api/var/manifest.json').includes('api/lib/old.php') && exists('update.php') && noTmp(), u.text.replace(/<style[\s\S]*?<\/style>/, '').replace(/<[^>]+>/g, ' ').slice(0, 300));
  u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('migration réussie', u.status === 200 && /2222222/.test(u.text) && /migrée/.test(u.text) && !exists('update.php'), u.text.replace(/<style[\s\S]*?<\/style>/, '').replace(/<[^>]+>/g, ' ').slice(0, 300));
  t('…configuration, variables locales, journaux et sessions conservés (déplacés)', read('backend/config/runtime.env') === lgBefore && read('backend/config/local.env') === 'LOCAL_ONLY=1\n' && read('backend/data/logs/measurements-2026-01.jsonl') === '{"x":1}\n' && read('backend/data/var/sessions-marker') === 'garder' && !exists('api/.runtime.env') && !exists('api/logs') && !exists('api/var'));
  t('…ancien code d\'api/ supprimé, façade et pointeur en place', !exists('api/lib') && !exists('api/assets') && !exists('api/calculation.php') && exists('api/index.php') && exists('api/backend.php') && fs.statSync(path.join(WWW, 'api/index.php')).size < 4000);
  t('…ancienne sauvegarde (autre format) écartée, état de référence au nouveau format', (!exists('backend/data/var/backup/prev/backup.json') || !read('backend/data/var/backup/prev/backup.json').includes('ancien')) && JSON.parse(read('backend/data/var/version.json')).sha === '2'.repeat(40) && !read('backend/data/var/manifest.json').includes('api/lib/old.php'));
  t('…protections du backend en place et site opérationnel', /Require all denied/.test(read('backend/.htaccess')) && (await fetch(`${BASE}/backend/config/runtime.env`)).status !== 200 && (await fetch(`${BASE}/api/config`)).status === 200 && (await c.req('GET', `${BASE}/auth`)).status === 200);
  fs.unlinkSync(path.join(WWW, 'backend/data/var/sessions-marker')); fs.unlinkSync(path.join(WWW, 'backend/data/logs/measurements-2026-01.jsonl'));
  fs.unlinkSync(path.join(WWW, 'backend/config/local.env'));

  // Redistribution des dossiers selon BACKEND_DIR / DATA_DIR de runtime.env
  console.log('\n— update.php : déplacement du backend et des données selon runtime.env');
  const PARENT = path.dirname(WWW); fs.chmodSync(PARENT, 0o777);
  const OUT = path.join(PARENT, 'moved-backend'); const OUTDATA = path.join(PARENT, 'moved-data');
  const cfgPath = () => path.join(WWW, 'backend/config/runtime.env');
  { // ancien format (sans marqueur), avec un réglage inconnu : remis en forme, valeurs conservées, ancienne version gardée
    const old = runtimeEnv().split('\n').filter((l) => !/Format du fichier/.test(l)).concat(['CUSTOM_X="valeur 1"']).join('\n');
    fs.writeFileSync(cfgPath(), old); fs.chmodSync(cfgPath(), 0o640); dropUpdate(); u = await up({ key: UKEY, ref: 'main', token: TOKEN });
    const parse = (txt) => Object.fromEntries(txt.split('\n').filter((l) => /^[A-Z_]+=/.test(l)).map((l) => [l.split('=')[0], l.slice(l.indexOf('=') + 1).replace(/^"(.*)"$/, '$1')]));
    const was = parse(old); const now = parse(runtimeEnv());
    t('runtime.env remis en forme (sections, commentaires), toutes les valeurs conservées, ancienne version gardée', u.status === 200 && /# Format du fichier : 2/.test(runtimeEnv()) && /^# -{20,}\n# Site et API/m.test(runtimeEnv()) && Object.keys(was).every((k) => now[k] === was[k]) && now.CUSTOM_X === 'valeur 1' && read('backend/config/runtime.env.bak') === old && (fs.statSync(cfgPath()).mode & 0o077) === 0, `${u.status} ${Object.keys(was).filter((k) => now[k] !== was[k]).join()}`);
  }
  fs.writeFileSync(path.join(WWW, 'backend/data/logs/marker.jsonl'), 'journal\n'); fs.writeFileSync(path.join(WWW, 'backend/data/var/marker'), 'session\n');
  const withEnv = (extra) => { const f = cfgPath(); const lines = fs.readFileSync(f, 'utf8').split('\n').filter((l) => !/^#?(BACKEND_DIR|DATA_DIR)=/.test(l)); fs.writeFileSync(f, lines.concat(extra).join('\n') + '\n'); };
  dropUpdate(); withEnv([`BACKEND_DIR="${OUT}"`]);
  let ug = await fetch(`${BASE}/update.php`); const mvForm = await ug.text();
  t('déplacement annoncé avant d\'agir', /Déplacement prévu/.test(mvForm) && mvForm.includes(OUT));
  u = await up({ key: UKEY, ref: 't9', token: TOKEN });
  t('déplacement + version défaillante → tout est rétabli (backend, données, pointeur)', u.status === 500 && /rétablie automatiquement/.test(u.text) && exists('backend/src/app.php') && exists('backend/data/logs/marker.jsonl') && !fs.existsSync(OUT) && !read('api/backend.php').includes(OUT) && exists('update.php'), u.text.replace(/<style[\s\S]*?<\/style>/, '').replace(/<[^>]+>/g, ' ').slice(0, 300));
  u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('backend déplacé hors de la racine web', u.status === 200 && /Backend déplacé/.test(u.text) && !exists('backend') && fs.existsSync(path.join(OUT, 'src/app.php')) && !exists('update.php'), u.text.replace(/<style[\s\S]*?<\/style>/, '').replace(/<[^>]+>/g, ' ').slice(0, 300));
  t('…données conservées (journaux, sessions), pointeur mis à jour, dossier de données mémorisé', fs.readFileSync(path.join(OUT, 'data/logs/marker.jsonl'), 'utf8') === 'journal\n' && fs.readFileSync(path.join(OUT, 'data/var/marker'), 'utf8') === 'session\n' && read('api/backend.php').includes(OUT) && fs.readFileSync(path.join(OUT, 'config/.data-dir'), 'utf8').trim() === path.join(OUT, 'data'));
  t('…site opérationnel depuis le nouvel emplacement', (await fetch(`${BASE}/api/config`)).status === 200 && (await c.req('GET', `${BASE}/auth`)).status === 200);
  // Données seules, ailleurs
  const envOut = path.join(OUT, 'config/runtime.env');
  const withEnvOut = (extra) => { const lines = fs.readFileSync(envOut, 'utf8').split('\n').filter((l) => !/^#?(BACKEND_DIR|DATA_DIR)=/.test(l)); fs.writeFileSync(envOut, lines.concat(extra).join('\n') + '\n'); };
  dropUpdate(); withEnvOut([`BACKEND_DIR="${OUT}"`, `DATA_DIR="${OUTDATA}"`]);
  u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('DATA_DIR modifié : les données seules sont déplacées', u.status === 200 && /Données déplacées/.test(u.text) && fs.readFileSync(path.join(OUTDATA, 'logs/marker.jsonl'), 'utf8') === 'journal\n' && !fs.existsSync(path.join(OUT, 'data')) && fs.existsSync(path.join(OUT, 'src/app.php')), u.text.replace(/<[^>]+>/g, ' ').slice(0, 300));
  t('…site opérationnel', (await fetch(`${BASE}/api/config`)).status === 200 && (await c.req('GET', `${BASE}/auth`)).status === 200);
  // Cible non vide : refus, rien ne bouge
  fs.mkdirSync(path.join(PARENT, 'occupied')); fs.writeFileSync(path.join(PARENT, 'occupied/x'), 'x');
  dropUpdate(); withEnvOut([`BACKEND_DIR="${path.join(PARENT, 'occupied')}"`, `DATA_DIR="${OUTDATA}"`]);
  u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('nouvel emplacement non vide → refusé, rien ne bouge', u.status === 500 && fs.existsSync(path.join(OUT, 'src/app.php')) && fs.existsSync(path.join(OUTDATA, 'logs/marker.jsonl')) && exists('update.php'), u.text.replace(/<[^>]+>/g, ' ').slice(0, 300));
  fs.rmSync(path.join(PARENT, 'occupied'), { recursive: true });
  // Retour à l'emplacement par défaut
  dropUpdate(); withEnvOut(['BACKEND_DIR="backend"']);
  u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('retour à l\'emplacement par défaut (backend/ dans la racine, données dedans)', u.status === 200 && exists('backend/src/app.php') && read('backend/data/logs/marker.jsonl') === 'journal\n' && read('backend/data/var/marker') === 'session\n' && !fs.existsSync(OUT) && !fs.existsSync(OUTDATA), u.text.replace(/<[^>]+>/g, ' ').slice(0, 300));
  t('…site opérationnel et protégé', (await fetch(`${BASE}/api/config`)).status === 200 && (await fetch(`${BASE}/backend/config/runtime.env`)).status !== 200);
  fs.unlinkSync(path.join(WWW, 'backend/data/logs/marker.jsonl')); fs.unlinkSync(path.join(WWW, 'backend/data/var/marker')); fs.rmSync(path.join(WWW, 'backend/config/runtime.env.bak'), { force: true });
  withEnv(['#BACKEND_DIR=']); setEnv('UPDATE_GITHUB_TOKEN', null); setEnv('CUSTOM_X', null);
  const envAfterLayout = runtimeEnv();

  console.log('\n— Fichiers hors liste blanche (.env, .htaccess, config.js, tests...)');
  const cfgBefore = read('config.js'); const htBefore = read('.htaccess'); resetLimits();
  r = await update(c, 't10', TOKEN);
  t('installée', /aaaaaaa/.test(r.msg), r.msg);
  t('vendor/: fichiers statiques installés, jamais de PHP ni de dossier trop profond', exists('vendor/ok.css') && exists('vendor/fonts/ok.woff2') && !exists('vendor/evil.php') && !exists('vendor/a/b/c.js') && !exists('vendor/.htaccess') && !exists('vendor/fonts/evil.phtml'));
  t('config.js et .htaccess racine inchangés', read('config.js') === cfgBefore && read('.htaccess') === htBefore);
  t('config, données, outillage, pointeur et scripts hors liste blanche jamais écrits', !exists('.env') && !exists('backend/config/local.env') && !exists('deploy.sh') && !exists('update.php.bak') && !exists('backend/data/logs/x.jsonl') && !exists('tests/x.php') && !exists('backend/evil.php') && !exists('backend/src/x.txt') && !exists('backend/src/lib/sub/x.php') && !exists('api/evil.php') && !exists('api/backend.php.pirate') && !read('backend/config/runtime.env').includes('X=1') && !exists('vendor/ok.css.x'));
  t('pointeur du backend (api/backend.php) non remplacé par la version cible', !exists('api/backend.php') || !read('api/backend.php').includes('pirate'));
  t('.runtime.env intact', runtimeEnv() === envAfterLayout);

  console.log('\n— Onglet « Mesures »');
  // Journaux de test aux dates RELATIVES (la durée d'antériorité est relative à « maintenant ») : valeurs hostiles
  // venant d'« appelants anonymes », rotation .1, ligne corrompue, ligne sans données (niveau « user »).
  fs.mkdirSync(path.join(WWW, 'backend/data/logs'), { recursive: true });
  const DAY = 86400000; const NOW = Date.now();
  const logFile = (ts, rotated = false) => { const d = new Date(ts); return path.join(WWW, `backend/data/logs/measurements-${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}.jsonl${rotated ? '.1' : ''}`); };
  const addRow = (ts, row, rotated = false) => { fs.appendFileSync(logFile(ts, rotated), JSON.stringify({ timestamp: new Date(ts).toISOString(), ...row }) + '\n'); fs.chmodSync(logFile(ts, rotated), 0o644); };
  const reco = { conjoint_plus_60: 12, conjoint_moins_60: 25, collegues_travail: 20, scenario_utilisateur: null };
  const full = (ip, geo, iso, ok = true, extra = {}) => ({ ip, ip_geo: geo, input: { isotope_code: iso, dose_rate: 100, patient_size_cm: 150, ...extra }, result: { ok, effective_days: 11.43, errors: ok ? [] : ['dose_rate invalide'], recommendations_days: ok ? reco : {}, rows: [] } });
  const paris = { ip: '9.9.9.9', country: 'France', region: 'Île-de-France', city: '<img src=x onerror=alert(1)>Paris', latitude: 48.857, longitude: 2.352 };
  for (const f of fs.readdirSync(path.join(WWW, 'backend/data/logs'))) if (f.startsWith('measurements-')) fs.unlinkSync(path.join(WWW, 'backend/data/logs', f));
  addRow(NOW - 2 * 3600e3, full('9.9.9.9', paris, '<script>alert(1)</script>', true, { user_period_days: 3, cure_count: 4 }));  // A: 2 h
  addRow(NOW - 3 * DAY, full('8.8.8.8', null, "=cmd|' /C calc'!A0"));                                                          // B: 3 j
  addRow(NOW - 20 * DAY, full('9.9.9.9', paris, 'radium223', false), true);                                                    // C: 20 j (fichier .1)
  addRow(NOW - 60 * DAY, full('7.7.7.7', { ip: '7.7.7.7', country: 'France', city: 'Lyon', latitude: 45.76, longitude: 4.84 }, 'psma_177lu')); // D: 60 j
  addRow(NOW - 400 * DAY, full('6.6.6.6', { ip: '6.6.6.6', country: 'United States', city: 'Austin', latitude: 30.27, longitude: -97.74 }, 'radium223')); // E: 400 j
  addRow(NOW - 3600e3, { ip: '5.5.5.5', ip_geo: { ip: '5.5.5.5', country: 'Germany', city: 'Berlin', latitude: 52.52, longitude: 13.4 } });  // F: 1 h, sans données
  fs.appendFileSync(logFile(NOW), 'ligne corrompue\n\n');
  const statOf = (html, label) => Number(((html.match(new RegExp(`<div class="v">([^<]*)</div><div class="l">${label}</div>`)) || [])[1] || '').replace(/\s/g, ''));
  const get = (qs) => c.req('GET', `${BASE}/auth/mesures${qs}`);
  resetLimits(); c = new Client(); await c.login();
  page = await c.req('GET', `${BASE}/auth`);
  t('onglet « Mesures » dans la navigation, onglet « Site » actif', /<a class="tab" href="\/auth\/mesures">Mesures<\/a>/.test(page.text) && /<a class="tab on" href="\/auth" aria-current="page">Site<\/a>/.test(page.text));
  let m = await get('');
  t('page Mesures: onglet actif, durée par défaut 30 jours', m.status === 200 && /<a class="tab on" href="\/auth\/mesures" aria-current="page">Mesures<\/a>/.test(m.text) && /Derniers 30 jours/.test(m.text) && /segbtn on" href="\/auth\/mesures">30 jours/.test(m.text));
  t('30 jours: 4 mesures (A, B, C via fichier .1, F), pas D ni E', statOf(m.text, 'Mesures') === 4 && !/7\.7\.7\.7|6\.6\.6\.6/.test(m.text), String(statOf(m.text, 'Mesures')));
  t('indicateurs: adresses IP distinctes, taux de réussite', statOf(m.text, 'Adresses IP') === 3 && /<div class="v">67 %<\/div><div class="l">Calculs réussis/.test(m.text) && statOf(m.text, 'En erreur') === 1, m.text.slice(m.text.indexOf('class="stats"'), m.text.indexOf('class="stats"') + 600));
  for (const [qs, n, label] of [['?days=1', 2, '24 h'], ['?days=7', 3, '7 jours'], ['?days=30', 4, '30 jours'], ['?days=90', 5, '90 jours'], ['?days=365', 5, '12 mois'], ['?days=all', 6, 'tout (E à 400 j inclus)']]) {
    m = await get(qs); t(`durée d'antériorité ${label} → ${n} mesure(s)`, m.status === 200 && statOf(m.text, 'Mesures') === n, String(statOf(m.text, 'Mesures')));
  }
  m = await get('?days=999&year=abc&page=-5'); t('paramètres hors bornes ignorés → 30 jours', m.status === 200 && statOf(m.text, 'Mesures') === 4);
  const dMonth = new Date(NOW - 60 * DAY); m = await get(`?period=${dMonth.getUTCFullYear()}-${dMonth.getUTCMonth() + 1}`);
  t('mois précis (liste déroulante): mesures de ce mois seulement', m.status === 200 && /7\.7\.7\.7/.test(m.text) && !/9\.9\.9\.9/.test(m.text) && /segbtn"/.test(m.text), String(statOf(m.text, 'Mesures')));
  m = await get('?days=all&iso=radium223'); t('filtre isotope', statOf(m.text, 'Mesures') === 2);
  m = await get('?days=all&ok=0'); t('filtre « en erreur »', statOf(m.text, 'Mesures') === 1 && /dose_rate invalide/.test(m.text));
  m = await get('?days=all&ok=1'); t('filtre « réussis » (exclut les lignes sans résultat)', statOf(m.text, 'Mesures') === 4, String(statOf(m.text, 'Mesures')));
  m = await get('?days=all&q=Lyon'); t('filtre texte sur le lieu', statOf(m.text, 'Mesures') === 1 && /7\.7\.7\.7/.test(m.text));
  m = await get('?q=9.9.9'); t('filtre texte sur l\'IP', statOf(m.text, 'Mesures') === 2);
  m = await get('?iso=radium223&ok=0&q=9.9'); t('filtres combinés', statOf(m.text, 'Mesures') === 1);
  m = await get('?ok=peut-etre&iso=' + 'x'.repeat(500)); t('valeurs de filtre invalides: ignorées ou bornées', m.status === 200);
  m = await get('?days=7'); t('graphique d\'activité (SVG, une barre par jour)', /<svg class="chart"/.test(m.text) && (m.text.match(/<rect class="bar"/g) || []).length >= 3 && (m.text.match(/<rect class="bar"/g) || []).length <= 8, String((m.text.match(/<rect class="bar"/g) || []).length));
  m = await get('?days=all'); t('graphique par mois au-delà de 100 jours', (m.text.match(/<rect class="bar"/g) || []).length >= 12);
  m = await get('?days=all'); t('répartition par isotope', /class="hbar"/.test(m.text) && />radium223<\/span>/.test(m.text));
  t('tableau: dates à l\'heure de Paris, pastilles d\'entrées et de résultats lisibles', /\d\d\/\d\d\/\d{4} \d\d:\d\d:\d\d/.test(m.text) && /<span class="pill">Débit 100<\/span>/.test(m.text) && /<span class="pill">4 cures<\/span>/.test(m.text) && /Conjoint &gt; 60 ans <b>12 j<\/b>/.test(m.text) && /<span class="badge good">OK<\/span>/.test(m.text) && /<span class="badge bad">Erreur<\/span>/.test(m.text));
  m = await get('');
  t('valeurs hostiles échappées (isotope, ville) — aucun HTML injecté', !/<script>alert/.test(m.text) && !/<img src=x/.test(m.text) && /&lt;script&gt;alert/.test(m.text) && /&lt;img src=x/.test(m.text));
  const pts = JSON.parse(((m.text.match(/<script type="application\/json" id="map-points">([\s\S]*?)<\/script>/) || [])[1]) || 'null');
  t('carte: points agrégés par position (Paris: 2 requêtes, Berlin: 1)', Array.isArray(pts) && pts.length === 2 && pts.some((x) => x.count === 2 && Math.abs(x.lat - 48.857) < 0.01) && pts.some((x) => x.count === 1 && Math.abs(x.lon - 13.4) < 0.01), JSON.stringify(pts));
  t('carte: JSON intégré inoffensif (balises encodées)', !/<img src=x/.test((m.text.match(/id="map-points">([\s\S]*?)<\/script>/) || [])[1]) && /\\u003C/.test(m.text));
  t('carte: conteneur, Leaflet et script servis par ce site (aucun script tiers)', /id="map"/.test(m.text) && /<script src="\/auth\/assets\/leaflet\.js"><\/script><script src="\/auth\/assets\/mesures\.js"><\/script>/.test(m.text) && !/<script src="https?:/.test(m.text) && !/unpkg|cdn/.test(m.text));
  const csp = m.headers.get('content-security-policy') || '';
  t('CSP de la page carte: scripts de ce site uniquement + tuiles OpenStreetMap', /script-src 'self'(;|$)/.test(csp) && /img-src 'self' data: https:\/\/\*\.tile\.openstreetmap\.org/.test(csp) && !/'unsafe-eval'|'unsafe-inline'[^;]*script/.test(csp) && /default-src 'none'/.test(csp), csp);
  t('politique de référent compatible avec les tuiles (origine seulement)', m.headers.get('referrer-policy') === 'strict-origin-when-cross-origin');
  page = await c.req('GET', `${BASE}/auth`); const cspSite = page.headers.get('content-security-policy') || '';
  t('onglet Site: toujours aucun JavaScript ni ressource externe', !/script-src/.test(cspSite) && !/img-src/.test(cspSite) && !/<script/.test(page.text));
  m = await get('?days=1&q=zzz'); t('sélection vide: message, pas de carte', /Aucune mesure sur cette sélection/.test(m.text) && !/id="map"/.test(m.text) && !/leaflet/.test(m.text));

  console.log('\n— Fichiers de la carte (servis derrière la session)');
  for (const f of ['leaflet.js', 'leaflet.css', 'mesures.js']) {
    const a = await c.req('GET', `${BASE}/auth/assets/${f}`); const local = fs.readFileSync(path.join(WWW, 'backend/assets', f));
    t(`${f}: servi, contenu identique, type correct, mis en cache`, a.status === 200 && Buffer.from(a.text).length >= local.length - 8 && crypto.createHash('sha256').update(a.text).digest('hex') === crypto.createHash('sha256').update(local.toString()).digest('hex') && /javascript|css/.test(a.headers.get('content-type')) && /max-age/.test(a.headers.get('cache-control')));
  }
  const lf = crypto.createHash('sha256').update(fs.readFileSync(path.join(WWW, 'backend/assets/leaflet.js'))).digest('base64');
  t('Leaflet 1.9.4: empreinte identique à celle épinglée par l\'ancienne page (intégrité)', lf === '20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=', lf);
  for (const bad of ['inconnu.js', '..%2Findex.php', '%2e%2e%2fconfig.php', 'LEAFLET-LICENSE.txt', 'leaflet.js%00.php']) {
    const a = await c.req('GET', `${BASE}/auth/assets/${bad}`); t(`fichier non listé « ${bad} » → 404`, a.status === 404 && !/<\?php/.test(a.text));
  }
  t('sans session: fichiers de la carte et onglet → 404', (await new Client().req('GET', `${BASE}/auth/assets/leaflet.js`)).status === 404 && (await new Client().req('GET', `${BASE}/auth/mesures`)).status === 404);

  console.log('\n— Script de la carte (exécuté avec un DOM simulé)');
  const vm = require('vm'); const jsSource = fs.readFileSync(path.join(WWW, 'backend/assets/mesures.js'), 'utf8');
  const runMap = (jsonText) => {
    const markers = []; let fit = null; let mapCreated = false; const els = { map: {}, 'map-points': { textContent: jsonText } };
    const node = (tag) => ({ tag, children: [], _text: '', appendChild(ch) { this.children.push(ch); return ch; }, set textContent(v) { this._text = v; }, get textContent() { return this._text; }, firstChild: null });
    const L = { map() { mapCreated = true; return { setView() { return this; }, fitBounds(b) { fit = b; } }; }, tileLayer() { return { addTo() {} }; }, divIcon(o) { return o; },
      marker(ll, o) { const inner = node('div'); const mk = { ll, o, popup: null, addTo() { return mk; }, getElement() { return { firstChild: inner }; }, bindPopup(p) { mk.popup = p; return mk; }, inner }; markers.push(mk); return mk; } };
    const document = { getElementById: (id) => els[id] || null, createElement: node, createTextNode: (x) => ({ text: x }) };
    vm.runInNewContext(jsSource, { window: { L }, document, L, JSON, Number, String, Array, isFinite });
    return { markers, fit, mapCreated };
  };
  let r2 = runMap(JSON.stringify([{ lat: 48.857, lon: 2.352, count: 7, label: '<b>Paris</b> / France' }, { lat: 'x', lon: 1, count: 1, label: 'invalide' }, { lat: 45, lon: 4, count: 1, label: 'Lyon' }]));
  t('carte: un marqueur par point valide, couleur selon le nombre (7 → rouge)', r2.markers.length === 2 && /c3/.test(r2.markers[0].o.icon.html) && /c1/.test(r2.markers[1].o.icon.html));
  t('carte: nombre et libellé écrits en texte (jamais interprétés comme du HTML)', r2.markers[0].inner.textContent === '7' && r2.markers[0].popup.children[0].textContent === '<b>Paris</b> / France' && !/innerHTML/.test(jsSource.replace(/\/\/.*$/gm, '')));
  t('carte: ajustée aux marqueurs', Array.isArray(r2.fit) && r2.fit.length === 2);
  r2 = runMap('{pas du json'); t('JSON invalide: aucune erreur, aucune carte', r2.mapCreated === false);
  r2 = runMap('[]'); t('aucun point: aucune carte', r2.mapCreated === false);

  console.log('\n— Pagination et export');
  for (let i = 0; i < 120; i += 1) addRow(NOW - 600e3 - i * 1000, full(`4.4.4.${i % 250}`, null, 'radium223'));
  m = await get('?days=1'); t('pagination: 50 lignes par page, « Page 1 / 3 »', /Page 1 \/ 3/.test(m.text) && (m.text.match(/<tr><td class="nowrap">\d\d\//g) || []).length === 50 && /Suivante/.test(m.text) && !/Précédente/.test(m.text));
  m = await get('?days=1&page=3'); t('dernière page: 22 lignes (122 mesures), lien « Précédente »', /Page 3 \/ 3/.test(m.text) && (m.text.match(/<tr><td class="nowrap">\d\d\//g) || []).length === 22 && /Précédente/.test(m.text) && !/Suivante/.test(m.text));
  m = await get('?days=1&page=99999'); t('page hors bornes ramenée à la dernière', /Page 3 \/ 3/.test(m.text));
  t('les liens de page conservent les filtres', /href="\/auth\/mesures\?days=1&amp;iso=radium223&amp;page=2"/.test((await get('?days=1&iso=radium223')).text));
  let csv = await c.req('GET', `${BASE}/auth/mesures.csv?days=all&iso=radium223`);
  t('export CSV de la sélection (toutes les lignes, pas seulement la page)', csv.status === 200 && /text\/csv/.test(csv.headers.get('content-type')) && /filename="mesures_tout\.csv"/.test(csv.headers.get('content-disposition')) && csv.text.split('\n').length === 1 + 2 + 120 && csv.text.startsWith('timestamp,ip,ip_geo'), String(csv.text.split('\n').length));
  csv = await c.req('GET', `${BASE}/auth/mesures.csv?days=30`); t('export: nom de fichier selon la durée, formule neutralisée', /mesures_30j\.csv/.test(csv.headers.get('content-disposition')) && /(^|,)'=cmd/m.test(csv.text) && !/(^|,)=cmd/m.test(csv.text));
  csv = await c.req('GET', `${BASE}/auth/mesures.csv?year=2000&month=1`); t('export: période sans donnée → seulement l\'en-tête', csv.status === 200 && csv.text.split('\n').filter(Boolean).length === 1);
  csv = await c.req('GET', `${BASE}/auth/mesures.csv?year=../../x&days=%0d%0aSet-Cookie:x`); t('export: paramètres hostiles → nom de fichier sûr', csv.status === 200 && /filename="mesures_30j\.csv"/.test(csv.headers.get('content-disposition')) && !/[\r\n]/.test(csv.headers.get('content-disposition')));

  console.log('\n— Mesures désactivées');
  setEnv('ADMIN_MEASUREMENTS_ENABLED', 'false'); resetLimits();
  t('mesures désactivées → onglet et export CSV en 404', (await get('')).status === 404 && (await c.req('GET', `${BASE}/auth/mesures.csv`)).status === 404);
  page = await c.req('GET', `${BASE}/auth`); t('…et onglet masqué dans la navigation', !/href="\/auth\/mesures"/.test(page.text));
  setEnv('ADMIN_MEASUREMENTS_ENABLED', 'true');

  console.log('\n— CSRF, connexion récente, déconnexion');
  page = await c.req('GET', `${BASE}/auth`);
  t('POST sans jeton CSRF → 403', (await c.req('POST', `${BASE}/auth/update`, { form: { ref: 'main' }, headers: { Origin: BASE } })).status === 403);
  t('POST avec mauvais jeton CSRF → 403', (await c.req('POST', `${BASE}/auth/update`, { form: { csrf: 'f'.repeat(64), ref: 'main' }, headers: { Origin: BASE } })).status === 403);
  // Régression « Requête refusée » : avec Referrer-Policy no-referrer, les navigateurs envoient « Origin: null » sur les formulaires.
  t('pages d\'administration: Referrer-Policy same-origin (jamais no-referrer, qui donne « Origin: null »)', page.headers.get('referrer-policy') === 'same-origin');
  const csrfTok = csrfOf(page.text);
  resetLimits(); // action sans effet sur le site : une mise à jour vers une version inexistante (échoue proprement)
  const stalePost = (headers, token = csrfTok) => c.req('POST', `${BASE}/auth/update`, { form: { csrf: token, ref: 'inexistant', token: TOKEN }, headers });
  let pr = await stalePost({ Origin: 'null' }); t('« Origin: null » avec le bon jeton CSRF → accepté (plus de « Requête refusée »)', pr.status === 303, String(pr.status));
  pr = await stalePost({ Origin: 'null' }, 'f'.repeat(64)); t('« Origin: null » avec un mauvais jeton → refusé', pr.status === 403);
  pr = await stalePost({ 'Sec-Fetch-Site': 'cross-site' }); t('Sec-Fetch-Site: cross-site → refusé même avec le bon jeton', pr.status === 403);
  pr = await stalePost({ 'Sec-Fetch-Site': 'same-site' }); t('Sec-Fetch-Site: same-site → refusé', pr.status === 403);
  pr = await stalePost({ 'Sec-Fetch-Site': 'same-origin', Origin: BASE }); t('Sec-Fetch-Site: same-origin + Origin du site → accepté', pr.status === 303);
  pr = await stalePost({ Referer: 'https://evil.example/page' }); t('sans Origin, Referer d\'un autre site → refusé', pr.status === 403);
  pr = await stalePost({ Referer: `${BASE}/auth` }); t('sans Origin, Referer du site → accepté', pr.status === 303);
  pr = await c.req('POST', `${BASE}/auth/update`, { form: { csrf: 'f'.repeat(64), ref: 'x' }, headers: {} }); t('refus: message explicatif pour l\'administrateur connecté', pr.status === 403 && /Requête refusée/.test(pr.text) && /rechargez \/auth/.test(pr.text));
  t('POST depuis une autre origine → 403', (await c.req('POST', `${BASE}/auth/update`, { form: { csrf: csrfOf(page.text), ref: 'main' }, headers: { Origin: 'https://evil.example' } })).status === 403);
  const sid = c.jar.radiv_admin; const sf = path.join(WWW, 'backend/data/var/sessions', `sess_${sid}`);
  fs.writeFileSync(sf, fs.readFileSync(sf, 'utf8').replace(/s:2:"at";i:\d+;/, `s:2:"at";i:${Math.floor(Date.now() / 1000) - 700};`));
  const stale = await c.req('POST', `${BASE}/auth/update`, { form: { csrf: csrfOf(page.text), ref: 'main', token: TOKEN }, headers: { Origin: BASE } });
  t('connexion ancienne → nouvelle authentification Google exigée', stale.status === 302 && /\/auth\?reauth=1$/.test(stale.loc), `${stale.status} ${stale.loc}`);
  t('…et rien n\'a été installé', JSON.parse(read('backend/data/var/version.json')).sha === 'a'.repeat(40));
  const re = await c.req('GET', stale.loc); t('reconnexion: prompt=login envoyé à Google', /prompt=login/.test(re.loc));
  await c.req('GET', (await c.req('GET', re.loc)).loc);
  page = await c.req('GET', `${BASE}/auth`); t('reconnecté', page.status === 200);
  const out = await c.req('POST', `${BASE}/auth/logout`, { form: { csrf: csrfOf(page.text) }, headers: { Origin: BASE } });
  const afterOut = await c.req('GET', `${BASE}/auth`);
  t('déconnexion: session détruite (retour à la connexion Google, plus de page ni de mesures)', out.status === 200 && afterOut.status === 302 && afterOut.loc.startsWith(G) && (await c.req('GET', `${BASE}/auth/mesures`)).status === 404);

  console.log('\n— Jeton dans le .env');
  resetLimits(); setEnv('UPDATE_GITHUB_TOKEN', TOKEN); c = new Client(); await c.login(); page = await c.req('GET', `${BASE}/auth`);
  t('jeton valide du .env → champ masqué', !/name="token"/.test(page.text)); t('tags proposés', /<option value="v1.2">/.test(page.text) && /<option value="v1.1">/.test(page.text));
  r = await update(c, 'v1.1', null); t('mise à jour sans saisie de jeton', /1111111/.test(r.msg), r.msg);
  resetLimits(); setEnv('UPDATE_GITHUB_TOKEN', 'ghp_envtokenrefuseenvtokenrefuse1'); c = new Client(); await c.login(); page = await c.req('GET', `${BASE}/auth`);
  t('jeton du .env refusé par GitHub → le champ réapparaît', /name="token"/.test(page.text));
  r = await update(c, 'main', TOKEN); t('…et un jeton saisi fonctionne', /2222222/.test(r.msg), r.msg);
  setEnv('UPDATE_GITHUB_TOKEN', null);

  console.log('\n— Mises à jour désactivées');
  resetLimits(); setEnv('ADMIN_UPDATE_ENABLED', 'false'); page = await c.req('GET', `${BASE}/auth`);
  t('page: mises à jour désactivées, pas de formulaire', /désactivées/.test(page.text) && !/action="[^"]*\/admin\/update"/.test(page.text));
  t('POST /update → 404', (await c.req('POST', `${BASE}/auth/update`, { form: { csrf: csrfOf(page.text), ref: 'main' }, headers: { Origin: BASE } })).status === 404);
  setEnv('ADMIN_UPDATE_ENABLED', 'true');

  console.log('\n— Page non configurée');
  setEnv('GOOGLE_CLIENT_SECRET', null); resetLimits();
  t('sans secret Google → /auth en 404', (await new Client().req('GET', `${BASE}/auth`)).status === 404 && (await c.req('GET', `${BASE}/auth`)).status === 404);
  setEnv('GOOGLE_CLIENT_SECRET', 'test-secret');

  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
