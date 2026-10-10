// Vérifie que install.php et deploy.sh produisent EXACTEMENT le même config/runtime.env :
//   1. la description (sections, variables, commentaires) est identique dans les deux fichiers ;
//   2. chaque variable décrite est réellement lue quelque part dans le backend (pas de faute de frappe) ;
//   3. les deux rendus, exécutés pour de vrai sur le même jeu de valeurs, donnent le même fichier
//      (hors les 3 premières lignes d'en-tête, qui nomment le générateur et la date) ;
//   4. le fichier produit est relu correctement par le chargeur de backend/src/lib/config.php.
// Aucun réseau. Usage: node tests/check-runtime-layout.js
const fs = require('fs'); const path = require('path'); const os = require('os'); const { execFileSync } = require('child_process');
const root = path.join(__dirname, '..');
const inst = fs.readFileSync(path.join(root, 'install.php'), 'utf8'); const dep = fs.readFileSync(path.join(root, 'deploy.sh'), 'utf8');
let pass = 0; let fail = 0;
const t = (name, cond, detail = '') => { if (cond) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };

const phpLayout = (inst.match(/\$RUNTIME_LAYOUT = <<<'LAYOUT'\n([\s\S]*?)\nLAYOUT;/) || [])[1];
const shLayout = (dep.match(/RUNTIME_LAYOUT="\$\(cat <<'LAYOUT'\n([\s\S]*?)\nLAYOUT\n\)"/) || [])[1];
t('description trouvée dans install.php et deploy.sh', !!phpLayout && !!shLayout);
t('descriptions identiques (sections, variables, commentaires)', phpLayout === shLayout);
const names = (phpLayout || '').split('\n').filter((l) => l && !l.startsWith('== ') && !l.startsWith('|')).map((l) => l.split('|')[0]);
t('aucune variable en double', new Set(names).size === names.length);

const apiSources = execFileSync('sh', ['-c', `cat ${root}/backend/src/app.php ${root}/backend/src/lib/*.php ${root}/backend/src/calculation.php`]).toString();
const unread = names.filter((n) => !apiSources.includes(`'${n}'`));
t('chaque variable décrite est lue par le code du backend', unread.length === 0, unread.join(', '));

// Jeu de valeurs commun (TRUSTED_PROXIES défini mais vide; mot de passe avec guillemet double)
const values = { SITE_PUBLIC_URL: 'https://www.exemple.fr', API_PUBLIC_URL: 'https://www.exemple.fr/api', API_CORS_ORIGIN: 'https://www.exemple.fr', TRUSTED_PROXIES: '',
  GOOGLE_CLIENT_ID: 'abc.apps.googleusercontent.com', GOOGLE_CLIENT_SECRET: 'secret-google', ADMIN_GOOGLE_EMAILS: 'moi@example.org, autre@example.org', ADMIN_UPDATE_ENABLED: 'true',
  UPDATE_GITHUB_REPO: 'giceyBpro/Radiv', SMTP_HOST: 'smtp.example.org', SMTP_PORT: '587', SMTP_PASS: 'p@ss"w0rd', RESTRICTION_ROUNDING_MODE: 'floor', LOGS_MAX_BYTES: '5242880' };
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'layout-'));
try {
  // rendu PHP: fonctions extraites d'install.php
  const envLine = (inst.match(/function env_line\(\$name, \$value\)\n\{[\s\S]*?\n\}\n/) || [])[0];
  const render = (inst.match(/function render_runtime_env\(\$vars\)\n\{[\s\S]*?\n\}\n\n/) || [])[0];
  t('fonctions de rendu trouvées dans install.php', !!envLine && !!render);
  const phpFile = path.join(tmp, 'render.php');
  fs.writeFileSync(phpFile, `<?php\n$RUNTIME_LAYOUT = <<<'LAYOUT'\n${phpLayout}\nLAYOUT;\n${envLine}\n${render}\necho render_runtime_env(json_decode(file_get_contents($argv[1]), true));\n`);
  const jsonFile = path.join(tmp, 'vars.json'); fs.writeFileSync(jsonFile, JSON.stringify(values));
  const phpOut = execFileSync('php', [phpFile, jsonFile]).toString();

  // rendu bash: bloc extrait de deploy.sh
  const shBlock = dep.slice(dep.indexOf('RUNTIME_LAYOUT="$(cat'), dep.indexOf('mkdir -p "$BACKEND_DIR/config"\ninstall -m 600'));
  const shFile = path.join(tmp, 'render.sh'); fs.writeFileSync(shFile, `log_warn() { :; }\n${shBlock}\nrender_runtime_env\n`);
  const shOut = execFileSync('bash', [shFile], { env: { PATH: process.env.PATH, ...values } }).toString();

  const body = (s) => s.split('\n').slice(3).join('\n');
  t('en-têtes: le générateur est nommé', /install\.php/.test(phpOut.split('\n')[1]) && /deploy\.sh/.test(shOut.split('\n')[1]));
  t('les deux rendus sont IDENTIQUES (hors en-tête)', body(phpOut) === body(shOut), (() => { const a = body(phpOut).split('\n'); const b = body(shOut).split('\n'); const i = a.findIndex((l, k) => l !== b[k]); return `ligne ${i}: «${a[i]}» ≠ «${b[i]}»`; })());
  t('variables définies écrites, les autres en commentaire « #NOM= »', phpOut.includes('SITE_PUBLIC_URL="https://www.exemple.fr"') && phpOut.includes(`SMTP_PASS='p@ss"w0rd'`) && phpOut.includes('TRUSTED_PROXIES=""') && /^#RECAPTCHA_SECRET_KEY=$/m.test(phpOut) && !/^RECAPTCHA_SECRET_KEY=/m.test(phpOut));
  t('sections lisibles (titres encadrés, lignes blanches)', (phpOut.match(/^# -{20,}$/gm) || []).length === 2 * (phpLayout.match(/^== /gm) || []).length && /\n\n# URL publique du site/.test(phpOut));

  // relecture par le chargeur de backend/src/lib/config.php
  const loader = path.join(tmp, 'load.php'); const out = path.join(tmp, 'runtime.env'); fs.writeFileSync(out, phpOut);
  fs.writeFileSync(loader, `<?php define('RADIV_ENTRY', true); require '${root}/backend/src/lib/config.php'; Radiv\\Config\\load_dotenv($argv[1]); $r = []; foreach (array_keys(json_decode(file_get_contents($argv[2]), true)) as $n) { $r[$n] = getenv($n); } $r['_RECAPTCHA_SECRET_KEY'] = getenv('RECAPTCHA_SECRET_KEY'); echo json_encode($r);`);
  const read = JSON.parse(execFileSync('php', [loader, out, jsonFile]).toString());
  t('relu à l\'identique par le site (guillemets, vide défini, commentaires ignorés)', Object.keys(values).every((k) => read[k] === values[k]) && read._RECAPTCHA_SECRET_KEY === false, JSON.stringify(read));
} finally { fs.rmSync(tmp, { recursive: true, force: true }); }
console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
