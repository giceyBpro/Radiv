// Test de /auth dans un VRAI navigateur (Chromium via Playwright) : les formulaires sont cliqués comme par un
// utilisateur, ce que les tests HTTP (qui fabriquent eux-mêmes les en-têtes) ne peuvent pas vérifier. C'est
// ce test qui aurait détecté « Requête refusée » (Chromium envoyait « Origin: null » avec Referrer-Policy no-referrer).
// Prérequis : site de test sous Apache sur :8082, api/tests/fake-services.js (faux Google/GitHub), archives de
// make-fixtures.php, Playwright + Chromium (non requis en CI). Usage: node test-browser-admin.js <racine_web> [chemin_chromium]
const fs = require('fs'); const path = require('path');
let chromium; for (const m of ['playwright', '/opt/node-tools/node_modules/playwright']) { try { ({ chromium } = require(m)); break; } catch (e) { /* suivant */ } }
if (!chromium) { console.error('Playwright introuvable'); process.exit(2); }
const WWW = process.argv[2]; const CHROME = process.argv[3] || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'; const BASE = 'http://127.0.0.1:8082';
const TOKEN = 'ghp_validtokenvalidtokenvalid1234';
let pass = 0; let fail = 0;
const t = (name, cond, detail = '') => { if (cond) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

// Journaux de test (dates relatives)
const NOW = Date.now(); const DAY = 86400000;
for (const f of fs.readdirSync(path.join(WWW, 'api/logs'))) if (f.startsWith('measurements-')) fs.unlinkSync(path.join(WWW, 'api/logs', f));
const addRow = (ts, row) => { const d = new Date(ts); const f = path.join(WWW, `api/logs/measurements-${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}.jsonl`); fs.appendFileSync(f, JSON.stringify({ timestamp: d.toISOString(), ...row }) + '\n'); fs.chmodSync(f, 0o644); };
const full = (ip, geo, iso, ok = true) => ({ ip, ip_geo: geo, input: { isotope_code: iso, dose_rate: 100, patient_size_cm: 150 }, result: { ok, effective_days: 11.43, errors: ok ? [] : ['dose_rate invalide'], recommendations_days: ok ? { conjoint_plus_60: 12 } : {} } });
addRow(NOW - 2 * 3600e3, full('9.9.9.9', { ip: '9.9.9.9', country: 'France', city: 'Paris', latitude: 48.857, longitude: 2.352 }, 'radium223'));
addRow(NOW - 3600e3, full('5.5.5.5', { ip: '5.5.5.5', country: 'Germany', city: 'Berlin', latitude: 52.52, longitude: 13.4 }, 'psma_177lu', false));
addRow(NOW - 3 * DAY, full('8.8.8.8', { ip: '8.8.8.8', country: 'France', city: 'Lyon', latitude: 45.76, longitude: 4.84 }, 'radium223'));
addRow(NOW - 60 * DAY, full('7.7.7.7', { ip: '7.7.7.7', country: 'Spain', city: 'Madrid', latitude: 40.4, longitude: -3.7 }, 'radium223'));
for (let i = 0; i < 60; i += 1) addRow(NOW - 600e3 - i * 1000, full(`4.4.4.${i}`, null, 'radium223'));

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ acceptDownloads: true });
  const page = await ctx.newPage();
  const requests = []; const posts = []; const consoleErrors = [];
  await page.addInitScript(() => { window.__csp = []; document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} ${e.blockedURI}`)); });
  await ctx.route(/tile\.openstreetmap\.org/, (r) => r.fulfill({ status: 200, contentType: 'image/png', body: PNG }));
  page.on('request', (r) => { requests.push(r.url()); if (r.method() === 'POST') posts.push({ url: r.url(), origin: r.headers().origin }); });
  page.on('pageerror', (e) => consoleErrors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  const click = (sel) => Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click(sel)]);
  const body = async () => (await page.textContent('body')).replace(/\s+/g, ' ');
  const stat = async (label) => Number((await page.locator('.stat', { hasText: label }).locator('.v').first().textContent()).replace(/\s/g, ''));

  console.log('— Connexion et mise à jour (formulaires cliqués)');
  await page.goto(`${BASE}/auth`);
  t('connexion Google (simulée) puis page d\'administration', (await page.title()) === 'Administration du site' && page.url() === `${BASE}/auth`);
  t('en-tête: onglets et adresse connectée', (await page.locator('nav.tabs a').allTextContents()).join() === 'Site,Mesures' && /admin@example\.org/.test(await body()));
  await page.fill('input[name=ref]', 'v1.1'); if (await page.$('input[name=token]')) await page.fill('input[name=token]', TOKEN);
  await click('button:has-text("Installer")');
  let txt = await body();
  t('mise à jour par le formulaire: PAS de « Requête refusée »', !/Requête refusée/.test(txt) && /Mise à jour installée : 1111111/.test(txt), txt.slice(0, 200));
  t('Chromium envoie le vrai Origin (et non « null »)', posts.length >= 1 && posts.every((p) => p.origin === BASE), JSON.stringify(posts));
  await page.fill('input[name=ref]', 'v1.2'); if (await page.$('input[name=token]')) await page.fill('input[name=token]', TOKEN);
  await click('button:has-text("Installer")');
  await click('button:has-text("Rétablir la version précédente")');
  txt = await body(); t('retour arrière par le bouton', /Version précédente rétablie/.test(txt) && !/Requête refusée/.test(txt), txt.slice(0, 200));

  console.log('\n— Onglet « Mesures » (carte réelle)');
  await click('a.tab:has-text("Mesures")');
  t('onglet Mesures actif, 30 jours par défaut', page.url() === `${BASE}/auth/mesures` && /Derniers 30 jours/.test(await body()) && (await stat('Mesures')) === 63);
  await page.waitForSelector('.leaflet-container', { timeout: 10000 });
  await page.waitForSelector('.mk');
  const markers = await page.locator('.mk').allTextContents();
  t('carte Leaflet affichée avec un marqueur par position (Paris, Berlin, Lyon)', markers.length === 3 && markers.sort().join() === '1,1,1', markers.join());
  await page.locator('.mk').first().click(); await page.waitForSelector('.leaflet-popup-content');
  t('clic sur un marqueur: bulle avec le lieu et le nombre de requêtes', /(Paris|Berlin|Lyon).*(1 requête)/.test(await page.textContent('.leaflet-popup-content')));
  const external = requests.filter((u) => !u.startsWith(BASE) && !/tile\.openstreetmap\.org/.test(u) && !u.startsWith('http://127.0.0.1:9201'));
  t('aucune ressource tierce (hors tuiles OpenStreetMap)', external.length === 0, external.join(' '));
  t('aucune violation de la politique de sécurité (CSP)', (await page.evaluate(() => window.__csp)).length === 0, JSON.stringify(await page.evaluate(() => window.__csp)));
  t('aucune erreur JavaScript ni de console', consoleErrors.length === 0, consoleErrors.join(' | '));
  t('graphique d\'activité affiché', await page.locator('svg.chart rect.bar').count() >= 2);

  console.log('\n— Durée, filtres, pagination, export');
  await click('a.segbtn:has-text("24 h")'); t('durée « 24 h »', /days=1/.test(page.url()) && (await stat('Mesures')) === 62);
  await click('a.segbtn:has-text("7 jours")'); t('durée « 7 jours »', (await stat('Mesures')) === 63 && !/Madrid/.test(await body()));
  await click('a.segbtn:has-text("90 jours")'); t('durée « 90 jours » (inclut Madrid, 60 j)', (await stat('Mesures')) === 64);
  await page.selectOption('select[name=iso]', 'psma_177lu'); await click('.filters button:has-text("Filtrer")');
  t('filtre isotope + conservation de la durée', (await stat('Mesures')) === 1 && /days=90/.test(page.url()) && (await stat('En erreur')) === 1);
  await click('a.reset'); await page.selectOption('select[name=ok]', '0'); await click('.filters button:has-text("Filtrer")'); t('filtre « en erreur »', (await stat('Mesures')) === 1);
  await click('a.reset'); await page.fill('input[name=q]', 'Lyon'); await click('.filters button:has-text("Filtrer")'); t('recherche par lieu', (await stat('Mesures')) === 1 && /8\.8\.8\.8/.test(await body()));
  await click('a.reset'); await click('a.segbtn:has-text("30 jours")');
  t('pagination: 50 lignes puis la suite', (await page.locator('tbody tr').count()) === 50 && /Page 1 \/ 2/.test(await body()));
  await click('.pager a:has-text("Suivante")'); t('page suivante', /Page 2 \/ 2/.test(await body()) && (await page.locator('tbody tr').count()) === 13);
  const sel = await page.$$eval('select[name=period] option', (o) => o.map((x) => x.value).filter(Boolean)); const old = new Date(NOW - 60 * DAY); const val = `${old.getUTCFullYear()}-${old.getUTCMonth() + 1}`;
  await page.selectOption('select[name=period]', val); await click('form.inline button:has-text("Afficher")');
  t('mois précis par la liste déroulante', sel.includes(val) && (await stat('Mesures')) === 1 && /Madrid/.test(await body()), `${sel.join()} | ${await stat('Mesures')}`);
  await click('a.segbtn:has-text("Tout")');
  const [download] = await Promise.all([page.waitForEvent('download'), page.click('a.btn:has-text("Exporter en CSV")')]);
  const csvPath = await download.path(); const csv = fs.readFileSync(csvPath, 'utf8');
  t('export CSV par le bouton', /^mesures_tout\.csv$/.test(download.suggestedFilename()) && csv.startsWith('timestamp,ip,ip_geo') && csv.split('\n').length === 1 + 64);

  console.log('\n— Déconnexion');
  await page.goto(`${BASE}/auth`); await click('button:has-text("Se déconnecter")');
  t('déconnexion par le bouton', /Vous êtes déconnecté/.test(await body()) && !/Requête refusée/.test(await body()));
  await page.goto(`${BASE}/auth/mesures`); t('plus d\'accès après déconnexion', /Page introuvable|Not found/.test(await body()));
  await browser.close();
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
