const http = require('http');
const fs = require('fs');
const path = require('path');

function loadDotEnv(filePath) {
  if (!fs.existsSync(filePath)) return;
  const content = fs.readFileSync(filePath, 'utf8');
  content.split(/\r?\n/).forEach((line) => {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) return;
    const idx = trimmed.indexOf('=');
    if (idx === -1) return;
    const key = trimmed.slice(0, idx).trim();
    const value = trimmed.slice(idx + 1).trim();
    if (!process.env[key]) process.env[key] = value;
  });
}

loadDotEnv(path.join(__dirname, '.runtime.env'));
loadDotEnv(path.join(__dirname, '.env'));

const port = Number(process.env.PORT || 3000);
const corsOrigin = process.env.CORS_ORIGIN || '*';

const isotopes = [
  { api_code: 'iode131_0_fixation', label: 'Iode-131-0%-fixation', periodHours: 16, reference: 'Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', remark: '-', situation: 'Cancer opéré' },
  { api_code: 'iode131_5_fixation', label: 'Iode-131-5%-fixation', periodHours: 16, reference: 'Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', remark: 'en considérant la période au niveau de la thyroïde', situation: 'Cancer oligo-métastasé' },
  { api_code: 'iode131_25_fixation', label: 'Iode-131-25%-fixation', periodHours: 16, reference: 'Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', remark: 'en considérant la période au niveau de la thyroïde', situation: 'Cancer poly-métastasé' },
  { api_code: 'iode131_benin', label: 'Iode-131-Bénin', periodHours: 122.4, reference: 'Nuclear Medicine Communications 2006, 27:559–566', remark: 'en considérant la période au niveau de la thyroïde', situation: 'Pathologie bénigne' },
  { api_code: 'radium223', label: 'Radium-223', periodHours: 11.43 * 24, reference: '-', remark: 'Demi vie physique', situation: '' },
  { api_code: 'psma_177lu', label: 'PSMA-177Lu', periodHours: 40, reference: 'EANM procedure guidelines for radionuclide therapy with 177Lu-labelled PSMA-ligands 2019', remark: 'Valeur la plus longue proposée', situation: '' },
  { api_code: 'synovectomie_90y', label: 'Synovectomie-90Y', periodHours: 2.67 * 24, reference: 'EANM Procedure Guidelines for Radiosynovectomy 2003', remark: 'Demi vie physique', situation: '' },
  { api_code: 'synovectomie_186re', label: 'Synovectomie-186Re', periodHours: 3.7 * 24, reference: 'EANM Procedure Guidelines for Radiosynovectomy 2003', remark: 'Demi vie physique', situation: '' },
  { api_code: 'synovectomie_169er', label: 'Synovectomie-169Er', periodHours: 9.4 * 24, reference: 'EANM Procedure Guidelines for Radiosynovectomy 2003', remark: 'Demi vie physique', situation: '' },
  { api_code: 'microspheres_90y', label: 'Microsphères-90Y', periodHours: 64.2, reference: 'EANM procedure guideline for the treatment of liver cancer and liver metastases with intra-arterial radioactive compounds 2011', remark: 'Demi vie physique', situation: '' },
  { api_code: 'lipiodol_131i', label: 'Lipiodol-131I', periodHours: 8.04 * 24, reference: 'EANM procedure guideline for the treatment of liver cancer and liver metastases with intra-arterial radioactive compounds 2011', remark: 'Demi vie physique', situation: '' },
  { api_code: 'lutetium177_net', label: 'Lutétium-177 NET', periodHours: 100, reference: 'Fitschen et al, Z Med Phys 2011, Levart et al, EJNMMI Phys 2019', remark: 'Demi vie effective', situation: '' },
  { api_code: 'mibg_131i', label: 'MIBG-131I', periodHours: 10.6, reference: 'Nucl. Med. Commun. 16 (1995) 767–772', remark: 'un peu plus longue chez l’adulte que chez l’enfant', situation: '' },
  { api_code: 'non_defini', label: 'Non défini', periodHours: null, reference: '-', remark: '-', situation: '' }
];

const scenarios = [
  { label: 'Contact avec le (la) conjoint(e) > 60 ans', exposures: [[8, 0.3], [3, 1]], limit: 15, condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 15 mSv' },
  { label: 'Contact avec le (la) conjoint(e) < 60 ans', exposures: [[8, 0.3], [3, 1]], limit: 3, condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 3 mSv' },
  { label: 'Contact avec la conjointe enceinte', exposures: [[8, 0.3], [3, 1]], limit: 1, condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 1 mSv' },
  { label: 'Transport en commun', exposures: [[3, 0.5]], limit: 1, condition: '3 h à 0,5 m,\nlimite 1 mSv' },
  { label: 'Contact avec un enfant (<3 ans) au retour à la maison', exposures: [[9, 1]], limit: 1, condition: '9 h à 1 m,\nlimite 1 mSv' },
  { label: 'Contact avec un enfant (entre 3 et 11 ans) au retour à la maison', exposures: [[2, 0.5], [2, 1]], limit: 1, condition: '2 h à 0,5 m et 2 h à 1 m,\nlimite 1 mSv' },
  { label: 'Contact avec des collègues de travail', exposures: [[6, 1]], limit: 1, condition: '6 h à 1 m,\nlimite 1 mSv' }
];

const toNumberOrNull = (value) => {
  if (value === '' || value === null || value === undefined) return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
};

const getIsotope = (apiCode) => isotopes.find((i) => i.api_code === apiCode) || isotopes[0];
const decayFraction = (hours, effectiveDays) => 1 - Math.exp(-((Math.log(2) / effectiveDays) * (hours / 24)));
const geometryFactor = (distance, patientSizeCm) => Math.atan(patientSizeCm / (2 * distance * 100)) / (patientSizeCm * distance / 200);
const commonFactor = (effectiveDays) => ((effectiveDays * 24) / Math.log(2)) / (1 - Math.exp(-(Math.log(2) / effectiveDays)));

const restrictionDays = (effectiveDays, doseRate, patientSizeCm, exposures, limit) => {
  const denominator = exposures
    .map(([hours, distance]) => decayFraction(hours, effectiveDays) * geometryFactor(distance, patientSizeCm))
    .reduce((sum, value) => sum + value, 0) * commonFactor(effectiveDays);

  if (!(denominator > 0)) return null;
  const ratio = (limit * 1000) / denominator;
  const day = -(effectiveDays / Math.log(2)) * Math.log(ratio / doseRate);
  if (!Number.isFinite(day)) return null;
  return Math.max(0, Math.round(day));
};

const computeDoseRate = (selected, benignActivityMbq, benignFixationPct, doseRate) => {
  if (selected.api_code !== 'iode131_benin') return toNumberOrNull(doseRate);
  if (benignActivityMbq === null || benignFixationPct === null) return null;
  return 2.2 * benignActivityMbq * (benignFixationPct / 100) / 37;
};


function expectedPayloadByIsotope(selected) {
  const common = [
    'isotope_code',
    'patient_size_cm',
    'user_period_days',
    'user_hours_1',
    'user_distance_1',
    'user_hours_2',
    'user_limit'
  ];

  if (selected.api_code === 'iode131_benin') {
    return {
      common,
      isotope_specific_required: ['benign_activity_mbq', 'benign_fixation_pct'],
      isotope_specific_optional: ['dose_rate (ignore pour iode131_benin)']
    };
  }

  if (selected.api_code === 'non_defini') {
    return {
      common,
      isotope_specific_required: ['dose_rate', 'user_period_days'],
      isotope_specific_optional: ['benign_activity_mbq', 'benign_fixation_pct (ignores hors iode131_benin)']
    };
  }

  return {
    common,
    isotope_specific_required: ['dose_rate'],
    isotope_specific_optional: ['benign_activity_mbq', 'benign_fixation_pct (ignores hors iode131_benin)']
  };
}

function calculate(payload) {
  const selected = getIsotope(payload.isotope_code);
  const userPeriodDays = toNumberOrNull(payload.user_period_days);
  const effectiveDays = userPeriodDays !== null ? userPeriodDays : (selected.api_code === 'non_defini' ? null : selected.periodHours / 24);
  const benignActivityMbq = toNumberOrNull(payload.benign_activity_mbq);
  const benignFixationPct = toNumberOrNull(payload.benign_fixation_pct);
  const doseRate = computeDoseRate(selected, benignActivityMbq, benignFixationPct, payload.dose_rate);
  const patientSizeCm = toNumberOrNull(payload.patient_size_cm);

  const user = {
    hours1: toNumberOrNull(payload.user_hours_1),
    distance1: toNumberOrNull(payload.user_distance_1),
    hours2: toNumberOrNull(payload.user_hours_2),
    limit: toNumberOrNull(payload.user_limit)
  };

  const userValues = [user.hours1, user.distance1, user.hours2, user.limit];
  const filledCount = userValues.filter((v) => v !== null).length;
  const userComplete = filledCount === userValues.length;
  const userEmpty = filledCount === 0;

  const errors = [];
  if (selected.api_code === 'iode131_benin') {
    if (!(benignActivityMbq > 0)) errors.push('Pour iode131_benin, benign_activity_mbq doit être strictement positif.');
    if (!(benignFixationPct > 0)) errors.push('Pour iode131_benin, benign_fixation_pct doit être strictement positif.');
    if ((benignActivityMbq > 0) && (benignFixationPct > 0) && !(doseRate > 0)) {
      errors.push('Le débit calculé automatiquement pour iode131_benin est invalide. Vérifiez benign_activity_mbq et benign_fixation_pct.');
    }
  } else {
    if (!(doseRate > 0)) errors.push('Pour cet isotope, dose_rate doit être strictement positif.');
  }
  if (!(patientSizeCm > 0)) errors.push('patient_size_cm doit être strictement positif.');
  if (selected.api_code === 'non_defini' && !(effectiveDays > 0)) errors.push('Avec isotope_code=non_defini, user_period_days devient obligatoire et doit être > 0.');
  if (effectiveDays !== null && !(effectiveDays > 0)) errors.push('La période effective retenue doit être strictement positive.');
  if (!userEmpty && !userComplete) errors.push('Pour calculer le scénario utilisateur, renseignez les 4 champs bleus du scénario personnalisé, ou laissez-les tous vides.');
  if (userComplete) {
    if (!(user.hours1 >= 0) || !(user.hours2 > 0)) errors.push('Les durées du scénario utilisateur doivent être valides et la durée n°2 doit être strictement positive.');
    if (!(user.distance1 > 0)) errors.push('La distance X du scénario utilisateur doit être strictement positive.');
    if (!(user.limit > 0)) errors.push('La limite dosimétrique du scénario utilisateur doit être strictement positive.');
  }

  const rows = scenarios.map((scenario) => ({
    label: scenario.label,
    condition: scenario.condition,
    value: errors.length ? null : restrictionDays(effectiveDays, doseRate, patientSizeCm, scenario.exposures, scenario.limit)
  }));

  let userRow = { label: 'Scénario utilisateur', condition: '-', value: null };
  if (userComplete) {
    userRow = {
      label: 'Scénario utilisateur',
      condition: `${user.hours1} h à ${user.distance1} m et ${user.hours2} h à 1 m,\nlimite ${user.limit} mSv`,
      value: errors.length ? null : restrictionDays(effectiveDays, doseRate, patientSizeCm, [[user.hours1, user.distance1], [user.hours2, 1]], user.limit)
    };
  }

  const expected_payload = expectedPayloadByIsotope(selected);

  if (errors.length) {
    return {
      ok: false,
      error: {
        code: 'VALIDATION_ERROR',
        message: `Échec du calcul pour isotope_code=${selected.api_code}.`,
        reason: selected.api_code === 'iode131_benin'
          ? 'Pour iode131_benin, les champs attendus diffèrent: benign_activity_mbq et benign_fixation_pct sont obligatoires (dose_rate est ignoré).'
          : selected.api_code === 'non_defini'
            ? 'Pour non_defini, dose_rate et user_period_days sont obligatoires et strictement positifs.'
            : 'Pour cet isotope, dose_rate est obligatoire et doit être strictement positif.',
        expected_payload
      },
      selected,
      computed_dose_rate: doseRate,
      effective_days: effectiveDays,
      effective_hours: effectiveDays === null ? null : effectiveDays * 24,
      errors,
      rows: [...rows, userRow]
    };
  }

  return {
    ok: true,
    selected,
    computed_dose_rate: doseRate,
    effective_days: effectiveDays,
    effective_hours: effectiveDays === null ? null : effectiveDays * 24,
    errors: [],
    rows: [...rows, userRow]
  };
}

function sendJson(res, statusCode, data) {
  const origin = corsOrigin === '*' ? '*' : corsOrigin;
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': origin,
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Allow-Methods': 'GET,POST,OPTIONS'
  });
  res.end(JSON.stringify(data));
}

const server = http.createServer((req, res) => {
  if (req.method === 'OPTIONS') return sendJson(res, 200, { ok: true });

  if (req.method === 'GET' && req.url === '/api/config') {
    return sendJson(res, 200, { isotopes, default_isotope_code: 'iode131_25_fixation' });
  }

  if (req.method === 'POST' && req.url === '/api/calculate') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; });
    req.on('end', () => {
      try {
        const payload = body ? JSON.parse(body) : {};
        sendJson(res, 200, calculate(payload));
      } catch (error) {
        sendJson(res, 400, { error: 'JSON invalide', details: error.message });
      }
    });
    return;
  }

  if (req.method === 'GET' && req.url === '/health') {
    return sendJson(res, 200, { ok: true, time: new Date().toISOString() });
  }

  sendJson(res, 404, { error: 'Not found' });
});

server.listen(port, () => {
  console.log(`Dosimetrie API listening on port ${port}`);
});
