// Formulaire de contact : l'anti-robots ALTCHA est local (aucune requête hors du site, aucun accord à demander) ; le navigateur
// résout vraiment le défi du serveur et envoie la preuve avec le message. Lance un serveur PHP local (dev-router).
// Usage: node tests/test-browser-contact.js
const { spawn } = require('child_process'); const path = require('path'); const os = require('os'); const fs = require('fs');
let chromium; for (const m of ['playwright', '/opt/node-tools/node_modules/playwright']) { try { ({ chromium } = require(m)); break; } catch (e) { /* suivant */ } }
const ROOT = path.join(__dirname, '..'); const PORT = 8097; const BASE = `http://127.0.0.1:${PORT}`;
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'contact-data-'));
  const php = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tests/dev-router.php')], { env: { ...process.env, DATA_DIR: data }, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1200));
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage(); const external = []; let sent = null;
  await page.route('**/api/contact', (r) => { sent = JSON.parse(r.request().postData()); r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); });
  page.on('request', (r) => { if (!r.url().startsWith(BASE)) external.push(r.url()); });
  await page.goto(`${BASE}/contact.html`); await page.waitForTimeout(1200);
  t('ouverture de la page : aucun appel hors du site', external.length === 0, external.join(' '));
  t('plus de case d\'accord tierce', (await page.locator('#consentRow').count()) === 0 && !/Google/.test(await page.textContent('body')));
  t('widget ALTCHA présent, champ piège masqué', (await page.locator('altcha-widget').count()) === 1 && (await page.locator('#website').boundingBox()).x < 0);
  t('information sur le traitement des données + lien', /ne les enregistre pas/.test(await page.textContent('.privacy')) && (await page.locator('.privacy a').getAttribute('href')) === '/legal#donnees');
  await page.fill('#email', 'patient@example.org'); await page.fill('#message', 'Bonjour');
  await page.waitForTimeout(3500); // résolution du défi (délai minimal de 2 s côté serveur)
  await page.click('#sendBtn'); await page.waitForFunction(() => /Message envoyé/.test(document.getElementById('status').textContent), null, { timeout: 15000 });
  const proof = sent && sent.captcha ? JSON.parse(Buffer.from(sent.captcha, 'base64').toString()) : null;
  t('message envoyé avec la preuve de travail ALTCHA', !!proof && proof.algorithm === 'SHA-256' && Number.isInteger(proof.number) && sent.email === 'patient@example.org' && sent.website === '', JSON.stringify(sent));
  t('toujours aucun appel hors du site', external.length === 0, external.join(' '));
  await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
