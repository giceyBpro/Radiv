// Vérifie que les pages HTML statiques qui chargent app.js portent la bonne version dans l'adresse (app.js?v=<empreinte>) :
// les navigateurs gardent app.js en cache, la version force le rechargement dès qu'il change.
// Usage: node tests/check-asset-version.js        (contrôle)
//        node tests/check-asset-version.js --fix  (met les pages à jour)
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const root = path.resolve(__dirname, '..', 'public');
const version = crypto.createHash('sha1').update(fs.readFileSync(path.join(root, 'app.js'))).digest('hex').slice(0, 8);
const fix = process.argv.includes('--fix');
let failures = 0;
for (const name of fs.readdirSync(root).filter((n) => n.endsWith('.html'))) {
  const file = path.join(root, name);
  const html = fs.readFileSync(file, 'utf8');
  if (!/<script src="app\.js(\?v=[0-9a-z]*)?"><\/script>/.test(html)) continue;
  const wanted = `<script src="app.js?v=${version}"></script>`;
  if (html.includes(wanted)) { console.log(`OK    ${name}`); continue; }
  if (fix) {
    fs.writeFileSync(file, html.replace(/<script src="app\.js(\?v=[0-9a-z]*)?"><\/script>/, wanted));
    console.log(`MIS À JOUR ${name}`);
  } else {
    console.log(`ÉCHEC ${name} : version de app.js périmée (attendu ?v=${version}) — lancez : node tests/check-asset-version.js --fix`);
    failures += 1;
  }
}
process.exit(failures ? 1 : 0);
