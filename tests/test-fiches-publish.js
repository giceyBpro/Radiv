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
  // 1. État du dépôt : seule la fiche « hyperthyroïdie » est publiée
  let srv = await start(tmp);
  let idx = await (await fetch(`${BASE}/informations`)).text();
  t('page /informations : avis « calculateur destiné aux professionnels de santé » en haut, consignes de l\'équipe', /class="notice"[^>]*>[\s\S]{0,200}professionnels de santé[\s\S]{0,300}consignes données par votre équipe/.test(idx));
  t('seule la fiche publiée est listée (hyperthyroïdie) ; brouillons ni listés ni liés', /Fiches par traitement/.test(idx) && /href="\/informations\/iode-131-hyperthyroidie"/.test(idx) && !/iode-131-cancer-thyroide|lutetium-177/.test(idx) && !/<!--FICHES-->/.test(idx));
  t('brouillon : /informations/iode-131-cancer-thyroide répond 404', (await fetch(`${BASE}/informations/iode-131-cancer-thyroide`)).status === 404);
  srv.kill(); await new Promise((r) => setTimeout(r, 300));
  // 2. On publie en plus la fiche « cancer de la thyroïde » (les autres restent en brouillon)
  const published = original.replace("'slug' => 'iode-131-cancer-thyroide',\n        'status' => 'draft',", "'slug' => 'iode-131-cancer-thyroide',\n        'status' => 'published',");
  t('(préparation) statut de la fiche cancer passé à « published »', published !== original);
  fs.writeFileSync(dataFile, published);
  srv = await start(tmp);
  idx = await (await fetch(`${BASE}/informations`)).text();
  t('fiches publiées : toutes deux listées sur /informations avec leur lien', /Fiches par traitement/.test(idx) && /href="\/informations\/iode-131-cancer-thyroide"/.test(idx) && /href="\/informations\/iode-131-hyperthyroidie"/.test(idx));
  t('fiches en brouillon : ni listées ni liées', !/lutetium-177-psma|lutetium-177-dotatate/.test(idx));
  const fr = await fetch(`${BASE}/informations/iode-131-cancer-thyroide`); const html = await fr.text();
  t('fiche publiée : 200, indexable, canonique absolue, FAQ structurée', fr.status === 200 && !/noindex/.test(html) && /rel="canonical" href="[^"]+\/informations\/iode-131-cancer-thyroide"/.test(html) && /FAQPage/.test(html));
  t('fiche publiée : pas de bandeau « brouillon » ni de points de relecture', !/BROUILLON/.test(html) && !/Points à vérifier/.test(html));
  t('brouillon voisin : toujours 404', (await fetch(`${BASE}/informations/lutetium-177-psma`)).status === 404);
  const sm = await (await fetch(`${BASE}/sitemap.xml`)).text();
  t('plan du site : les fiches publiées y figurent, pas les brouillons', /\/informations\/iode-131-cancer-thyroide</.test(sm) && /\/informations\/iode-131-hyperthyroidie</.test(sm) && !/lutetium/.test(sm));
  srv.kill(); fs.rmSync(tmp, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
