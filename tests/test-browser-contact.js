// Formulaire de contact : Google reCAPTCHA ne doit être chargé qu'après accord explicite, et l'envoi doit être
// impossible sans cet accord. Lance un serveur PHP local (dev-router) avec une clé reCAPTCHA factice.
// Usage: node tests/test-browser-contact.js
const { spawn } = require('child_process'); const path = require('path');
let chromium; for (const m of ['playwright', '/opt/node-tools/node_modules/playwright']) { try { ({ chromium } = require(m)); break; } catch (e) { /* suivant */ } }
const ROOT = path.join(__dirname, '..'); const PORT = 8097; const BASE = `http://127.0.0.1:${PORT}`;
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
(async () => {
  const php = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tests/dev-router.php')], { env: { ...process.env, RECAPTCHA_SITE_KEY: 'cle-publique-factice' }, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1200));
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage(); const external = []; let sent = null;
  await page.route(/google\.com\/recaptcha/, (r) => { external.push(r.request().url()); r.fulfill({ status: 200, contentType: 'application/javascript', body: "window.grecaptcha={ready:function(f){f();},execute:function(){return Promise.resolve('jeton-test');}}; if(window.onRecaptchaLoaded)window.onRecaptchaLoaded();" }); });
  await page.route('**/api/contact', (r) => { sent = JSON.parse(r.request().postData()); r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); });
  page.on('request', (r) => { if (!r.url().startsWith(BASE) && !/google\.com\/recaptcha/.test(r.url())) external.push(r.url()); });
  await page.goto(`${BASE}/contact.html`); await page.waitForTimeout(1200);
  t('ouverture de la page: aucun appel à Google', external.length === 0, external.join(' '));
  t('case d\'accord reCAPTCHA affichée, texte explicite', await page.locator('#consentRow').isVisible() && /adresse IP/.test(await page.textContent('#consentRow')) && /Google/.test(await page.textContent('#consentRow')));
  t('envoi bloqué tant que la case n\'est pas cochée', await page.locator('#sendBtn').isDisabled() && !(await page.locator('#recaptchaConsent').isChecked()));
  t('information sur le traitement des données + lien', /ne les enregistre pas/.test(await page.textContent('.privacy')) && (await page.locator('.privacy a').getAttribute('href')) === '/legal#donnees');
  await page.fill('#email', 'patient@example.org'); await page.fill('#message', 'Bonjour');
  await page.click('#recaptchaConsent'); await page.waitForTimeout(800);
  t('après accord: reCAPTCHA chargé (et seulement alors)', external.length === 1 && /recaptcha\/api\.js/.test(external[0]), external.join(' '));
  t('bouton d\'envoi activé', await page.locator('#sendBtn').isEnabled());
  await page.click('#sendBtn'); await page.waitForFunction(() => /Message envoyé/.test(document.getElementById('status').textContent), null, { timeout: 8000 });
  t('message envoyé avec le jeton reCAPTCHA', sent && sent.recaptcha_token === 'jeton-test' && sent.email === 'patient@example.org');
  await page.click('#recaptchaConsent');
  t('accord retiré: envoi de nouveau bloqué', await page.locator('#sendBtn').isDisabled());
  await browser.close(); php.kill();
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
