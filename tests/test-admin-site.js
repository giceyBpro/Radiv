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

function treeHash() { // tout ce que la mise à jour a le droit de modifier
  const h = crypto.createHash('sha256'); const files = [];
  const walk = (d) => { for (const n of fs.readdirSync(d, { withFileTypes: true }).sort((x, y) => x.name.localeCompare(y.name))) {
    const p = path.join(d, n.name); const rel = path.relative(WWW, p);
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

  console.log('\n— Espace disque : purge des journaux les plus anciens au-delà de 85 %');
  const envDisk = runtimeEnv(); const logsDir = path.join(WWW, 'backend/data/logs');
  const treeBytes = (d) => fs.readdirSync(d, { withFileTypes: true }).reduce((n, e) => n + (e.isDirectory() ? treeBytes(path.join(d, e.name)) : fs.statSync(path.join(d, e.name)).size), 0);
  for (const f of ['measurements-2025-01.jsonl', 'measurements-2025-02.jsonl']) fs.writeFileSync(path.join(logsDir, f), 'x'.repeat(250 * 1024));
  const quotaMb = Math.ceil(treeBytes(WWW) / 1048576 / 0.9); // le site occupe ≈ 90 % du quota
  setEnv('DISK_QUOTA_MB', String(quotaMb)); resetLimits(); try { fs.unlinkSync(path.join(logsDir, '.last-disk-check')); } catch (e) { /* absent */ }
  const diskPage = await c.req('GET', `${BASE}/auth`);
  t('page Site : occupation du quota affichée', /Espace disque/.test(diskPage.text) && /quota de l'hébergement/.test(diskPage.text) && /\d+(,\d)? %/.test(diskPage.text) && /85 %/.test(diskPage.text));
  const before = fs.readdirSync(logsDir).filter((f) => f.startsWith('measurements-')).sort();
  const diskCalc = await fetch(`${BASE}/api/calculate`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 'local' }) });
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
  const afterRescue = treeHash();
  // Idempotence : même version relancée = même état, et le retour arrière de /auth vers la version d'avant est conservé
  dropUpdate(); resetLimits(); u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('relance avec la même version : succès, signalée comme réparation', u.status === 200 && /déjà installée/.test(u.text) && !exists('update.php'));
  t('état identique après la relance (idempotent)', treeHash() === afterRescue);
  t('retour arrière de /auth toujours possible vers la version précédente', /Rétablir la version précédente/.test((await c.req('GET', `${BASE}/auth`)).text));
  // Secours : code du site endommagé, éléments de protection manquants
  fs.writeFileSync(path.join(WWW, 'backend/src/lib/updater.php'), '<?php this is not php {{{');
  fs.unlinkSync(path.join(WWW, 'backend/.htaccess')); fs.unlinkSync(path.join(WWW, 'backend/data/.htaccess')); fs.unlinkSync(path.join(WWW, 'app.js'));
  fs.writeFileSync(path.join(WWW, '.htaccess'), read('.htaccess').replace(/# \/backend \(backend : accès refusé\) v1\n[^\n]*\n[^\n]*\n\n/, ''));
  dropUpdate(); resetLimits(); u = await up({ key: UKEY, ref: 'main', token: TOKEN });
  t('secours : le site endommagé est réparé (le script ne dépend pas du code installé)', u.status === 200 && /2222222/.test(u.text) && !exists('update.php'), u.text.replace(/<[^>]+>/g, ' ').slice(0, 300));
  t('…fichiers de la version, .htaccess du backend et des données restaurés', read('app.js').includes('fixture v1.2') && /Require all denied/.test(read('backend/.htaccess')) && !read('backend/src/lib/updater.php').includes('this is not php') && /Require all denied/.test(read('backend/data/.htaccess')));
  t('…règle d\'interdiction du backend rétablie dans le .htaccess racine, une seule fois', (read('.htaccess').match(/backend : accès refusé/g) || []).length === 1);
  t('…état identique à avant l\'incident', treeHash() === afterRescue);
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

  console.log('\n— Fichiers hors liste blanche (.env, .htaccess, config.js, tests...)');
  const cfgBefore = read('config.js'); const htBefore = read('.htaccess'); resetLimits();
  r = await update(c, 't10', TOKEN);
  t('installée', /aaaaaaa/.test(r.msg), r.msg);
  t('vendor/: fichiers statiques installés, jamais de PHP ni de dossier trop profond', exists('vendor/ok.css') && exists('vendor/fonts/ok.woff2') && !exists('vendor/evil.php') && !exists('vendor/a/b/c.js') && !exists('vendor/.htaccess') && !exists('vendor/fonts/evil.phtml'));
  t('config.js et .htaccess racine inchangés', read('config.js') === cfgBefore && read('.htaccess') === htBefore);
  t('config, données, outillage, pointeur et scripts hors liste blanche jamais écrits', !exists('.env') && !exists('backend/config/local.env') && !exists('deploy.sh') && !exists('update.php.bak') && !exists('backend/data/logs/x.jsonl') && !exists('tests/x.php') && !exists('backend/evil.php') && !exists('backend/src/x.txt') && !exists('backend/src/lib/sub/x.php') && !exists('api/evil.php') && !exists('api/backend.php.pirate') && !read('backend/config/runtime.env').includes('X=1') && !exists('vendor/ok.css.x'));
  t('pointeur du backend (api/backend.php) non remplacé par la version cible', !exists('api/backend.php') || !read('api/backend.php').includes('pirate'));
  t('.runtime.env intact', runtimeEnv() === envBefore);

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
