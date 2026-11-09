// Accueil servi aux robots : en service, le HTML brut ne contient plus le portail de maintenance (avis + style qui masque la page) ;
// en maintenance, 503 + noindex. Usage: node tests/test-seo-gate.js
const { spawn } = require('child_process'); const fs = require('fs'); const os = require('os'); const path = require('path');
const ROOT = path.join(__dirname, '..'); const PORT = 8101; const BASE = `http://127.0.0.1:${PORT}`;
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'seo-'));
  // Comme le .htaccess installé : l'accueil passe par la façade PHP (dev-router ne route que /api, /auth, /health).
  const router = path.join(data, 'router.php');
  fs.writeFileSync(router, `<?php $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); if ($p === '/') { putenv('RADIV_BACKEND=${ROOT}/backend'); require '${ROOT}/public/api/index.php'; return true; } return require '${ROOT}/tests/dev-router.php';`);
  const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), router], { env: { ...process.env, DATA_DIR: data, SITE_PUBLIC_URL: BASE }, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1200));
  let res = await fetch(`${BASE}/`); let html = await res.text();
  t('en service : 200 et vrai contenu', res.status === 200 && /<main class="page">/.test(html));
  t('en service : aucun portail de maintenance dans le HTML brut', !/MAINTENANCE-GATE|rivGate|maintenanceNotice/.test(html));
  t('en service : balises de référencement présentes', /rel="canonical"/.test(html) && /application\/ld\+json/.test(html));
  fs.mkdirSync(path.join(data, 'var'), { recursive: true });
  fs.writeFileSync(path.join(data, 'var/maintenance.json'), JSON.stringify({ mode: 'site', since: '2026-01-01T00:00:00Z', by: 'test' }));
  res = await fetch(`${BASE}/`); html = await res.text();
  t('en maintenance : 503, noindex, Retry-After', res.status === 503 && /noindex/.test(res.headers.get('x-robots-tag') || '') && !!res.headers.get('retry-after') && /Site en maintenance/.test(html));
  srv.kill(); fs.rmSync(data, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
