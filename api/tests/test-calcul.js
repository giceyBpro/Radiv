// Non-régression du calcul: calculation.php doit redonner exactement les résultats figés dans
// golden-calcul.json (valeurs, ordre des clés, nombres), dans les deux modes d'arrondi.
// Le fichier a été produit par l'ancienne implémentation de référence (voir gen-golden.js).
// Usage: node api/tests/test-calcul.js
const { execFileSync } = require('child_process');
const path = require('path');
const golden = require('./golden-calcul.json');

let failures = 0;
for (const mode of ['round', 'floor']) {
  const out = execFileSync('php', [path.join(__dirname, 'calc-cli.php')], {
    input: JSON.stringify(golden.cases),
    env: { ...process.env, RESTRICTION_ROUNDING_MODE: mode },
    maxBuffer: 1 << 28
  }).toString();
  const got = JSON.parse(out);
  let bad = 0;
  golden.cases.forEach((c, i) => {
    const a = JSON.stringify(golden.expected[mode][i]);
    const b = JSON.stringify(got[i]);
    if (a !== b) {
      bad += 1;
      if (bad <= 5) console.log(`ÉCART [${mode}] cas #${i}: ${JSON.stringify(c)}\n  attendu: ${a.slice(0, 500)}\n  obtenu : ${b.slice(0, 500)}`);
    }
  });
  console.log(`[${mode}] ${golden.cases.length} cas, ${bad} écart(s)`);
  failures += bad;
}
process.exit(failures ? 1 : 0);
