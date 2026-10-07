// Compare, requête par requête, les réponses HTTP de l'API Node (référence) et de l'API PHP:
// statut, en-têtes de sécurité/CORS/contenu et corps JSON (comparé après parsing, donc
// indifférent au formatage "1e+300" / "1.0e+300"). Les champs d'horodatage sont ignorés.
// Usage: node api/tests/compare-http.js [urlNode=http://127.0.0.1:3003] [urlPhp=http://127.0.0.1:8081]
const NODE = process.argv[2] || 'http://127.0.0.1:3003';
const PHP = process.argv[3] || 'http://127.0.0.1:8081';
const HEADERS = ['content-type', 'content-security-policy', 'access-control-allow-origin', 'access-control-allow-headers',
  'access-control-allow-methods', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'strict-transport-security'];
const j = (o) => JSON.stringify(o);
const post = (body, extra = {}) => ({ method: 'POST', path: '/api/calculate', body: typeof body === 'string' ? body : j(body), headers: { 'Content-Type': 'application/json', ...extra } });
const get = (path, headers = {}) => ({ method: 'GET', path, headers });
const cases = [
  get('/health'), get('/api/config'), get('/api/public-config'), get('/api/nope'), get('/api/nope', { Accept: 'text/html' }),
  get('/api/calculate'), { method: 'OPTIONS', path: '/api/calculate', headers: {} },
  get('/api/admin/measurements'), get('/api/admin/measurements/periods'),
  post({ isotope_code: 'psma_177lu', dose_rate: 100, patient_size_cm: 150, cure_count: 4, calculation_mode: 'local' }),
  post({ isotope_code: 'iode131_25_fixation', dose_rate: 20, patient_size_cm: 160 }),
  post({ isotope_code: 'iode131_benin', benign_activity_mbq: 400, benign_fixation_pct: 15, patient_size_cm: 170 }),
  post({ isotope_code: 'non_defini', dose_rate: 50, patient_size_cm: 170, user_period_days: 3 }),
  post({ isotope_code: 'mibg_131i', dose_rate: 80, patient_size_cm: 165, user_hours_1: 4, user_distance_1: 0.4, user_hours_2: 2, user_limit: 3 }),
  post({ isotope_code: 'radium223', patient_size_cm: 150 }),
  post({ isotope_code: 'zzz', dose_rate: 5, patient_size_cm: 150, calculation_mode: 'LOCAL' }),
  post({ isotope_code: 'psma_177lu', dose_rate: 5, patient_size_cm: 150, cure_count: 3 }),
  post({ calculation_mode: 'sfmn', isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150 }),
  post({}), post(''), post('{pas du json'), post('null'), post('5'), post('"x"'), post('[]'), post('[1,2]'),
  post({ isotope_code: 'radium223', dose_rate: 100, patient_size_cm: 150, calculation_mode: 0 }),
  // /api/contact: validations avant tout envoi (reCAPTCHA non configuré => refus, des deux côtés)
  ...[{}, { email: 'a@b.fr' }, { message: 'x' }, { email: 'a b@c.fr', message: 'x' }, { email: 'a@b.fr\nBcc: x@y.z', message: 'x' },
    { email: 0, message: 'x' }, { email: ' a@b.fr ', message: ' bonjour ' }, { email: 'a@b.fr', message: 'x', recaptcha_token: 'tok' }]
    .map((b) => ({ ...post(b), path: '/api/contact' })),
  { ...post('{pas du json'), path: '/api/contact' }, { ...post('null'), path: '/api/contact' }, { ...post(''), path: '/api/contact' },
  ...Array.from({ length: 5 }, () => ({ ...post({ email: 'a@b.fr', message: 'x' }), path: '/api/contact' })), // dépasse 5/min => 429
  get('/api/contact'),
  // Non comparé: corps > 64 Ko. Node coupe la connexion sans réponse (req.destroy); PHP répond
  // proprement 413 {"ok":false,"error":"Requête trop volumineuse."} — écart volontaire.
];
const strip = (v) => JSON.parse(JSON.stringify(v, (k, x) => (k === 'time' ? undefined : x)));
async function call(base, c) {
  const res = await fetch(base + c.path, { method: c.method, headers: c.headers, body: c.body });
  const text = await res.text();
  let body = text;
  try { body = strip(JSON.parse(text)); } catch { /* HTML ou vide */ }
  const headers = Object.fromEntries(HEADERS.map((h) => [h, res.headers.get(h)]));
  return { status: res.status, headers, body };
}
(async () => {
  let bad = 0;
  for (const c of cases) {
    const label = `${c.method} ${c.path} ${(c.body || '').slice(0, 70)}`;
    const [a, b] = await Promise.all([call(NODE, c).catch((e) => ({ err: String(e) })), call(PHP, c).catch((e) => ({ err: String(e) }))]);
    const sa = j(a); const sb = j(b);
    if (sa === sb) { console.log(`OK    ${label}`); continue; }
    bad += 1;
    console.log(`ÉCART ${label}`);
    for (const key of ['status', 'headers', 'body', 'err']) {
      if (j(a[key]) !== j(b[key])) console.log(`   ${key}\n     node: ${j(a[key])?.slice(0, 300)}\n     php : ${j(b[key])?.slice(0, 300)}`);
    }
  }
  console.log(bad ? `\n${bad} écart(s)` : '\nToutes les réponses sont identiques.');
  process.exit(bad ? 1 : 0);
})();
