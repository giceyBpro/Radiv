// Calculateur intégré aux fiches d'information : le navigateur appelle le vrai moteur (API du site), affiche les durées et l'avis
// « les consignes de l'équipe font foi ». Page de fiche générée par fiches.php, servie dans une copie temporaire du site.
// Usage: node tests/test-browser-fiche.js
const { spawn, execFileSync } = require('child_process'); const fs = require('fs'); const os = require('os'); const path = require('path');
let chromium; for (const m of ['playwright', '/opt/node-tools/node_modules/playwright']) { try { ({ chromium } = require(m)); break; } catch (e) { /* suivant */ } }
const ROOT = path.join(__dirname, '..'); const PORT = 8102; const BASE = `http://127.0.0.1:${PORT}`;
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'fiche-'));
  for (const d of ['public', 'backend', 'core']) fs.cpSync(path.join(ROOT, d), path.join(tmp, d), { recursive: true });
  fs.mkdirSync(path.join(tmp, 'tests')); fs.copyFileSync(path.join(ROOT, 'tests/dev-router.php'), path.join(tmp, 'tests/dev-router.php'));
  const gen = `define('RADIV_ENTRY',1); foreach(['json','config','contact','fiches'] as $f) require '${tmp}/backend/src/lib/'.$f.'.php'; foreach(['iode-131','lutetium-177-psma'] as $s) file_put_contents('${tmp}/public/fiche-'.$s.'.html', Radiv\\Fiches\\page_html(Radiv\\Fiches\\get($s), false));`;
  execFileSync('php', ['-r', gen]);
  const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(tmp, 'public'), path.join(tmp, 'tests/dev-router.php')], { env: { ...process.env, DATA_DIR: path.join(tmp, 'var') }, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1200));
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage(); const external = [];
  page.on('request', (r) => { if (!r.url().startsWith(BASE)) external.push(r.url()); });
  await page.goto(`${BASE}/fiche-lutetium-177-psma.html`); await page.waitForSelector('#fiche-calc form', { timeout: 8000 });
  t('fiche Pluvicto : formulaire du moteur (débit de dose, taille), sans liste de choix', (await page.locator('#fc-dose_rate').count()) === 1 && (await page.locator('#fc-patient_size_cm').count()) === 1 && (await page.locator('#fc-isotope').count()) === 0);
  t('avis « les consignes de l\'équipe font foi » avant le calculateur', /consignes de votre équipe font foi/.test(await page.textContent('.notice')));
  await page.fill('#fc-dose_rate', '20'); await page.fill('#fc-patient_size_cm', '170'); await page.click('.fc-form button');
  await page.waitForSelector('.fc-table', { timeout: 8000 });
  if (process.env.SHOT) { await page.locator('#calcul').scrollIntoViewIfNeeded(); await page.screenshot({ path: process.env.SHOT, fullPage: true }); }
  const rows = await page.locator('.fc-table tr').count();
  t('résultats du moteur : une ligne par situation, avec durées', rows >= 6 && /jour|Aucune restriction/.test(await page.textContent('.fc-table')));
  t('avis de prudence sous le résultat', /ce sont toujours les consignes de l'équipe/.test(await page.textContent('.fc-warn')));
 
  await page.goto(`${BASE}/fiche-iode-131.html`); await page.waitForSelector('#fiche-calc select', { timeout: 8000 });
  t('fiche iode : liste de choix des situations (hyperthyroïdie, cancer)', (await page.locator('#fc-isotope option').count()) === 4);
  await page.selectOption('#fc-isotope', 'iode131_benin');
  t('hyperthyroïdie : champs spécifiques (activité administrée, fixation)', (await page.locator('#fc-benign_activity_mbq').count()) === 1 && (await page.locator('#fc-benign_fixation_pct').count()) === 1);
  await page.fill('#fc-benign_activity_mbq', '400'); await page.fill('#fc-benign_fixation_pct', '30'); await page.fill('#fc-patient_size_cm', '170'); await page.click('.fc-form button');
  await page.waitForSelector('.fc-table, .fc-error', { timeout: 8000 });
  t('hyperthyroïdie : calcul du moteur affiché', (await page.locator('.fc-table').count()) === 1);
  t('aucun appel hors du site', external.length === 0, external.join(' '));
  await browser.close(); srv.kill(); fs.rmSync(tmp, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
