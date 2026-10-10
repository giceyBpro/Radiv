// Aucun fichier PHP du backend ne doit s'exécuter hors de l'API (appelé directement, par exemple si le serveur ignorait
// les .htaccess) : sortie vide, aucune erreur. Usage: node tests/test-backend-guard.js
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const root = path.join(__dirname, '..', 'backend', 'src');
const files = []; const walk = (d) => { for (const n of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, n.name); n.isDirectory() ? walk(p) : p.endsWith('.php') && files.push(p); } }; walk(root);
let pass = 0; let fail = 0;
for (const f of files) {
  let out = ''; let ok = true;
  try { out = execFileSync('php', ['-d', 'display_errors=1', f], { stdio: ['ignore', 'pipe', 'pipe'] }).toString(); } catch (e) { ok = false; out = String(e.stdout || '') + String(e.stderr || ''); }
  const good = ok && out.trim() === '';
  console.log(`${good ? 'OK   ' : 'ÉCHEC'} ${path.relative(path.join(__dirname, '..'), f)} refuse de s'exécuter seul`); good ? pass += 1 : fail += 1;
}
console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
