// Test de bout en bout de install.php sur un Apache vierge (port 8083) avec le faux GitHub de
// fake-services.js. Usage: node test-install.js <racine_web_vierge> <dossier_zips> <repo>
const fs = require('fs'); const path = require('path'); const crypto = require('crypto');
const WWW = process.argv[2]; const REPO_DIR = process.argv[4]; const BASE = 'http://127.0.0.1:8083';
const KEY = 'cle-de-test-installation-0123456789abcdef'; const TOKEN = 'ghp_validtokenvalidtokenvalid1234';
const SECRET_SMTP = 'p@ss"w0rd!'; const GSECRET = 'google-secret-value-123';
let pass = 0; let fail = 0;
const t = (name, cond, detail = '') => { if (cond) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };
const src = fs.readFileSync(path.join(REPO_DIR, 'install.php'), 'utf8');

function deployInstaller({ key = KEY, extra = {}, envConst = null } = {}) { // dossier vierge + install.php « préparé » par l'utilisateur
  fs.rmSync(WWW, { recursive: true, force: true }); fs.mkdirSync(WWW, { recursive: true });
  let code = src.replace("const INSTALL_KEY = 'CHANGEZ-MOI';", `const INSTALL_KEY = '${key}';`).replace("const GITHUB_API = 'https://api.github.com';", "const GITHUB_API = 'http://127.0.0.1:9202';");
  if (envConst !== null) code = code.replace("const ENV_FILE = '../.env';", `const ENV_FILE = '${envConst}';`);
  fs.writeFileSync(path.join(WWW, 'install.php'), code);
  for (const [f, c] of Object.entries(extra)) { fs.mkdirSync(path.dirname(path.join(WWW, f)), { recursive: true }); fs.writeFileSync(path.join(WWW, f), c); }
  require('child_process').execSync(`chown -R www-data:www-data ${WWW}; chmod -R u+rwX,go+rX ${WWW}`);
}
const post = async (fields) => { const r = await fetch(`${BASE}/install.php`, { method: 'POST', body: new URLSearchParams(fields), redirect: 'manual' }); return { status: r.status, text: await r.text(), headers: r.headers }; };
const listing = () => { const out = []; const walk = (d) => { for (const n of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, n.name); if (n.name.startsWith('.install-attempts')) continue; n.isDirectory() ? walk(p) : out.push(path.relative(WWW, p)); } }; walk(WWW); return out.sort(); };
const snapshot = () => crypto.createHash('sha256').update(listing().map((f) => f + fs.readFileSync(path.join(WWW, f))).join('\0')).digest('hex');
const form = (over = {}) => ({ step: 'install', key: KEY, repo: 'giceyBpro/Radiv', ref: 'v1.1', token: TOKEN, site_url: BASE,
  google_id: 'cid.apps.googleusercontent.com', google_secret: GSECRET, admin_emails: 'admin@example.org, second@example.org', update_enabled: '1',
  recaptcha_site: 'sitekey', recaptcha_secret: 'recaptchasecret', smtp_host: 'smtp.example.org', smtp_port: '587', smtp_user: 'contact@example.org', smtp_pass: SECRET_SMTP,
  smtp_from: 'Site <no-reply@example.org>', contact_dest: 'dest@example.org', rounding: 'round', logging: 'full', retention: '3', logs_mb: '5', ...over });
const msg = (html) => (html.match(/<p class="msg">([^<]*)</) || [])[1] || '';

(async () => {
  console.log('— Accès à l\'installeur');
  deployInstaller({ key: 'CHANGEZ-MOI' });
  let r = await fetch(`${BASE}/install.php`); t('clé non définie → 403, rien d\'autre', r.status === 403 && /Aucune clé/.test(await r.text()));
  deployInstaller({ key: 'trop-courte' }); r = await fetch(`${BASE}/install.php`); t('clé trop courte → 403', r.status === 403);
  deployInstaller(); r = await fetch(`${BASE}/install.php`); let html = await r.text();
  t('GET: simple formulaire de clé, aucune information sur l\'hébergement', r.status === 200 && /name="key"/.test(html) && !/PHP \d|curl|zip/i.test(html.replace(/<style[\s\S]*?<\/style>/, '')));
  t('en-têtes: noindex, CSP, no-store', /noindex/.test(r.headers.get('x-robots-tag')) && /default-src 'none'/.test(r.headers.get('content-security-policy')) && /no-store/.test(r.headers.get('cache-control')));
  r = await post({ step: 'check', key: 'mauvaise' }); t('clé incorrecte → 403', r.status === 403 && /incorrecte/.test(r.text));
  r = await post({ ...form(), key: 'mauvaise' }); t('installation avec mauvaise clé → 403, rien écrit', r.status === 403 && listing().join() === 'install.php');
  r = await post({ step: 'install' }); t('installation sans clé → 403', r.status === 403);
  r = await post({ step: 'nimporte', key: KEY }); t('étape inconnue avec bonne clé → 404', r.status === 404);

  console.log('\n— Diagnostic');
  r = await post({ step: 'check', key: KEY }); html = r.text;
  t('diagnostic affiché (PHP, extensions, écriture, GitHub)', r.status === 200 && /État de l'hébergement/.test(html) && />OK</.test(html) && /Accès sortant vers GitHub/.test(html));
  t('formulaire d\'installation proposé, clé reportée en champ caché', /name="step" value="install"/.test(html) && html.includes(`name="key" value="${KEY}"`));
  t('aucune erreur critique', !/class="ko"/.test(html), (html.match(/class="ko"[^<]*<\/td><td>[^<]*/) || [''])[0]);

  console.log('\n— Saisies invalides (rien ne doit être écrit)');
  const cases = [['dépôt invalide', { repo: 'pas un depot' }], ['version invalide', { ref: '../x' }], ['jeton de forme invalide', { token: 'court' }], ['adresse sans https', { site_url: 'http://exemple.fr' }],
    ['adresse avec chemin', { site_url: 'https://exemple.fr/chemin' }], ['e-mail administrateur invalide', { admin_emails: 'pas-un-mail' }], ['Google incomplet', { google_secret: '' }],
    ['reCAPTCHA incomplet', { recaptcha_secret: '' }], ['SMTP incomplet', { smtp_user: '' }], ['port SMTP invalide', { smtp_port: 'abc' }], ['destinataire invalide', { contact_dest: 'x' }],
    ['arrondi invalide', { rounding: 'ceil' }], ['journalisation invalide', { logging: 'tout' }], ['conservation invalide', { retention: '-1' }], ['saut de ligne dans une valeur', { smtp_from: 'a\nINJECT=1' }]];
  for (const [label, over] of cases) { r = await post(form(over)); t(`${label} → refusé, formulaire réaffiché`, r.status === 400 && /name="step" value="install"/.test(r.text) && listing().join() === 'install.php', `${r.status} ${msg(r.text)}`); }
  t('les secrets saisis ne sont pas renvoyés dans le formulaire réaffiché', !(await post(form({ repo: 'x' }))).text.includes(GSECRET) && !(await post(form({ repo: 'x' }))).text.includes(TOKEN));

  console.log('\n— Échecs côté GitHub / archive (le site ne doit pas changer)');
  const before = snapshot();
  r = await post(form({ token: 'ghp_mauvaisjetonmauvaisjeton12345' })); t('jeton refusé par GitHub → interrompu', r.status === 500 && /jeton n(?:'|&#0?39;)est pas valide/.test(r.text) && snapshot() === before);
  r = await post(form({ ref: 'inexistant' })); t('version introuvable → interrompu', r.status === 500 && /introuvable/.test(r.text) && snapshot() === before);
  for (const [ref, re, label] of [['t3', /chemin invalide/, 'traversée de chemin'], ['t4', /lien symbolique/, 'lien symbolique'], ['t5', /syntaxe PHP/, 'erreur de syntaxe'], ['t6', /ne correspondant pas/, 'mauvais dossier racine'], ['t7', /incomplète ou trop ancienne/, 'version sans administration'], ['t8', /taille/, 'fichier trop gros']]) {
    r = await post(form({ ref })); t(`${label} → interrompu, site inchangé`, r.status === 500 && re.test(r.text) && snapshot() === before, msg(r.text));
    t(`${label}: dossier temporaire supprimé`, !fs.readdirSync(WWW).some((n) => n.startsWith('.install-tmp')));
  }
  t('aucun fichier hors de la liste blanche (.env, evil.php...)', listing().join() === 'install.php');

  console.log('\n— Échec d\'écriture en cours d\'installation: retour arrière complet');
  deployInstaller({ extra: { 'app.js': 'ANCIEN app.js', 'contact.html': 'ANCIEN contact', downloads: 'je suis un fichier, pas un dossier' } });
  const pre = snapshot(); r = await post(form());
  t('installation interrompue', r.status === 500 && /Installation interrompue/.test(r.text), msg(r.text));
  t('fichiers préexistants remis tels quels', fs.readFileSync(path.join(WWW, 'app.js'), 'utf8') === 'ANCIEN app.js' && fs.readFileSync(path.join(WWW, 'contact.html'), 'utf8') === 'ANCIEN contact');
  t('rien d\'autre ne reste (état identique à avant)', snapshot() === pre && !fs.existsSync(path.join(WWW, 'api')) && !fs.readdirSync(WWW).some((n) => n.startsWith('.install-tmp')), listing().join());

  console.log('\n— Version qui ne démarre pas: avertissement, installeur conservé, nouvel essai possible');
  deployInstaller({ extra: { '.htaccess': '# regle de l\'hebergeur\nAddDefaultCharset UTF-8\n', 'index.html': 'PAGE D\'ATTENTE' } });
  r = await post(form({ ref: 't9' }));
  t('avertissement (API qui ne répond pas)', /avertissement/.test(r.text) && /Échec/.test(r.text), msg(r.text));
  t('install.php conservé, pas de verrou', fs.existsSync(path.join(WWW, 'install.php')) && !fs.existsSync(path.join(WWW, 'api/var/install.done')));

  console.log('\n— Installation réussie (v1.1) avec fichiers préexistants de l\'hébergeur');
  r = await post(form()); html = r.text;
  t('site installé', r.status === 200 && /Site installé/.test(html) && /1111111/.test(html), msg(html));
  t('contrôles: API, santé, administration', (html.match(/class="ok">OK</g) || []).length >= 3 && !/class="ko"/.test(html), (html.match(/<table>[\s\S]*<\/table>/) || [''])[0].replace(/<[^>]+>/g, ' ').slice(0, 300));
  t('install.php supprimé', !fs.existsSync(path.join(WWW, 'install.php')) && /a été supprimé/.test(html));
  t('install.php de l\'archive NON installé (liste blanche)', !fs.existsSync(path.join(WWW, 'install.php')));
  t('verrou posé', fs.existsSync(path.join(WWW, 'api/var/install.done')));
  t('app.js de la version v1.1 installé', fs.readFileSync(path.join(WWW, 'app.js'), 'utf8').includes('fixture v1.1')); t('index.html de la page d\'attente remplacé', fs.readFileSync(path.join(WWW, 'index.html'), 'utf8') !== 'PAGE D\'ATTENTE');
  const cfg = fs.readFileSync(path.join(WWW, 'config.js'), 'utf8');
  t('config.js généré', cfg.includes(`RADIOPROTECTION_API_URL = "${BASE}/api"`) && cfg.includes(`RADIOPROTECTION_SITE_URL = "${BASE}"`));
  t('sitemap.xml et ligne Sitemap de robots.txt', fs.readFileSync(path.join(WWW, 'sitemap.xml'), 'utf8').includes(`<loc>${BASE}/</loc>`) && /^Sitemap: /m.test(fs.readFileSync(path.join(WWW, 'robots.txt'), 'utf8')));
  const ht = fs.readFileSync(path.join(WWW, '.htaccess'), 'utf8');
  t('.htaccess: règle de l\'hébergeur conservée', ht.includes('AddDefaultCharset UTF-8'));
  t('.htaccess: /health, /auth et raccourcis ajoutés', ht.includes('RewriteRule ^health/?$ api/index.php') && ht.includes('RewriteRule ^auth(/.*)?$ api/index.php') && ht.includes('RewriteRule ^legal/?$ mentions-legales.html'));
  t('.htaccess: en-têtes de sécurité (une seule fois), pas de HSTS ni de redirection en http local', ht.includes('# En-têtes de sécurité des pages v1') && ht.split('# En-têtes de sécurité').length === 2 && !ht.includes('Strict-Transport-Security') && !ht.includes('R=308'));
  const pageHdr = (await fetch(`${BASE}/index.html`)).headers; const apiHdr = (await fetch(`${BASE}/api/config`)).headers;
  t('pages: en-têtes de sécurité posés', pageHdr.get('x-content-type-options') === 'nosniff' && pageHdr.get('x-frame-options') === 'DENY' && /strict-origin-when-cross-origin/.test(pageHdr.get('referrer-policy') || '') && /frame-ancestors 'none'/.test(pageHdr.get('content-security-policy') || '') && /camera=\(\)/.test(pageHdr.get('permissions-policy') || ''));
  t('API: ses propres en-têtes restent prioritaires (CSP stricte, pas de doublon)', apiHdr.get('content-security-policy') === "default-src 'none'; frame-ancestors 'none'" && apiHdr.get('referrer-policy') === 'no-referrer' && apiHdr.get('x-frame-options') === 'DENY');
  t('polices et bibliothèques hébergées installées (vendor/)', fs.existsSync(path.join(WWW, 'vendor/fonts.css')) === fs.existsSync(path.join(REPO_DIR, 'vendor/fonts.css')));
  const envFile = path.join(WWW, 'api/.runtime.env'); const env = fs.readFileSync(envFile, 'utf8');
  t('.runtime.env en 600', (fs.statSync(envFile).mode & 0o777) === 0o600);
  t('.runtime.env: valeurs et guillemets (mot de passe avec ")', env.includes(`SMTP_PASS='${SECRET_SMTP}'`) && env.includes(`GOOGLE_CLIENT_SECRET="${GSECRET}"`) && env.includes('ADMIN_GOOGLE_EMAILS="admin@example.org, second@example.org"') && env.includes('LOGS_MAX_BYTES="5242880"') && env.includes('SFMN_MODE_ENABLED="false"') && env.includes('ADMIN_UPDATE_ENABLED="true"'));
  t('.runtime.env: pas de jeton GitHub, SFMN sans URL (variables seulement en commentaire « #NOM= »)', !env.includes(TOKEN) && !/^UPDATE_GITHUB_TOKEN=/m.test(env) && !/^SFMN_CALCULATOR_URL=/m.test(env) && /^#UPDATE_GITHUB_TOKEN=$/m.test(env));
  t('.runtime.env lisible: sections et commentaires', /^# Site et API$/m.test(env) && /^# Formulaire de contact$/m.test(env) && /\n\n# Adresses Google autorisées/.test(env) && /^# Généré par install\.php/m.test(env));
  const everything = []; const walk = (d) => { for (const n of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, n.name); n.isDirectory() ? walk(p) : everything.push(p); } }; walk(WWW);
  t('jeton GitHub jamais écrit sur le disque', !everything.some((f) => fs.readFileSync(f).includes(TOKEN)));
  const ver = JSON.parse(fs.readFileSync(path.join(WWW, 'api/var/version.json'), 'utf8'));
  t('version.json et manifest.json pour les mises à jour futures', ver.sha === '1'.repeat(40) && ver.ref === 'v1.1' && JSON.parse(fs.readFileSync(path.join(WWW, 'api/var/manifest.json'), 'utf8')).files.includes('api/index.php'));
  t('journal: opération install', /"action":"install"/.test(fs.readFileSync(path.join(WWW, 'api/logs/updates.jsonl'), 'utf8')));
  t('logs/ et var/ interdits d\'accès HTTP', (await fetch(`${BASE}/api/logs/updates.jsonl`)).status === 403 && (await fetch(`${BASE}/api/var/version.json`)).status === 403 && (await fetch(`${BASE}/api/.runtime.env`)).status === 403);
  t('GET /api/config → 200', (await fetch(`${BASE}/api/config`)).status === 200);
  const calc = await (await fetch(`${BASE}/api/calculate`, { method: 'POST', body: JSON.stringify({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 'local' }) })).json();
  t('POST /api/calculate fonctionne (valeur de référence 38 j)', calc.ok === true && calc.recommendations_days.collegues_travail === 38);
  t('GET /health → 200', (await fetch(`${BASE}/health`)).status === 200);
  const au = await fetch(`${BASE}/auth`, { redirect: 'manual' }); t('/auth redirige vers Google', au.status === 302 && /accounts\.google\.com/.test(au.headers.get('location')), `${au.status} ${au.headers.get('location')}`);
  t('/install.php inexistant après installation', (await fetch(`${BASE}/install.php`)).status === 404);

  console.log('\n— Configuration lue dans un .env (hors du dossier public)');
  const ENVDIR = path.dirname(WWW); const ENVF = path.join(ENVDIR, '.env');
  const baseEnv = (extra = '') => ['# déploiement', 'GIT_REPO=https://github.com/giceyBpro/Radiv.git', 'GIT_BRANCH=v1.1', `SITE_PUBLIC_URL="${BASE}"   # commentaire en fin de ligne`,
    'GOOGLE_CLIENT_ID=cid.apps.googleusercontent.com', `GOOGLE_CLIENT_SECRET=${GSECRET}`, 'ADMIN_GOOGLE_EMAILS="admin@example.org, second@example.org"', 'ADMIN_UPDATE_ENABLED=true',
    'SMTP_HOST=smtp.example.org', 'SMTP_PORT=587', 'SMTP_USER=contact@example.org', `SMTP_PASS='${SECRET_SMTP}'`, 'SMTP_FROM=Site <no-reply@example.org>', 'CONTACT_DEST=dest@example.org',
    'RECAPTCHA_SITE_KEY=sitekey', 'RECAPTCHA_SECRET_KEY=recaptchasecret', 'SFMN_MODE_ENABLED=true', 'SFMN_CALCULATOR_URL=https://sfmn.example.org/calc',
    'RESTRICTION_ROUNDING_MODE=floor', 'MEASUREMENT_LOGGING_LEVEL=user', 'LOGS_RETENTION_MONTHS=6', 'LOGS_MAX_BYTES=3145728', 'CALCULATE_RATE_LIMIT=33', 'TRUSTED_PROXIES=', 'RATE_LIMIT_BACKEND=file', extra].join('\n') + '\n';
  const writeEnv = (text, file = ENVF) => { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, text); require('child_process').execSync(`chown -R www-data:www-data ${path.dirname(file)}; chmod 640 ${file}`); };
  const dropEnv = () => { for (const f of [ENVF, path.join(ENVDIR, 'private')]) fs.rmSync(f, { recursive: true, force: true }); };
  const envForm = (over = {}) => ({ step: 'install', key: KEY, use_env: '1', ...over });

  deployInstaller(); writeEnv(baseEnv());
  r = await post({ step: 'check', key: KEY }); html = r.text;
  t('.env trouvé: configuration affichée', /Configuration lue dans le fichier \.env/.test(html) && html.includes('giceyBpro/Radiv') && html.includes('v1.1') && html.includes(BASE));
  t('secrets masqués, jamais renvoyés au navigateur', ![GSECRET, SECRET_SMTP, 'p@ss', 'recaptchasecret'].some((x) => html.includes(x)) && /●●●/.test(html));
  t('seuls la version et le jeton sont demandés', /name="use_env" value="1"/.test(html) && /name="ref"/.test(html) && /name="token"/.test(html) && !/name="smtp_host"/.test(html) && !/name="google_secret"/.test(html));
  const beforeEnv = snapshot();
  r = await post(envForm({ ref: 'v1.1' })); t('sans jeton → refusé, rien écrit', r.status === 400 && /jeton/i.test(r.text) && snapshot() === beforeEnv, msg(r.text));
  r = await post(envForm({ ref: 'v1.1', token: 'ghp_mauvaisjetonmauvaisjeton12345' })); t('jeton refusé par GitHub → interrompu, rien écrit', r.status === 500 && snapshot() === beforeEnv);
  r = await post(envForm({ ref: 'v1.1', token: 'court' })); t('jeton de forme invalide → refusé', r.status === 400 && snapshot() === beforeEnv);

  r = await post(envForm({ ref: 'v1.1', token: TOKEN, site_url: 'https://evil.example', google_id: 'evil', smtp_host: 'evil.example', admin_emails: 'pirate@evil.example', repo: 'evil/repo' }));
  html = r.text;
  t('installation depuis le .env avec le seul jeton saisi', r.status === 200 && /Site installé/.test(html) && /1111111/.test(html), msg(html));
  const env2 = fs.readFileSync(path.join(WWW, 'api/.runtime.env'), 'utf8');
  t('champs du formulaire ne peuvent pas écraser le .env (site, Google, SMTP, dépôt)', !/evil/.test(env2) && env2.includes(`SITE_PUBLIC_URL="${BASE}"`) && env2.includes('ADMIN_GOOGLE_EMAILS="admin@example.org, second@example.org"') && env2.includes('SMTP_HOST="smtp.example.org"'));
  t('valeurs du .env reportées (arrondi, journal, limites, SFMN, proxies, rate-limit)', ['RESTRICTION_ROUNDING_MODE="floor"', 'MEASUREMENT_LOGGING_LEVEL="user"', 'LOGS_RETENTION_MONTHS="6"', 'LOGS_MAX_BYTES="3145728"', 'CALCULATE_RATE_LIMIT="33"', 'TRUSTED_PROXIES=""', 'RATE_LIMIT_BACKEND="file"', 'SFMN_MODE_ENABLED="true"', 'SFMN_CALCULATOR_URL="https://sfmn.example.org/calc"', 'ADMIN_UPDATE_ENABLED="true"', `SMTP_PASS='${SECRET_SMTP}'`].every((x) => env2.includes(x)), env2);
  t('jeton saisi absent du disque', ![...(function* w(d) { for (const n of fs.readdirSync(d, { withFileTypes: true })) { const q = path.join(d, n.name); if (n.isDirectory()) yield* w(q); else yield q; } })(ENVDIR)].some((f) => fs.readFileSync(f).includes(TOKEN)));
  t('.env d\'origine intact (hors dossier public, conservé)', fs.readFileSync(ENVF, 'utf8') === baseEnv() && /n'a pas été modifié/.test(html.replace(/&#039;/g, "'")));
  t('mode SFMN du .env pris en compte par l\'API', (await (await fetch(`${BASE}/api/config`)).json()).calculation_modes.includes('sfmn'));
  t('version installée = celle du .env (v1.1)', JSON.parse(fs.readFileSync(path.join(WWW, 'api/var/version.json'), 'utf8')).sha === '1'.repeat(40));

  console.log('\n— Jetons présents dans le .env');
  deployInstaller(); writeEnv(baseEnv(`UPDATE_GITHUB_TOKEN=${TOKEN}`));
  r = await post({ step: 'check', key: KEY }); t('UPDATE_GITHUB_TOKEN valide → champ jeton masqué', !/name="token"/.test(r.text) && /name="use_env"/.test(r.text) && !r.text.includes(TOKEN));
  r = await post(envForm({ ref: 'main' })); const envT = fs.readFileSync(path.join(WWW, 'api/var/version.json'), 'utf8');
  t('installation sans rien saisir, version surchargeable (main → v1.2)', /Site installé/.test(r.text) && JSON.parse(envT).sha === '2'.repeat(40) && fs.readFileSync(path.join(WWW, 'app.js'), 'utf8').includes('fixture v1.2'));
  t('UPDATE_GITHUB_TOKEN reporté dans api/.runtime.env (600) pour que /auth ne le redemande pas', fs.readFileSync(path.join(WWW, 'api/.runtime.env'), 'utf8').includes(`UPDATE_GITHUB_TOKEN="${TOKEN}"`) && (fs.statSync(path.join(WWW, 'api/.runtime.env')).mode & 0o777) === 0o600);
  deployInstaller(); writeEnv(baseEnv(`GIT_TOKEN=${TOKEN}`));
  r = await post({ step: 'check', key: KEY }); t('GIT_TOKEN valide → champ jeton masqué', !/name="token"/.test(r.text));
  r = await post(envForm({ ref: 'v1.1' })); t('installation avec GIT_TOKEN', /Site installé/.test(r.text));
  t('GIT_TOKEN (jeton de déploiement) NON reporté dans api/', !fs.readFileSync(path.join(WWW, 'api/.runtime.env'), 'utf8').includes(TOKEN));
  deployInstaller(); writeEnv(baseEnv('UPDATE_GITHUB_TOKEN=ghp_perimeperimeperimeperime1234'));
  r = await post({ step: 'check', key: KEY }); t('jeton du .env refusé par GitHub → le champ jeton réapparaît', /name="token"/.test(r.text));
  const snap2 = snapshot(); r = await post(envForm({ ref: 'v1.1' })); t('…et sans jeton saisi, l\'installation échoue proprement', r.status === 500 && /jeton/.test(r.text) && snapshot() === snap2);
  r = await post(envForm({ ref: 'v1.1', token: TOKEN })); t('…avec un jeton saisi elle réussit', /Site installé/.test(r.text));
  t('le jeton périmé du .env n\'est pas reporté', !fs.readFileSync(path.join(WWW, 'api/.runtime.env'), 'utf8').includes('ghp_perime'));

  console.log('\n— .env invalide ou absent');
  deployInstaller(); writeEnv(baseEnv().replace('admin@example.org, second@example.org', 'pas-un-mail'));
  r = await post({ step: 'check', key: KEY }); t('valeur invalide signalée au diagnostic, pas de bouton d\'installation', /Adresse administrateur invalide/.test(r.text) && !/name="use_env"/.test(r.text));
  const snap3 = snapshot(); r = await post(envForm({ ref: 'v1.1', token: TOKEN })); t('installation refusée avec ce .env, rien écrit', r.status === 400 && /Adresse administrateur invalide/.test(r.text) && snapshot() === snap3);
  writeEnv(baseEnv().replace(/^SITE_PUBLIC_URL.*$/m, ''));
  r = await post({ step: 'check', key: KEY }); t('SITE_PUBLIC_URL manquant signalé', /Adresse du site invalide/.test(r.text));
  writeEnv(baseEnv('SMTP_TIMEOUT_MS=15\nINJECT_ME=1'));
  r = await post({ step: 'check', key: KEY }); t('variables inconnues du .env ignorées (liste blanche)', !/INJECT_ME/.test(r.text) && /name="use_env"/.test(r.text));
  dropEnv(); r = await post({ step: 'check', key: KEY }); t('pas de .env → formulaire manuel + indication de l\'emplacement attendu', /name="smtp_host"/.test(r.text) && /Aucun fichier \.env lisible/.test(r.text));
  r = await post(envForm({ ref: 'v1.1', token: TOKEN })); t('use_env sans fichier → refusé', r.status === 400 && /introuvable/.test(r.text) && listing().join() === 'install.php');

  console.log('\n— Emplacements: dossier public et ~/');
  deployInstaller({ envConst: 'install.env' }); writeEnv(baseEnv(), path.join(WWW, 'install.env'));
  r = await post({ step: 'check', key: KEY }); t('.env dans le dossier public: avertissement', /dossier public/.test(r.text), r.text.replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 700));
  r = await post(envForm({ ref: 'v1.1', token: TOKEN })); t('installation réussie…', /Site installé/.test(r.text));
  t('…et ce .env public est supprimé après l\'installation', !fs.existsSync(path.join(WWW, 'install.env')) && /a été supprimé/.test(r.text));
  deployInstaller({ envConst: '~/private/install.env' }); writeEnv(baseEnv(), path.join(ENVDIR, 'private/install.env'));
  r = await post({ step: 'check', key: KEY }); t('chemin ~/... résolu depuis le dossier personnel', /Configuration lue dans le fichier \.env/.test(r.text), r.text.slice(r.text.indexOf('<h2>'), r.text.indexOf('<h2>') + 200));
  r = await post(envForm({ ref: 'v1.1', token: TOKEN })); t('installation via ~/...', /Site installé/.test(r.text) && !/qui se trouvait dans le dossier public/.test(r.text) && fs.existsSync(path.join(ENVDIR, 'private/install.env')));
  deployInstaller({ envConst: '/srv/install-test/private/install.env' });
  r = await post({ step: 'check', key: KEY }); t('chemin absolu accepté', /Configuration lue dans le fichier \.env/.test(r.text));
  deployInstaller({ envConst: '' }); writeEnv(baseEnv(), ENVF);
  r = await post({ step: 'check', key: KEY }); t('ENV_FILE vide: lecture du .env désactivée', /name="smtp_host"/.test(r.text) && !/Configuration lue/.test(r.text));
  dropEnv();

  console.log('\n— Cohérence des listes blanches (install.php ↔ updater.php)');
  const list = (text, re) => [...(text.match(re) || [''])[0].matchAll(/'([^']+)'/g)].map((m) => m[1]).sort().join(',');
  const upd = fs.readFileSync(path.join(REPO_DIR, 'api/lib/updater.php'), 'utf8');
  t('FRONTEND_FILES identiques', list(src, /\$FRONTEND_FILES = array\(([\s\S]*?)\);/) === list(upd, /const FRONTEND_FILES = \[([\s\S]*?)\];/) && list(src, /\$FRONTEND_FILES = array\(([\s\S]*?)\);/) !== '');
  t('REQUIRED_FILES identiques', list(src, /\$REQUIRED_FILES = array\(([\s\S]*?)\);/) === list(upd, /const REQUIRED_FILES = \[([\s\S]*?)\];/) && list(src, /\$REQUIRED_FILES = array\(([\s\S]*?)\);/) !== '');
  const dep = fs.readFileSync(path.join(REPO_DIR, 'deploy.sh'), 'utf8');
  const deployFiles = [...dep.matchAll(/--include='([^'/]+\.(?:html|js|ico|txt))'/g)].map((m) => m[1]).filter((f) => f !== 'config.js').sort().join(',');
  t('liste du site identique à deploy.sh', deployFiles === list(src, /\$FRONTEND_FILES = array\(([\s\S]*?)\);/), `${deployFiles}`);

  console.log('\n— Limitation des essais de clé');
  deployInstaller(); let last = 0; for (let i = 0; i < 11; i += 1) last = (await post({ step: 'check', key: `mauvaise-${i}` })).status;
  t('au-delà de 10 clés fausses → 429, même avec la bonne clé', last === 429 && (await post({ step: 'check', key: KEY })).status === 429);

  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
