// Publication des fiches d'information : la page /informations ne liste que les fiches publiées ; une fiche en brouillon n'est jamais
// servie ni listée (ni dans le plan du site) ; une fiche publiée l'est avec canonique et données structurées.
// Usage: node tests/test-fiches-publish.js
const { spawn } = require('child_process'); const fs = require('fs'); const os = require('os'); const path = require('path');
const ROOT = path.join(__dirname, '..'); const PORT = 8103; const BASE = `http://127.0.0.1:${PORT}`;
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
const start = async (tmp) => {
  const router = path.join(tmp, 'router.php');
  fs.writeFileSync(router, `<?php $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); if ($p === '/' || $p === '/sitemap.xml' || str_starts_with($p, '/informations')) { putenv('RADIV_BACKEND=${tmp}/backend'); require '${tmp}/public/api/index.php'; return true; } return false;`);
  const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(tmp, 'public'), router], { env: { ...process.env, DATA_DIR: path.join(tmp, 'var'), SITE_PUBLIC_URL: BASE }, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1200)); return srv;
};
(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pub-'));
  for (const d of ['public', 'backend', 'core']) fs.cpSync(path.join(ROOT, d), path.join(tmp, d), { recursive: true });
  const dataFile = path.join(tmp, 'backend/src/lib/fiches_data.php');
  const original = fs.readFileSync(dataFile, 'utf8');
  // 1. État du dépôt : les deux fiches de l'iode 131 sont publiées, Pluvicto et Lutathera en brouillon
  let srv = await start(tmp);
  let idx = await (await fetch(`${BASE}/informations`)).text();
  t('page /informations : avis « calculateur destiné aux professionnels de santé » en haut, consignes de l\'équipe', /class="notice"[^>]*>[\s\S]{0,200}professionnels de santé[\s\S]{0,300}consignes données par votre équipe/.test(idx));
  t('fiches publiées listées (iode : hyperthyroïdie, cancer) ; brouillons (lutétium) ni listés ni liés', /Fiches par traitement/.test(idx) && /href="\/informations\/iode-131-hyperthyroidie"/.test(idx) && /href="\/informations\/iode-131-cancer-thyroide"/.test(idx) && !/lutetium-177/.test(idx) && !/<!--FICHES-->/.test(idx));
  t('brouillon : /informations/lutetium-177-psma répond 404', (await fetch(`${BASE}/informations/lutetium-177-psma`)).status === 404);
  srv.kill(); await new Promise((r) => setTimeout(r, 300));
  // 2. On publie en plus la fiche « Pluvicto » (Lutathera reste en brouillon)
  const published = original.replace("'slug' => 'lutetium-177-psma',\n        'status' => 'draft',", "'slug' => 'lutetium-177-psma',\n        'status' => 'published',");
  t('(préparation) statut de la fiche Pluvicto passé à « published »', published !== original);
  fs.writeFileSync(dataFile, published);
  srv = await start(tmp);
  idx = await (await fetch(`${BASE}/informations`)).text();
  t('trois fiches publiées listées sur /informations avec leur lien', /Fiches par traitement/.test(idx) && /href="\/informations\/lutetium-177-psma"/.test(idx) && /href="\/informations\/iode-131-hyperthyroidie"/.test(idx) && /href="\/informations\/iode-131-cancer-thyroide"/.test(idx));
  t('fiche en brouillon (Lutathera) : ni listée ni liée', !/lutetium-177-dotatate/.test(idx));
  const fr = await fetch(`${BASE}/informations/lutetium-177-psma`); const html = await fr.text();
  t('fiche publiée : 200, indexable, canonique absolue, FAQ structurée', fr.status === 200 && !/noindex/.test(html) && /rel="canonical" href="[^"]+\/informations\/lutetium-177-psma"/.test(html) && /FAQPage/.test(html));
  t('fiche publiée : pas de bandeau « brouillon » ni de points de relecture', !/BROUILLON/.test(html) && !/Points à vérifier/.test(html));
  t('brouillon voisin (Lutathera) : toujours 404', (await fetch(`${BASE}/informations/lutetium-177-dotatate`)).status === 404);
  const sm = await (await fetch(`${BASE}/sitemap.xml`)).text();
  t('plan du site : les fiches publiées y figurent, pas le brouillon Lutathera', /\/informations\/iode-131-cancer-thyroide</.test(sm) && /\/informations\/iode-131-hyperthyroidie</.test(sm) && /\/informations\/lutetium-177-psma</.test(sm) && !/lutetium-177-dotatate/.test(sm));
  srv.kill(); fs.rmSync(tmp, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
