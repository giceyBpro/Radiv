// Connexion alternative de /auth (identifiant + mot de passe + TOTP) : vecteur RFC 6238, rejeu, mauvais identifiants, session, choix Google/local.
// Usage: node tests/test-admin-login.js
const { spawn, execFileSync } = require('child_process'); const crypto = require('crypto'); const fs = require('fs'); const os = require('os'); const path = require('path');
const ROOT = path.join(__dirname, '..'); const PORT = 8099; const BASE = `http://127.0.0.1:${PORT}`;
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const b32 = (buf) => { let bits = ''; for (const b of buf) bits += b.toString(2).padStart(8, '0'); return (bits.match(/.{1,5}/g) || []).map((c) => A[parseInt(c.padEnd(5, '0'), 2)]).join(''); };
const unb32 = (s) => { let bits = ''; for (const c of s) bits += A.indexOf(c).toString(2).padStart(5, '0'); return Buffer.from((bits.match(/.{8}/g) || []).map((x) => parseInt(x, 2))); };
const totp = (secret, time) => { const c = Buffer.alloc(8); c.writeBigUInt64BE(BigInt(Math.floor(time / 30))); const h = crypto.createHmac('sha1', unb32(secret)).update(c).digest(); const o = h[19] & 15; return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0'); };
const php = (code) => execFileSync('php', ['-r', `define('RADIV_ENTRY',1); require '${ROOT}/backend/src/lib/admin_login.php'; ${code}`], { encoding: 'utf8' });
(async () => {
  // Vecteur RFC 6238 : secret ASCII « 12345678901234567890 », t = 59 s → 94287082 (8 chiffres) donc 287082 sur 6.
  const rfc = b32(Buffer.from('12345678901234567890'));
  t('TOTP : vecteur RFC 6238 (côté Node, référence)', totp(rfc, 59) === '287082');
  t('TOTP : le code PHP est identique (RFC 6238)', php(`echo Radiv\\AdminLogin\\code_at(Radiv\\AdminLogin\\base32_decode('${rfc}'), 1);`) === '287082');
  t('base32 : aller-retour', php(`echo Radiv\\AdminLogin\\base32_encode(Radiv\\AdminLogin\\base32_decode('${rfc}'));`) === rfc);

  const secret = b32(crypto.randomBytes(20));
  const hash = execFileSync('php', ['-r', "echo password_hash('Un mot de passe solide', PASSWORD_DEFAULT);"], { encoding: 'utf8' });
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'login-data-'));
  const env = { ...process.env, DATA_DIR: data, SITE_PUBLIC_URL: BASE, ADMIN_ALLOW_INSECURE_HTTP: 'true', ADMIN_LOGIN_USER: 'admin', ADMIN_LOGIN_PASSWORD_HASH: hash, ADMIN_TOTP_SECRET: secret, GOOGLE_CLIENT_ID: '', GOOGLE_CLIENT_SECRET: '', ADMIN_GOOGLE_EMAILS: '' };
  const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tests/dev-router.php')], { env, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 1200));
  let cookie = '';
  const req = async (method, url, form) => {
    const res = await fetch(BASE + url, { method, redirect: 'manual', headers: { Cookie: cookie, ...(form ? { 'Content-Type': 'application/x-www-form-urlencoded', Origin: BASE } : {}) }, body: form ? new URLSearchParams(form).toString() : undefined });
    const sc = res.headers.get('set-cookie'); if (sc) cookie = sc.split(';')[0];
    return { status: res.status, text: await res.text(), loc: res.headers.get('location') };
  };
  const login = async (over = {}) => {
    const page = await req('GET', '/auth');
    const csrf = (page.text.match(/name="csrf" value="([0-9a-f]+)"/) || [])[1];
    return { page, res: await req('POST', '/auth/connexion', { csrf, login: 'admin', password: 'Un mot de passe solide', code: totp(secret, Date.now() / 1000), ...over }) };
  };
  const first = await req('GET', '/auth');
  t('/auth sans session : formulaire de connexion (Google absent)', first.status === 200 && /name="password"/.test(first.text) && !/avec Google/.test(first.text));
  let r = await login({ password: 'mauvais' });
  t('mauvais mot de passe refusé, message unique', r.res.status === 401 && /Identifiants ou code incorrects/.test(r.res.text));
  cookie = ''; r = await login({ login: 'autre' });
  t('mauvais identifiant : même message', r.res.status === 401 && /Identifiants ou code incorrects/.test(r.res.text));
  cookie = ''; r = await login({ code: '000000' });
  t('mauvais code refusé', r.res.status === 401);
  cookie = ''; r = await login({ csrf: 'faux' });
  t('jeton CSRF invalide : refusé (404 générique)', r.res.status === 404);
  cookie = ''; r = await login();
  t('bons identifiants + code : connecté (303 vers /auth)', r.res.status === 303);
  const home = await req('GET', '/auth');
  t('session ouverte : page d\'administration', home.status === 200 && /Se déconnecter/.test(home.text) && !/name="password"/.test(home.text));
  cookie = ''; r = await login();
  t('même code rejoué : refusé (usage unique)', r.res.status === 401, String(r.res.status));
  srv.kill(); fs.rmSync(data, { recursive: true, force: true });
  console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
