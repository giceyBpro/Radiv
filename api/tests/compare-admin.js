// Compare les routes /api/admin/* de Node et de PHP sur des journaux identiques (les mêmes
// fichiers logs/ des deux côtés). Deux phases séparées, chacune sur des serveurs fraîchement
// démarrés (la limite est de 10 tentatives/minute/IP, tentatives refusées comprises):
//   node api/tests/compare-admin.js functional   — réponses, CSV, en-têtes, 404 uniformes
//   node api/tests/compare-admin.js ratelimit    — au-delà de 10 tentatives: 404 des deux côtés
// Variables: ADMIN_TOKEN doit valoir "secret-test" sur les deux serveurs.
const NODE = 'http://127.0.0.1:3003'; const PHP = 'http://127.0.0.1:8081';
const TOKEN = 'secret-test';
const phase = process.argv[2] || 'functional';
const h = (t) => (t === null ? {} : { 'X-Admin-Token': t });
const functional = [
  ['/api/admin/measurements/periods', null], ['/api/admin/measurements/periods', 'mauvais'],
  ['/api/admin/measurements/periods', TOKEN], ['/api/admin/measurements', TOKEN],
  ['/api/admin/measurements?year=2026', TOKEN], ['/api/admin/measurements?year=2026&month=09', TOKEN],
  ['/api/admin/measurements?month=09', TOKEN], ['/api/admin/measurements?year=2026&month=9', TOKEN],
  ['/api/admin/measurements?year=1999', TOKEN],
  ['/api/admin/measurements.csv?year=2026&month=09', TOKEN]
];
const extraCsv = [['/api/admin/measurements.csv?year=2026', TOKEN], ['/api/admin/measurements.csv', TOKEN], ['/api/admin/measurements?year=abc', TOKEN]];
if (phase === 'functional2') functional.splice(0, functional.length, ...extraCsv);
const ratelimit = Array.from({ length: 13 }, () => ['/api/admin/measurements/periods', TOKEN]);
async function call(base, [path, token]) {
  const r = await fetch(base + path, { headers: h(token) });
  const text = await r.text();
  return { status: r.status, type: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), body: text };
}
(async () => {
  const list = phase === 'ratelimit' ? ratelimit : functional;
  let bad = 0; const codes = { node: [], php: [] };
  for (const c of list) {
    const a = await call(NODE, c); const b = await call(PHP, c);
    codes.node.push(a.status); codes.php.push(b.status);
    // Les rows contiennent des objets: comparaison JSON stricte du texte brut
    const same = JSON.stringify(a) === JSON.stringify(b);
    if (!same) { bad += 1; console.log('ÉCART', c[0], '\n node', JSON.stringify(a).slice(0, 400), '\n php ', JSON.stringify(b).slice(0, 400)); }
    else if (phase === 'functional') console.log('OK   ', c[0], c[1] === null ? '(sans jeton)' : c[1] === TOKEN ? '' : '(mauvais jeton)', '→', a.status, a.type);
  }
  if (phase === 'ratelimit') console.log('node', codes.node.join(' '), '\nphp ', codes.php.join(' '));
  console.log(bad ? `${bad} écart(s)` : 'identique');
  process.exit(bad ? 1 : 0);
})();
