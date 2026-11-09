// Contrôle automatique des mises à jour (page Site) : à jour / disponible avec la liste des modifications / version inconnue / introuvable.
// Faux GitHub local. Usage: node tests/test-update-check.js
const http = require('http'); const { execFile } = require('child_process'); const fs = require('fs'); const os = require('os'); const path = require('path');
const A = 'a'.repeat(40); const B = 'b'.repeat(40); const C = 'c'.repeat(40);
const refs = { 'v2.0': B, main: B };
const srv = http.createServer((req, res) => {
  const send = (st, body, type = 'application/json') => { res.writeHead(st, { 'Content-Type': type }); res.end(typeof body === 'string' ? body : JSON.stringify(body)); };
  const m = req.url.match(/^\/repos\/giceyBpro\/Radiv\/commits\/(.+)$/);
  if (m) return refs[m[1]] ? send(200, refs[m[1]], 'text/plain') : send(404, {});
  if (req.url === `/repos/giceyBpro/Radiv/compare/${A}...${B}`) {
    return send(200, { status: 'ahead', commits: [
      { sha: '1111111' + '0'.repeat(33), commit: { message: 'Contact : ALTCHA\n\ndétail', author: { name: 'Moi', date: '2026-11-07T10:00:00Z' } } },
      { sha: '2222222' + '0'.repeat(33), commit: { message: 'Merge ccr-xyz into main', author: { name: 'Moi', date: '2026-11-07T11:00:00Z' } } },
      { sha: '3333333' + '0'.repeat(33), commit: { message: 'Connexion TOTP', author: { name: 'Moi', date: '2026-11-08T11:00:00Z' } } }],
    files: [{ filename: 'backend/src/app.php' }, { filename: 'public/contact.html' }, { filename: 'tests/test-captcha.js' }, { filename: 'docs/x.md' }] });
  }
  send(404, {});
});
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
srv.listen(0, '127.0.0.1', async () => {
  const port = srv.address().port; const data = fs.mkdtempSync(path.join(os.tmpdir(), 'upd-'));
  const run = (sha, ref) => new Promise((resolve, reject) => {
    fs.mkdirSync(path.join(data, 'var'), { recursive: true });
    if (sha) fs.writeFileSync(path.join(data, 'var/version.json'), JSON.stringify({ sha, ref: 'x' })); else fs.rmSync(path.join(data, 'var/version.json'), { force: true });
    execFile('php', [path.join(__dirname, 'updater-cli.php'), ref], { env: { ...process.env, DATA_DIR: data, UPDATE_GITHUB_API_URL: `http://127.0.0.1:${port}` } }, (e, out) => (e ? reject(e) : resolve(JSON.parse(out))));
  });
  let r = await run(B, 'v2.0');
  t('à jour : identique', r.ok && r.status === 'identical' && r.commits.length === 0);
  r = await run(A, 'v2.0');
  t('mise à jour disponible : 2 modifications (fusion masquée)', r.ok && r.status === 'ahead' && r.commits.length === 2 && r.ignored === 1 && r.commits[0][1] === 'Contact : ALTCHA' && r.commits[0][2] === '2026-11-07');
  t('fichiers du site listés, autres comptés', r.files.includes('backend/src/app.php') && r.files.includes('public/contact.html') && !r.files.includes('tests/test-captcha.js') && r.files_other === 2, JSON.stringify(r.files));
  r = await run(C, 'v2.0');
  t('version installée inconnue de GitHub : mise à jour proposée sans détail', r.ok && r.status === 'unknown' && r.commits.length === 0);
  r = await run(null, 'v2.0');
  t('aucune version installée : proposée sans comparaison', r.ok && r.current === null && r.status === 'unknown');
  r = await run(A, 'v9.9');
  t('version introuvable : erreur claire', !r.ok && /introuvable/.test(r.error));
  srv.close(); fs.rmSync(data, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
});
