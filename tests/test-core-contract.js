// Test de contrat du site envers le moteur partagé (core/) :
//   1. la version du moteur est de la majeure attendue et au moins égale à min_version (tests/core-contract.json) ;
//   2. le moteur redonne EXACTEMENT les résultats figés de la copie du site (tests/golden-core-contract.json),
//      dans les deux modes d'arrondi. Cette copie est volontairement distincte de celle du moteur (core/tests) :
//      si le moteur et sa référence changent ensemble, ce test échoue tant que le site n'a pas adopté le changement.
// Usage: node tests/test-core-contract.js
const { execFileSync } = require('child_process');
const path = require('path');
const contract = require('./core-contract.json');
const golden = require('./golden-core-contract.json');

const parse = (v) => v.split('.').map(Number);
const atLeast = (a, b) => { for (let i = 0; i < 3; i++) { if (a[i] !== b[i]) return a[i] > b[i]; } return true; };

let failures = 0;
const t = (name, ok, detail = '') => { console.log(`${ok ? 'OK   ' : 'ÉCHEC'} ${name}${ok ? '' : ' — ' + detail}`); if (!ok) failures += 1; };

const run = (mode) => JSON.parse(execFileSync('php', [path.join(__dirname, 'core-cli.php')], {
  input: JSON.stringify(golden.cases),
  env: { ...process.env, RESTRICTION_ROUNDING_MODE: mode },
  maxBuffer: 1 << 28
}).toString());

const first = run('round');
const version = first.version;
t(`version du moteur (${version}) : majeure ${contract.supported_major}, au moins ${contract.min_version}`,
  /^\d+\.\d+\.\d+$/.test(version) && parse(version)[0] === contract.supported_major && atLeast(parse(version), parse(contract.min_version)), version);

for (const mode of ['round', 'floor']) {
  const got = (mode === 'round' ? first : run(mode)).results;
  let bad = 0;
  golden.cases.forEach((c, i) => {
    if (JSON.stringify(golden.expected[mode][i]) !== JSON.stringify(got[i])) {
      bad += 1;
      if (bad <= 3) console.log(`  écart [${mode}] cas #${i}: ${JSON.stringify(c)}`);
    }
  });
  t(`[${mode}] ${golden.cases.length} cas identiques à la référence du site`, bad === 0, `${bad} écart(s)`);
}
process.exit(failures ? 1 : 0);
