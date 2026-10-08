// Test de bout en bout de la page d'administration (connexion Google + mises à jour), contre un
// site déployé (Apache + mod_php) et api/tests/fake-services.js. Préparation: voir README.
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
  async login() {
    const a = await this.req('GET', `${BASE}/api/admin/login`);
    const b = await this.req('GET', a.loc); // Google approuve et renvoie vers le callback
    const c = await this.req('GET', b.loc);
    return { a, b, c };
  }
}
const ctl = (q) => fetch(`${G}/ctl?${q}`).then((r) => r.text());
const ghLog = () => fetch(`${H}/ctl/log`).then((r) => r.json());
const resetLimits = () => { for (const f of fs.readdirSync(path.join(WWW, 'api/var'))) if (f.startsWith('ratelimit-')) fs.unlinkSync(path.join(WWW, 'api/var', f)); };
const csrfOf = (html) => (html.match(/name="csrf" value="([0-9a-f]+)"/) || [])[1];
const flash = (html) => ((html.match(/<p class="msg (?:ok|ko)">([^<]*)</) || [])[1] || '').replace(/&#39;/g, "'");

function treeHash() { // tout ce que la mise à jour a le droit de modifier
  const h = crypto.createHash('sha256'); const files = [];
  const walk = (d) => { for (const n of fs.readdirSync(d, { withFileTypes: true }).sort((x, y) => x.name.localeCompare(y.name))) {
    const p = path.join(d, n.name); const rel = path.relative(WWW, p);
    if (rel === 'api/var' || rel === 'api/logs' || rel === 'api/.runtime.env') continue;
    n.isDirectory() ? walk(p) : files.push(rel);
  } };
  walk(WWW); files.forEach((f) => { h.update(f); h.update(fs.readFileSync(path.join(WWW, f))); }); return h.digest('hex');
}
const exists = (rel) => fs.existsSync(path.join(WWW, rel));
const read = (rel) => fs.readFileSync(path.join(WWW, rel), 'utf8');
const runtimeEnv = () => fs.readFileSync(path.join(WWW, 'api/.runtime.env'), 'utf8');
const setEnv = (key, value) => { const f = path.join(WWW, 'api/.runtime.env'); const lines = fs.readFileSync(f, 'utf8').split('\n').filter((l) => !l.startsWith(`${key}=`)); if (value !== null) lines.push(`${key}="${value}"`); fs.writeFileSync(f, lines.join('\n')); };
const tmpClean = () => !exists('api/var/tmp') || fs.readdirSync(path.join(WWW, 'api/var/tmp')).length === 0;

async function update(c, ref, token) {
  const page = await c.req('GET', `${BASE}/api/admin/site`);
  const r = await c.req('POST', `${BASE}/api/admin/update`, { form: { csrf: csrfOf(page.text), ref, ...(token ? { token } : {}) }, headers: { Origin: BASE } });
  const after = await c.req('GET', r.loc || `${BASE}/api/admin/site`);
  return { post: r, msg: flash(after.text), html: after.text };
}

(async () => {
  resetLimits(); await ctl('email=admin@example.org&verified=1&aud=test-client-id.apps.googleusercontent.com');
  const base = treeHash(); const envBefore = runtimeEnv();

  console.log('\n— Accès sans connexion');
  let c = new Client();
  t('GET /site sans session → 404', (await c.req('GET', `${BASE}/api/admin/site`)).status === 404);
  t('POST /update sans session → 404', (await c.req('POST', `${BASE}/api/admin/update`, { form: { ref: 'main' } })).status === 404);
  t('POST /logout sans session → 404', (await c.req('POST', `${BASE}/api/admin/logout`, { form: {} })).status === 404);

  console.log('\n— Connexion Google: refus');
  for (const [label, q] of [['adresse non autorisée', 'email=intrus@example.org'], ['adresse non vérifiée', 'email=admin@example.org&verified=0'], ['mauvaise audience', 'email=admin@example.org&verified=1&aud=autre-client']]) {
    await ctl(q); c = new Client(); const { c: cb } = await c.login();
    t(`${label} → 403`, cb.status === 403); t(`${label}: pas de session`, (await c.req('GET', `${BASE}/api/admin/site`)).status === 404);
  }
  await ctl('email=admin@example.org&verified=1&aud=test-client-id.apps.googleusercontent.com');
  c = new Client(); const a = await c.req('GET', `${BASE}/api/admin/login`); const b = await c.req('GET', a.loc);
  t('state falsifié → 403', (await c.req('GET', b.loc.replace(/state=[^&]+/, 'state=faux'))).status === 403);
  resetLimits(); c = new Client(); const a2 = await c.req('GET', `${BASE}/api/admin/login`); const b2 = await c.req('GET', a2.loc);
  t('callback sans cookie de session → 403', (await new Client().req('GET', b2.loc)).status === 403);
  t('callback valide → 303', (await c.req('GET', b2.loc)).status === 303);
  t('rejeu du même callback → 403', (await c.req('GET', b2.loc)).status === 403);

  console.log('\n— Connexion Google: succès');
  resetLimits(); c = new Client(); const lg = await c.login();
  t('URL Google: PKCE S256 + nonce + state', /code_challenge_method=S256/.test(lg.a.loc) && /nonce=/.test(lg.a.loc) && /state=/.test(lg.a.loc));
  t('connexion acceptée → 303 vers la page', lg.c.status === 303 && /\/api\/admin\/site$/.test(lg.c.loc));
  const cookie = lg.c.setCookie.join(' | ');
  t('cookie HttpOnly + SameSite=Lax + Path=/api/admin', /HttpOnly/i.test(cookie) && /SameSite=Lax/i.test(cookie) && /path=\/api\/admin/i.test(cookie), cookie);
  let page = await c.req('GET', `${BASE}/api/admin/site`);
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
  const version = JSON.parse(read('api/var/version.json'));
  t('version enregistrée (sha, ref, par)', version.sha === '1'.repeat(40) && version.ref === 'v1.1' && version.by === 'admin@example.org');
  const log = await ghLog();
  t("jeton envoyé à l'API GitHub", log.filter((l) => l.path.startsWith('/repos')).every((l) => l.auth === 'present'));
  t('jeton NON transmis au téléchargement redirigé', log.filter((l) => l.path.startsWith('/codeload')).every((l) => l.auth === 'absent') && log.some((l) => l.path.startsWith('/codeload')));
  t('archive et préparation supprimées', tmpClean());
  t('.runtime.env intact', runtimeEnv() === envBefore); t('config.js / .htaccess racine intacts', read('config.js').includes('RADIOPROTECTION') && read('.htaccess').includes('RewriteEngine'));
  t('jeton jamais écrit sur le disque', !fs.readdirSync(path.join(WWW, 'api/var')).some((f) => !fs.statSync(path.join(WWW, 'api/var', f)).isDirectory() && read(`api/var/${f}`).includes(TOKEN)) && !fs.readdirSync(path.join(WWW, 'api/logs')).some((f) => read(`api/logs/${f}`).includes(TOKEN)));
  const journal = fs.readFileSync(path.join(WWW, 'api/logs/updates.jsonl'), 'utf8');
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
  t('version inchangée', JSON.parse(read('api/var/version.json')).sha === '1'.repeat(40));
  t('API opérationnelle après retour arrière', (await fetch(`${BASE}/api/config`)).status === 200);

  console.log('\n— Mise à jour v1.2 puis retour arrière manuel');
  resetLimits(); r = await update(c, 'main', TOKEN);
  t('v1.2 installée', /2222222/.test(r.msg), r.msg); t('app.js en v1.2', read('app.js').includes('fixture v1.2')); t('fichier retiré par la mise à jour', !exists('downloads/ajout-v1_1.xml'));
  page = await c.req('GET', `${BASE}/api/admin/site`); t('bouton de retour arrière proposé', /Rétablir la version précédente/.test(page.text));
  const rb = await c.req('POST', `${BASE}/api/admin/rollback`, { form: { csrf: csrfOf(page.text) }, headers: { Origin: BASE } });
  const rbPage = await c.req('GET', rb.loc);
  t('retour arrière effectué', /Version précédente rétablie/.test(flash(rbPage.text)), flash(rbPage.text));
  t('état exactement identique à la v1.1', treeHash() === afterGood); t('version = v1.1', JSON.parse(read('api/var/version.json')).sha === '1'.repeat(40));
  page = await c.req('GET', `${BASE}/api/admin/site`); t('plus de retour arrière possible', !/Rétablir la version précédente/.test(page.text));

  console.log('\n— Fichiers hors liste blanche (.env, .htaccess, config.js, tests...)');
  const cfgBefore = read('config.js'); const htBefore = read('.htaccess'); resetLimits();
  r = await update(c, 't10', TOKEN);
  t('installée', /aaaaaaa/.test(r.msg), r.msg);
  t('config.js et .htaccess racine inchangés', read('config.js') === cfgBefore && read('.htaccess') === htBefore);
  t('.env / api/.env / deploy-php.sh / api/logs/x / api/tests absents', !exists('.env') && !exists('api/.env') && !exists('deploy-php.sh') && !exists('api/logs/x.jsonl') && !exists('api/tests/x.php'));
  t('.runtime.env intact', runtimeEnv() === envBefore);

  console.log('\n— CSRF, connexion récente, déconnexion');
  page = await c.req('GET', `${BASE}/api/admin/site`);
  t('POST sans jeton CSRF → 403', (await c.req('POST', `${BASE}/api/admin/update`, { form: { ref: 'main' }, headers: { Origin: BASE } })).status === 403);
  t('POST avec mauvais jeton CSRF → 403', (await c.req('POST', `${BASE}/api/admin/update`, { form: { csrf: 'f'.repeat(64), ref: 'main' }, headers: { Origin: BASE } })).status === 403);
  t('POST depuis une autre origine → 403', (await c.req('POST', `${BASE}/api/admin/update`, { form: { csrf: csrfOf(page.text), ref: 'main' }, headers: { Origin: 'https://evil.example' } })).status === 403);
  const sid = c.jar.radiv_admin; const sf = path.join(WWW, 'api/var/sessions', `sess_${sid}`);
  fs.writeFileSync(sf, fs.readFileSync(sf, 'utf8').replace(/s:2:"at";i:\d+;/, `s:2:"at";i:${Math.floor(Date.now() / 1000) - 700};`));
  const stale = await c.req('POST', `${BASE}/api/admin/update`, { form: { csrf: csrfOf(page.text), ref: 'main', token: TOKEN }, headers: { Origin: BASE } });
  t('connexion ancienne → nouvelle authentification Google exigée', stale.status === 302 && /admin\/login\?reauth=1/.test(stale.loc), `${stale.status} ${stale.loc}`);
  t('…et rien n\'a été installé', JSON.parse(read('api/var/version.json')).sha === 'a'.repeat(40));
  const re = await c.req('GET', stale.loc); t('reconnexion: prompt=login envoyé à Google', /prompt=login/.test(re.loc));
  await c.req('GET', (await c.req('GET', re.loc)).loc);
  page = await c.req('GET', `${BASE}/api/admin/site`); t('reconnecté', page.status === 200);
  const out = await c.req('POST', `${BASE}/api/admin/logout`, { form: { csrf: csrfOf(page.text) }, headers: { Origin: BASE } });
  t('déconnexion', out.status === 200 && (await c.req('GET', `${BASE}/api/admin/site`)).status === 404);

  console.log('\n— Jeton dans le .env');
  resetLimits(); setEnv('UPDATE_GITHUB_TOKEN', TOKEN); c = new Client(); await c.login(); page = await c.req('GET', `${BASE}/api/admin/site`);
  t('jeton valide du .env → champ masqué', !/name="token"/.test(page.text)); t('tags proposés', /<option value="v1.2">/.test(page.text) && /<option value="v1.1">/.test(page.text));
  r = await update(c, 'v1.1', null); t('mise à jour sans saisie de jeton', /1111111/.test(r.msg), r.msg);
  resetLimits(); setEnv('UPDATE_GITHUB_TOKEN', 'ghp_envtokenrefuseenvtokenrefuse1'); c = new Client(); await c.login(); page = await c.req('GET', `${BASE}/api/admin/site`);
  t('jeton du .env refusé par GitHub → le champ réapparaît', /name="token"/.test(page.text));
  r = await update(c, 'main', TOKEN); t('…et un jeton saisi fonctionne', /2222222/.test(r.msg), r.msg);
  setEnv('UPDATE_GITHUB_TOKEN', null);

  console.log('\n— Mises à jour désactivées');
  resetLimits(); setEnv('ADMIN_UPDATE_ENABLED', 'false'); page = await c.req('GET', `${BASE}/api/admin/site`);
  t('page: mises à jour désactivées, pas de formulaire', /désactivées/.test(page.text) && !/action="[^"]*\/admin\/update"/.test(page.text));
  t('POST /update → 404', (await c.req('POST', `${BASE}/api/admin/update`, { form: { csrf: csrfOf(page.text), ref: 'main' }, headers: { Origin: BASE } })).status === 404);
  setEnv('ADMIN_UPDATE_ENABLED', 'true');

  console.log('\n— Page non configurée');
  setEnv('GOOGLE_CLIENT_SECRET', null); resetLimits();
  t('sans secret Google → /login et /site en 404', (await new Client().req('GET', `${BASE}/api/admin/login`)).status === 404 && (await c.req('GET', `${BASE}/api/admin/site`)).status === 404);
  setEnv('GOOGLE_CLIENT_SECRET', 'test-secret');

  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
