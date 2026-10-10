// Faux Google (OpenID Connect) et faux GitHub (API + "codeload") pour tester la page
// d'administration sans réseau. Usage: node fake-services.js <dossier_zips> [portGoogle=9201] [portGithub=9202]
// Contrôle: GET http://127.0.0.1:<portGoogle>/ctl?email=...&verified=0|1 règle le compte qui "se connecte".
// GET http://127.0.0.1:<portGithub>/ctl/log renvoie (et vide) le journal des requêtes reçues.
const http = require('http'); const fs = require('fs'); const path = require('path'); const crypto = require('crypto');
const dir = process.argv[2]; const GP = Number(process.argv[3] || 9201); const HP = Number(process.argv[4] || 9202);
const CLIENT_ID = 'test-client-id.apps.googleusercontent.com'; const CLIENT_SECRET = 'test-secret';
const VALID_TOKEN = 'ghp_validtokenvalidtokenvalid1234'; const REPO = '/repos/giceyBpro/Radiv';
let who = { email: 'admin@example.org', verified: true, aud: CLIENT_ID };
const codes = new Map();
const b64 = (o) => Buffer.from(typeof o === 'string' ? o : JSON.stringify(o)).toString('base64url');

http.createServer((req, res) => {
  const u = new URL(req.url, `http://127.0.0.1:${GP}`);
  if (u.pathname === '/ctl') {
    if (u.searchParams.has('email')) who.email = u.searchParams.get('email');
    if (u.searchParams.has('verified')) who.verified = u.searchParams.get('verified') === '1';
    if (u.searchParams.has('aud')) who.aud = u.searchParams.get('aud');
    return res.end('ok');
  }
  if (u.pathname === '/auth') { // approbation automatique
    const q = Object.fromEntries(u.searchParams);
    if (q.client_id !== CLIENT_ID || q.code_challenge_method !== 'S256' || !q.code_challenge || q.response_type !== 'code' || q.scope !== 'openid email') { res.writeHead(400); return res.end('bad request'); }
    const code = crypto.randomBytes(8).toString('hex');
    codes.set(code, { nonce: q.nonce, challenge: q.code_challenge, redirect: q.redirect_uri, prompt: q.prompt });
    res.writeHead(302, { Location: `${q.redirect_uri}?code=${code}&state=${encodeURIComponent(q.state)}` }); return res.end();
  }
  if (u.pathname === '/token' && req.method === 'POST') {
    let body = ''; req.on('data', (c) => { body += c; });
    return req.on('end', () => {
      const f = Object.fromEntries(new URLSearchParams(body)); const c = codes.get(f.code); codes.delete(f.code);
      const okPkce = c && crypto.createHash('sha256').update(f.code_verifier || '').digest('base64url') === c.challenge;
      if (!c || f.client_secret !== CLIENT_SECRET || f.client_id !== CLIENT_ID || f.redirect_uri !== c.redirect || !okPkce) { res.writeHead(400, { 'Content-Type': 'application/json' }); return res.end('{"error":"invalid_grant"}'); }
      const claims = { iss: 'https://accounts.google.com', aud: who.aud, sub: '1234', email: who.email, email_verified: who.verified, nonce: c.nonce, exp: Math.floor(Date.now() / 1000) + 600 };
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ access_token: 'x', id_token: `${b64({ alg: 'none' })}.${b64(claims)}.sig`, token_type: 'Bearer' }));
    });
  }
  res.writeHead(404); res.end();
}).listen(GP, '127.0.0.1');

const refs = { main: '2', 'v1.2': '2', 'v1.1': '1', 'v1.0': '7' };
for (let i = 3; i <= 9; i += 1) refs[`t${i}`] = String(i);
refs.t10 = 'a';
const shaFor = (n) => (n === 'a' ? 'a' : String(n)).repeat(40);
let log = [];
http.createServer((req, res) => {
  const u = new URL(req.url, `http://127.0.0.1:${HP}`);
  const auth = req.headers.authorization || '';
  if (u.pathname === '/ctl/log') { res.end(JSON.stringify(log)); log = []; return; }
  log.push({ path: u.pathname, auth: auth ? 'present' : 'absent' });
  const json = (code, o) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
  if (u.pathname.startsWith('/codeload/')) { // téléchargement signé: ne doit JAMAIS recevoir le jeton
    const f = path.join(dir, `${u.pathname.split('/').pop().replace('.zip', '')}.zip`);
    if (!fs.existsSync(f)) return json(404, {});
    res.writeHead(200, { 'Content-Type': 'application/zip' }); return res.end(fs.readFileSync(f));
  }
  if (auth !== `Bearer ${VALID_TOKEN}`) return json(auth ? 401 : 404, { message: 'Bad credentials' });
  if (u.pathname === REPO) return json(200, { full_name: 'giceyBpro/Radiv' });
  if (u.pathname === `${REPO}/tags`) return json(200, [{ name: 'v1.2' }, { name: 'v1.1' }]);
  const m = u.pathname.match(new RegExp(`^${REPO}/commits/(.+)$`));
  if (m) { const r = refs[decodeURIComponent(m[1])]; if (!r) return json(404, {}); res.writeHead(200, { 'Content-Type': 'text/plain' }); return res.end(shaFor(r)); }
  const z = u.pathname.match(new RegExp(`^${REPO}/zipball/([0-9a-f]{40})$`));
  if (z) { res.writeHead(302, { Location: `http://127.0.0.1:${HP}/codeload/${z[1][0] === 'a' ? 10 : z[1][0]}.zip` }); return res.end(); }
  json(404, {});
}).listen(HP, '127.0.0.1');
console.log('faux google/github prêts');
