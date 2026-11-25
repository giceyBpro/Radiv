// Comparaison automatique RadIV / SFMN : tirage, valeurs, classement des anomalies, e-mail, nouveaux essais, délai minimal, rattrapage.
// SFMN simulée (aucun appel réel). Usage: node tests/test-sfmn-audit.js
const { execFileSync } = require('child_process'); const fs = require('fs'); const os = require('os'); const path = require('path');
let pass = 0; let fail = 0;
const t = (n, c, d = '') => { if (c) { pass += 1; console.log(`OK    ${n}`); } else { fail += 1; console.log(`ÉCHEC ${n} ${d}`); } };
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sfmn-audit-'));
const out = JSON.parse(execFileSync('php', [path.join(__dirname, 'sfmn-audit-cli.php'), dir], { encoding: 'utf8' }));
const exec = (log) => log.filter((x) => x.startsWith('cas_execute')).length;
t('tirage : un cycle de 14 jours couvre les 14 radiopharmaceutiques, tous distincts', out.cycle.distincts === 14 && out.cycle.tous === true);
t('iode bénin : 4 cas, 3 fixations standard (15, 25, 30), activités 6000-8000 MBq, reproductible par la graine', out.benin.n === 4 && out.benin.fixations_standard && out.benin.fixations_valides && out.benin.activites && out.benin.reproductible && out.benin.graine_differente && out.benin.sans_debit, JSON.stringify(out.benin));
t('autre isotope : 4 cas, débit 300-1500 µSv/h, taille 120-210 cm, nombre de cures permis', out.psma.n === 4 && out.psma.debit && out.psma.taille && out.psma.cures, JSON.stringify(out.psma));
t('journée concordante : 4 cas, aucune anomalie, aucun e-mail', exec(out.ok.log) === 4 && out.ok.log.every((x) => !x.includes('discordance')) && out.ok.log.at(-1) === 'jour_termine' && out.ok.mails === 0 && out.ok.counts_ok === 4, JSON.stringify(out.ok));
t('rapport : version du moteur et arrondi « floor » enregistrés', out.ok.engine === true && out.ok.rounding === 'floor');
t('discordance : détectée, e-mail unique avec tableau des écarts, graine et lien vers le rapport', exec(out.discordance.log) === 4 && out.discordance.mails === 1 && /4 × Discordance de calcul/.test(out.discordance.subject) && out.discordance.html_table && out.discordance.html_seed && out.discordance.html_link, JSON.stringify(out.discordance));
t('SFMN indisponible : 3 nouveaux essais espacés, puis conclusion et e-mail « SFMN indisponible »', out.indisponible.retries === 3 && out.indisponible.counts === 4 && out.indisponible.mails === 1 && /4 × SFMN indisponible/.test(out.indisponible.subject) && out.indisponible.log.filter((x) => x === 'nouvel_essai_programme').length === 3, JSON.stringify(out.indisponible));
t('page SFMN modifiée : catégorie « Format de la page SFMN modifié », e-mail', out.format.log[0] === 'cas_execute:sfmn_format' && out.format.mails === 1 && /Format de la page SFMN modifié/.test(out.format.subject), JSON.stringify(out.format));
t('réponse SFMN vide : catégorie dédiée', out.vide.log[0] === 'cas_execute:sfmn_vide' && out.vide.mails === 1 && /vide ou illisible/i.test(out.vide.subject), JSON.stringify(out.vide));
t('moteur en erreur : catégorie dédiée', out.moteur.log[0] === 'cas_execute:moteur_erreur' && out.moteur.mails === 1 && /erreur du moteur/i.test(out.moteur.subject), JSON.stringify(out.moteur));
t('délai minimal : deux calculs ne se suivent pas à moins de 120 s', out.delai[0] === 'cas_execute' && out.delai[1] === 'attente' && out.delai[2] === 'cas_execute', JSON.stringify(out.delai));
t('interrupteur : désactivée, rien ne s\'exécute', out.desactive === 'desactive');
t('rattrapage : 15 jours manqués, seuls les 7 plus récents sont traités, dans l\'ordre', out.rattrapage.jours.length === 7 && out.rattrapage.jours[0] === '2026-11-19' && out.rattrapage.jours.at(-1) === '2026-11-25', JSON.stringify(out.rattrapage));
t('avant 1 h du matin : la journée n\'est pas encore due', out.avant_1h === 'rien_a_faire', out.avant_1h);
fs.rmSync(dir, { recursive: true, force: true });
console.log(`\n${pass} réussis, ${fail} échec(s)`); process.exit(fail ? 1 : 0);
