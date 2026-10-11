// install.php, update.php et backend/src/lib/updater.php installent les mêmes fichiers aux mêmes endroits : mêmes listes
// blanches, même comportement de allowed(), et les fonctions communes d'install.php et update.php sont identiques
// (update.php est autonome : le code est dupliqué, ce contrôle empêche toute dérive). Aucun réseau.
// Usage: node tests/check-shared-code.js
const fs = require('fs'); const path = require('path'); const os = require('os'); const { execFileSync } = require('child_process');
const root = path.join(__dirname, '..');
const rd = (f) => fs.readFileSync(path.join(root, f), 'utf8');
const inst = rd('install.php'); const upd = rd('update.php'); const lib = rd('backend/src/lib/updater.php');
let pass = 0; let fail = 0;
const t = (name, cond, detail = '') => { if (cond) { pass += 1; console.log(`OK    ${name}`); } else { fail += 1; console.log(`ÉCHEC ${name} ${detail}`); } };

const list = (text, re) => [...((text.match(re) || [''])[0].matchAll(/'([^']+)'/g))].map((m) => m[1]).sort().join(',');
const phpList = (text, name) => list(text, new RegExp(`\\$${name} = array\\(([\\s\\S]*?)\\n?\\);`));
const libList = (name) => list(lib, new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`));
for (const name of ['FRONTEND_FILES', 'REQUIRED_FILES']) {
  const a = phpList(inst, name); const b = phpList(upd, name); const c = libList(name);
  t(`${name} identiques (install.php, update.php, updater.php)`, a !== '' && a === b && a === c, `${a}\n${b}\n${c}`);
}

// Fonctions communes copiées à l'identique
const fn = (text, name) => (text.match(new RegExp(`^function ${name}\\(.*?^\\}\\n`, 'ms')) || [''])[0];
const shared = ['h', 'page', 'starts_with', 'ends_with', 'has_text', 'rrmdir', 'make_dir', 'guard_dir', 'gh_headers', 'http_get', 'download_to', 'allowed', 'dest_of',
  'extract_archive', 'lint_php', 'parse_env_file', 'normalize_path', 'first_valid_token', 'htaccess_blocks', 'backend_deny_block', 'htaccess_head', 'copy_atomic', 'health_get', 'backend_url_path', 'home_dir', 'resolve_backend_dir', 'env_line', 'render_runtime_env'];
const drift = shared.filter((n) => fn(inst, n) === '' || fn(inst, n) !== fn(upd, n));
t(`${shared.length} fonctions communes identiques dans install.php et update.php`, drift.length === 0, drift.join(', '));

const layout = (text) => (text.match(/^\$RUNTIME_LAYOUT = <<<'LAYOUT'\n[\s\S]*?^LAYOUT;\n/m) || [''])[0];
t('description de runtime.env ($RUNTIME_LAYOUT) identique dans install.php et update.php', layout(inst) !== '' && layout(inst) === layout(upd));

// Comportement de allowed() : la version de l'installeur / du secours et celle de l'application donnent les mêmes réponses
const paths = ['public/index.html', 'public/v1.html', 'public/app.js', 'public/robots.txt', 'public/favicon.ico', 'public/config.js', 'public/.htaccess', 'public/.env', 'public/sitemap.xml',
  'public/api/index.php', 'public/api/.htaccess', 'public/api/backend.php', 'public/api/evil.php', 'public/api/lib/x.php', 'public/downloads/a.xml', 'public/downloads/sub/a.xml', 'public/downloads/.hidden',
  'public/vendor/fonts.css', 'public/vendor/fonts/a.woff2', 'public/vendor/evil.php', 'public/vendor/fonts/evil.phtml', 'public/vendor/a/b/c.js', 'public/vendor/.htaccess',
  'backend/.htaccess', 'backend/src/app.php', 'backend/src/calculation.php', 'backend/src/lib/config.php', 'backend/src/lib/sub/x.php', 'backend/src/x.txt', 'backend/src/.hidden.php',
  'backend/assets/leaflet.js', 'backend/assets/leaflet.css', 'backend/assets/LEAFLET-LICENSE.txt', 'backend/assets/a.php', 'backend/assets/sub/a.js', 'backend/config/runtime.env', 'backend/config/local.env',
  'backend/data/logs/a.jsonl', 'backend/evil.php', 'tests/a.php', 'install.php', 'update.php', 'deploy.sh', '.env', '../x', 'public/../x', '/etc/passwd', 'public//index.html', 'public\\index.html', 'index.html', 'api/index.php', ''];
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'shared-'));
try {
  const libAllowed = fn(lib, 'allowed').replace('function allowed(string $rel): bool', 'function allowed($rel)');
  const frontend = (phpList(inst, 'FRONTEND_FILES')).split(',').map((x) => `'${x}'`).join(',');
  fs.writeFileSync(path.join(tmp, 'lib.php'), `<?php\nconst FRONTEND_FILES = [${frontend}];\n${libAllowed}\necho json_encode(array_map('allowed', json_decode(file_get_contents($argv[1]), true)));\n`);
  fs.writeFileSync(path.join(tmp, 'inst.php'), `<?php\n$FRONTEND_FILES = array(${frontend});\n${fn(inst, 'allowed')}\necho json_encode(array_map('allowed', json_decode(file_get_contents($argv[1]), true)));\n`);
  fs.writeFileSync(path.join(tmp, 'paths.json'), JSON.stringify(paths));
  const a = JSON.parse(execFileSync('php', [path.join(tmp, 'lib.php'), path.join(tmp, 'paths.json')]).toString());
  const b = JSON.parse(execFileSync('php', [path.join(tmp, 'inst.php'), path.join(tmp, 'paths.json')]).toString());
  const diff = paths.filter((p, i) => a[i] !== b[i]);
  t(`allowed() : mêmes réponses (application / installeur) sur ${paths.length} chemins`, diff.length === 0, diff.join(', '));
  const accepted = paths.filter((p, i) => a[i]);
  t('seuls les fichiers attendus sont acceptés', accepted.every((p) => !/evil|\.env|config\/|data\/|tests\/|deploy|backend\.php|\.\.|sitemap|config\.js|\/\.htaccess$/.test(p) || p === 'public/api/.htaccess' || p === 'backend/.htaccess'), accepted.join(' '));
} finally { fs.rmSync(tmp, { recursive: true, force: true }); }
console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
