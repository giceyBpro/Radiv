// Lien des fiches vers le calculateur : /?isotope=<code> présélectionne le radiopharmaceutique ; un code inconnu est ignoré.
// Usage: node tests/test-browser-isotope-link.js
const { spawn } = require('child_process'); const path = require('path');
let chromium; for (const m of ['playwright', '/opt/node-tools/node_modules/playwright']) { try { ({ chromium } = require(m)); break; } catch (e) { /* suivant */ } }
const ROOT = path.join(__dirname, '..'); const PORT = 8104; const BASE = `http://127.0.0.1:${PORT}`;
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
(async () => {
  const php = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tests/dev-router.php')], { env: { ...process.env, DATA_DIR: path.join(require('os').tmpdir(), 'iso-link-data') }, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1200));
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const value = async (query) => { const page = await browser.newPage(); await page.goto(`${BASE}/index.html${query}`); await page.waitForFunction(() => document.querySelectorAll('#isotope option').length > 3, null, { timeout: 8000 }); const v = await page.$eval('#isotope', (el) => el.value); await page.close(); return v; };
  const def = await value('');
  t('sans paramètre : isotope par défaut', def !== '' && def !== 'psma_177lu' ? true : def !== '');
  t('?isotope=psma_177lu : PSMA-177Lu présélectionné', (await value('?isotope=psma_177lu')) === 'psma_177lu');
  t('?isotope=iode131_benin : Iode-131-Bénin présélectionné', (await value('?isotope=iode131_benin')) === 'iode131_benin');
  t('?isotope=iode131_0_fixation : néoplasie 0 % présélectionnée', (await value('?isotope=iode131_0_fixation')) === 'iode131_0_fixation');
  t('code inconnu : ignoré (isotope par défaut)', (await value('?isotope=nimporte_quoi')) === def);
  await browser.close(); php.kill();
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
