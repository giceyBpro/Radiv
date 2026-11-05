// Page d'accueil de RadIV (public/index.html + app.js) dans un vrai navigateur (Playwright) avec un moteur auquel on a ajouté une NOUVELLE RÈGLE
// DE CALCUL : le profil exemple du guide de core/src/profiles.php (décommenté) et le champ « poids_kg » qu'il utilise (core/data/fields.json).
// La page doit construire ce champ seule (libellé, unité), l'afficher pour l'isotope concerné seulement, le rendre obligatoire, le transmettre
// au calcul, et reprendre le nom choisi par RadIV (public-config, « field_labels »). Non requis en CI (Playwright).
// Usage: node tests/test-radiv-fields.js
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
let chromium; for (const m of ['playwright', '/opt/node-tools/node_modules/playwright']) { try { ({ chromium } = require(m)); break; } catch (e) { /* suivant */ } }
if (!chromium) { console.error('Playwright introuvable'); process.exit(2); }

const root = path.resolve(__dirname, '..');
let pass = 0; let fail = 0;
const t = (name, ok, detail = '') => { if (ok) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };

// Copie du moteur + nouvelle règle (mêmes ajouts que tests/test-minisite.js).
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'radiv-fields-'));
for (const sub of ['core', 'demo']) fs.cpSync(path.join(root, sub), path.join(dir, sub), { recursive: true, filter: (src) => !/[\\/]tests([\\/]|$)/.test(src) });
const addJson = (file, entry) => { const f = path.join(dir, file); fs.writeFileSync(f, JSON.stringify([...JSON.parse(fs.readFileSync(f, 'utf8')), entry])); };
addJson('core/data/fields.json', { code: 'poids_kg', label: 'Poids du patient (kg)', unit: 'kg', type: 'number', min: 1, step: 'any', help: 'Poids au moment de la mesure.' });
addJson('core/data/isotopes.json', { api_code: 'essai_poids', label: 'Essai poids', period_hours: 100, reference: 'essai', remark: '-', situation: '', profile: 'poids_reference' });
const profilesFile = path.join(dir, 'core/src/profiles.php');
{ const src = fs.readFileSync(profilesFile, 'utf8').split('\n'); const ex = []; let inside = false;
  for (const line of src) { if (line.includes('$poidsReference = [')) inside = true; if (inside) ex.push(line.replace(/^\s*\/\/   ?/, '')); if (inside && line.includes('] + $standard;')) break; }
  const reg = "    return $profiles = ['standard' => $standard, 'benign_iodine' => $benignIodine, 'free_period' => $freePeriod];";
  const text = src.join('\n');
  if (!text.includes(reg) || !ex.length) throw new Error('exemple du guide introuvable dans profiles.php');
  fs.writeFileSync(profilesFile, text.replace(reg, ex.join('\n') + "\n    return $profiles = ['standard' => $standard, 'benign_iodine' => $benignIodine, 'free_period' => $freePeriod, 'poids_reference' => $poidsReference];")); }

// Petit serveur : fichiers de public/, API du moteur (config, calculate) et une configuration du site avec un nom de champ choisi par RadIV.
fs.writeFileSync(path.join(dir, 'router.php'), `<?php
require __DIR__ . '/demo/api/handler.php';
$route = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($route === 'api/public-config') { header('Content-Type: application/json'); echo json_encode(['site_name' => 'Test', 'field_labels' => ['poids_kg' => 'Poids (nom RadIV)', 'patient_size_cm' => 'Taille (nom RadIV)']]); exit; }
if (strpos($route, 'api/') === 0) { [$s, $d] = RivEngine\\Api\\handle($_SERVER['REQUEST_METHOD'], substr($route, 4), (string) file_get_contents('php://input')); http_response_code($s); header('Content-Type: application/json'); echo RivEngine\\Api\\encode($d); exit; }
$file = ${JSON.stringify(path.join(root, 'public'))} . '/' . ($route === '' ? 'index.html' : $route);
if (!is_file($file)) { http_response_code(404); exit; }
$types = ['html' => 'text/html', 'js' => 'text/javascript', 'css' => 'text/css'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream') . '; charset=utf-8');
readfile($file);
`);
const PORT = 8392;
const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, path.join(dir, 'router.php')], { stdio: 'ignore' });

(async () => {
  await new Promise((ok) => setTimeout(ok, 1200));
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const errors = []; page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(`http://127.0.0.1:${PORT}/`);
  await page.waitForSelector('#isotope option', { state: 'attached' });

  t('champ commun « taille du patient » : libellé choisi par RadIV et bornes lues dans la description du moteur', /Taille \(nom RadIV\)/.test(await page.locator('#patientSize').evaluate((el) => el.previousElementSibling.textContent)) && (await page.locator('#patientSize').getAttribute('min')) === '1');
  const field = page.locator('[data-extra-field="poids_kg"]');
  await page.selectOption('#isotope', 'radium223');
  t('champ ajouté par le moteur : masqué pour les isotopes qui ne l\'utilisent pas', !(await field.isVisible()));
  await page.selectOption('#isotope', 'essai_poids');
  t('…affiché pour l\'isotope dont le profil l\'utilise, sous le nom choisi par RadIV et avec son unité', await field.isVisible() && /Poids \(nom RadIV\) \(kg\)/.test(await field.innerText()));
  t('…avec son aide', /Poids au moment de la mesure/.test(await field.innerText()));
  await page.fill('#doseRate', '50'); await page.fill('#patientSize', '170');
  await page.click('#calculateBtn');
  await page.waitForTimeout(800);
  t('…champ obligatoire vide : mis en évidence comme les autres champs obligatoires, aucun résultat affiché', (await page.locator('#extra_poids_kg').getAttribute('class') || '').includes('required-field') && (await page.locator('#resultsBody tr').count()) === 0);
  await page.fill('#extra_poids_kg', '70');
  await page.click('#calculateBtn');
  await page.waitForSelector('#resultsBody tr', { timeout: 10000 });
  t('…renseigné : le calcul se fait avec la nouvelle règle (7 scénarios)', (await page.locator('#resultsBody tr').count()) === 7);
  await page.selectOption('#isotope', 'radium223');
  t('…de nouveau masqué pour un autre isotope', !(await field.isVisible()));
  t('aucune erreur JavaScript', errors.length === 0, errors.join('; '));

  await browser.close();
  server.kill();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { server.kill(); console.error(e); process.exit(2); });
