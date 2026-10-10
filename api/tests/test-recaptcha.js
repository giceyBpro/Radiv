// Contrôles serveur de reCAPTCHA (succès, action, nom d'hôte, score) contre un faux service Google local.
// Usage: node api/tests/test-recaptcha.js
const http = require('http'); const { execFile } = require('child_process'); const path = require('path');
let reply = {};
const srv = http.createServer((req, res) => { req.resume(); req.on('end', () => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(reply)); }); });
let pass = 0; let fail = 0;
const t = (n, c) => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n}`); } };
srv.listen(0, '127.0.0.1', async () => {
  const port = srv.address().port;
  const run = (r, env = {}) => new Promise((resolve, reject) => { reply = r; execFile('php', [path.join(__dirname, 'recaptcha-cli.php')], { env: { ...process.env, RECAPTCHA_SECRET_KEY: 's', RECAPTCHA_VERIFY_URL: `http://127.0.0.1:${port}/v`, SITE_PUBLIC_URL: 'https://www.exemple.fr', ...env } }, (e, out) => (e ? reject(e) : resolve(out === 'oui'))); });
  const ok = { success: true, score: 0.9, action: 'contact_form', hostname: 'www.exemple.fr' };
  t('jeton valide accepté', await run(ok));
  t('échec Google refusé', !(await run({ success: false })));
  t('score trop bas refusé (0,2 < 0,5)', !(await run({ ...ok, score: 0.2 })));
  t('score limite accepté (0,5)', await run({ ...ok, score: 0.5 }));
  t('seuil réglable (RECAPTCHA_MIN_SCORE=0.8)', !(await run({ ...ok, score: 0.7 }, { RECAPTCHA_MIN_SCORE: '0.8' })) && await run({ ...ok, score: 0.9 }, { RECAPTCHA_MIN_SCORE: '0.8' }));
  t('seuil invalide → 0,5', await run({ ...ok, score: 0.6 }, { RECAPTCHA_MIN_SCORE: 'abc' }));
  t('autre action refusée', !(await run({ ...ok, action: 'login' })));
  t('autre site refusé', !(await run({ ...ok, hostname: 'evil.example' })));
  t('site sans www accepté', await run({ ...ok, hostname: 'exemple.fr' }));
  t('réponse v2 (sans score ni action) acceptée', await run({ success: true, hostname: 'www.exemple.fr' }));
  t('score non numérique refusé', !(await run({ ...ok, score: 'x' })));
  srv.close(); console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
});
