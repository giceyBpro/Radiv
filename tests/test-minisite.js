// Page minimale du moteur (core/web) dans un vrai navigateur (Playwright), servie par l'exemple d'API (core/api/index.php) sur une COPIE du
// moteur à laquelle on ajoute, dans les données seulement, un isotope de profil « iode bénin » et un scénario d'exposition : la page doit
// les prendre en charge sans aucune modification. Non requis en CI (Playwright). Usage: node tests/test-minisite.js
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
let chromium; for (const m of ['playwright', '/opt/node-tools/node_modules/playwright']) { try { ({ chromium } = require(m)); break; } catch (e) { /* suivant */ } }
if (!chromium) { console.error('Playwright introuvable'); process.exit(2); }

const root = path.resolve(__dirname, '..');
let pass = 0; let fail = 0;
const t = (name, ok, detail = '') => { if (ok) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'minisite-'));
fs.cpSync(path.join(root, 'core'), dir, { recursive: true, filter: (src) => !/[\\/]tests([\\/]|$)/.test(src) });
const iso = path.join(dir, 'data/isotopes.json');
fs.writeFileSync(iso, JSON.stringify([...JSON.parse(fs.readFileSync(iso, 'utf8')), { api_code: 'essai_benin', label: 'Essai bénin', period_hours: 122.4, reference: 'essai', remark: '-', situation: '', profile: 'benign_iodine' }]));
// Nouvelle règle de calcul : le profil EXEMPLE du guide de src/profiles.php (décommenté) et le champ « poids_kg » qu'il utilise.
const ff = path.join(dir, 'data/fields.json');
fs.writeFileSync(ff, JSON.stringify([...JSON.parse(fs.readFileSync(ff, 'utf8')), { code: 'poids_kg', label: 'Poids du patient (kg)', unit: 'kg', type: 'number', min: 1, step: 'any', help: 'Poids au moment de la mesure.' }]));
fs.writeFileSync(iso, JSON.stringify([...JSON.parse(fs.readFileSync(iso, 'utf8')), { api_code: 'essai_poids', label: 'Essai poids', period_hours: 100, reference: 'essai', remark: '-', situation: '', profile: 'poids_reference' }]));
const profilesFile = path.join(dir, 'src/profiles.php');
{ const src = fs.readFileSync(profilesFile, 'utf8').split('\n'); const ex = []; let inside = false;
  for (const line of src) { if (line.includes('$poidsReference = [')) inside = true; if (inside) ex.push(line.replace(/^\s*\/\/   ?/, '')); if (inside && line.includes('] + $standard;')) break; }
  const reg = "    return $profiles = ['standard' => $standard, 'benign_iodine' => $benignIodine, 'free_period' => $freePeriod];";
  const text = src.join('\n');
  if (!text.includes(reg) || !ex.length) throw new Error('exemple du guide introuvable dans profiles.php');
  fs.writeFileSync(profilesFile, text.replace(reg, ex.join('\n') + '\n    return $profiles = [\'standard\' => $standard, \'benign_iodine\' => $benignIodine, \'free_period\' => $freePeriod, \'poids_reference\' => $poidsReference];')); }
const sc = path.join(dir, 'data/scenarios.json');
fs.writeFileSync(sc, JSON.stringify([...JSON.parse(fs.readFileSync(sc, 'utf8')), { audience_code: 'voisin_palier', label: 'Voisin de palier', exposures: [{ hours: 1, distance: 1, unit_factor_at_1m: true }], limit: 1 }]));

const PORT = 8391;
const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, path.join(dir, 'api/index.php')], { stdio: 'ignore' });

(async () => {
  await new Promise((ok) => setTimeout(ok, 1200));
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = []; page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(`http://127.0.0.1:${PORT}/`);
  await page.waitForSelector('#isotope option', { state: 'attached' });

  t('titre de la page repris de config.js', /Durées de restriction/.test(await page.textContent('h1')));
  t('radium: débit de dose demandé, pas d\'activité/fixation', await page.locator('#doseRateField').isVisible() && !(await page.locator('#benignFields').isVisible()));
  await page.selectOption('#isotope', 'iode131_benin');
  t('iode bénin: activité/fixation demandées, pas de débit', await page.locator('#benignFields').isVisible() && !(await page.locator('#doseRateField').isVisible()));
  await page.selectOption('#isotope', 'essai_benin');
  t('isotope ajouté (profil iode bénin): mêmes champs, sans modifier la page', await page.locator('#benignFields').isVisible() && !(await page.locator('#doseRateField').isVisible()));
  t('règle ajoutée dans le moteur : son champ n\'apparaît pas pour les autres isotopes', !(await page.locator('[data-extra-field="poids_kg"]').isVisible()));
  await page.selectOption('#isotope', 'essai_poids');
  t('…il apparaît pour l\'isotope dont le profil l\'utilise, avec son libellé et son unité, sans modifier la page', await page.locator('[data-extra-field="poids_kg"]').isVisible() && /Poids du patient \(kg\)/.test(await page.locator('[data-extra-field="poids_kg"]').innerText()));
  await page.fill('#doseRate', '50'); await page.fill('#patientSize', '170'); await page.click('#submit');
  await page.waitForSelector('#errors:not([hidden])');
  t('…champ obligatoire vide : l\'erreur du moteur est affichée', /poids_kg/.test(await page.locator('#errors').innerText()));
  await page.fill('#extra_poids_kg', '70'); await page.click('#submit');
  await page.waitForSelector('#results:not([hidden]) tbody tr');
  t('…renseigné : le calcul utilise la nouvelle règle', (await page.locator('#results tbody tr').count()) >= 7);
  await page.selectOption('#isotope', 'non_defini');
  t('« non défini »: libellé « période obligatoire »', /obligatoire pour cet isotope/.test(await page.locator('[data-label="periodDays"]').innerText()));
  await page.selectOption('#isotope', 'psma_177lu');
  t('PSMA-177Lu: choix du nombre de cures proposé', await page.locator('#cureField').isVisible());

  await page.selectOption('#isotope', 'radium223'); await page.fill('#doseRate', '100'); await page.fill('#patientSize', '170');
  await page.click('#submit');
  await page.waitForSelector('#results:not([hidden]) tbody tr');
  const rows = await page.locator('#results tbody tr').allInnerTexts();
  t('tableau: 7 scénarios historiques + le scénario ajouté dans les données', rows.length === 8 && /Voisin de palier/.test(rows[7]) && /1 h à 1 m/.test(rows[7]), `${rows.length} lignes`);
  t('aucune erreur JavaScript', errors.length === 0, errors.join('; '));

  await browser.close();
  server.kill();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { server.kill(); console.error(e); process.exit(2); });
