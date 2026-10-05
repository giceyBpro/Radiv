// ===== MODULE DE CALCUL LOCAL — AUCUNE DÉPENDANCE AU RESTE DU SERVEUR =====
// Ce fichier contient l'intégralité du calcul des durées de restriction (mode "local"):
// données sources, formalisme mathématique, validation et orchestration. Il ne dépend
// d'aucun module Node (pas de fs/http/crypto/net), d'aucun secret, d'aucun autre fichier
// du projet — il peut être lu, audité ou testé isolément en dehors de tout contexte serveur.
// Seule dépendance externe: la variable d'environnement RESTRICTION_ROUNDING_MODE (voir
// plus bas), qui ne contient jamais de valeur sensible.
//
// Formalisme général, scénarios (durées/distances/limites) et tableau des demi-vies
// effectives harmonisés au niveau national par le groupe Radioprotection de la SFMN:
// Carlier T, Denizot B, Prevot-Bitot N, Nioche C, Courbon F, Cachin F. Harmonization of
// exposure constraints for relatives following targeted radionuclide therapy: a French
// perspective. Médecine Nucléaire 2026;50:131-136. DOI: 10.1016/j.mednuc.2026.03.002
// (reprend et prolonge le modèle de calcul de Carlier et al., Radioprotection 2004;39:481-92).
// Valeurs du Tableau 1 de cet article reprises telles quelles, SAUF mibg_131i: sa source
// primaire (Wafelman et al. 1995, vérifiée intégralement) contredit la valeur du Tableau 1 —
// voir le commentaire sur cette entrée ci-dessous.

// "round" (défaut): Math.round, légèrement plus protecteur (arrondit au jour supérieur dès
// que la fraction dépasse 0,5). "floor": troncature, reproduit exactement les valeurs de
// l'outil SFMN de référence (vérifié le 2026-09-02 sur les 7 scénarios, Radium-223).
const RESTRICTION_ROUNDING_MODE = (process.env.RESTRICTION_ROUNDING_MODE || 'round').trim().toLowerCase();
if (RESTRICTION_ROUNDING_MODE !== 'round' && RESTRICTION_ROUNDING_MODE !== 'floor') {
  console.warn(`RESTRICTION_ROUNDING_MODE invalide (${RESTRICTION_ROUNDING_MODE}); fallback sur "round"`);
}
const roundRestrictionDays = RESTRICTION_ROUNDING_MODE === 'floor' ? Math.floor : Math.round;

// 1) isotopes: périodes effectives, références et libellés métier utilisés dans le calcul
const isotopes = [
  { api_code: 'iode131_0_fixation', label: 'Iode-131-0%-fixation', periodHours: 16, reference: 'Carlier et al., Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', remark: '-', situation: 'Cancer opéré' },
  { api_code: 'iode131_5_fixation', label: 'Iode-131-5%-fixation', periodHours: 16, reference: 'Carlier et al., Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', remark: 'en considérant la période au niveau de la thyroïde', situation: 'Cancer oligo-métastasé' },
  { api_code: 'iode131_25_fixation', label: 'Iode-131-25%-fixation', periodHours: 16, reference: 'Carlier et al., Radioprotection 2004 Vol. 39, n° 4, pages 481 à 492 — DOI: 10.1051/radiopro:2004012', remark: 'en considérant la période au niveau de la thyroïde', situation: 'Cancer poly-métastasé' },
  { api_code: 'iode131_benin', label: 'Iode-131-Bénin', periodHours: 122.4, reference: 'Carlier et al., Nuclear Medicine Communications 2006, 27:559–566', remark: 'en considérant la période au niveau de la thyroïde', situation: 'Pathologie bénigne' },
  { api_code: 'radium223', label: 'Radium-223', periodHours: 11.43 * 24, reference: 'Période physique du radium-223', remark: 'Demi vie physique', situation: '' },
  { api_code: 'psma_177lu', label: 'PSMA-177Lu', periodHours: 40, reference: 'Kratochwil et al., EANM procedure guidelines for radionuclide therapy with 177Lu-labelled PSMA-ligands 2019', remark: 'Valeur la plus longue proposée', situation: '' },
  { api_code: 'synovectomie_90y', label: 'Synovectomie-90Y', periodHours: 2.67 * 24, reference: 'Clunie et al., EANM Procedure Guidelines for Radiosynovectomy 2003', remark: 'Demi vie physique', situation: '' },
  { api_code: 'synovectomie_186re', label: 'Synovectomie-186Re', periodHours: 3.7 * 24, reference: 'Clunie et al., EANM Procedure Guidelines for Radiosynovectomy 2003', remark: 'Demi vie physique', situation: '' },
  { api_code: 'synovectomie_169er', label: 'Synovectomie-169Er', periodHours: 9.4 * 24, reference: 'Clunie et al., EANM Procedure Guidelines for Radiosynovectomy 2003', remark: 'Demi vie physique', situation: '' },
  { api_code: 'microspheres_90y', label: 'Microsphères-90Y', periodHours: 64.05, reference: 'Période physique de l’yttrium-90', remark: 'Demi vie physique', situation: '' },
  { api_code: 'microspheres_166ho', label: 'Microsphères-166Ho', periodHours: 26.81, reference: 'Période physique de l’holmium-166', remark: 'Demi vie physique', situation: '' },
  { api_code: 'lipiodol_131i', label: 'Lipiodol-131I', periodHours: 8.04 * 24, reference: 'Giammarile et al., EANM procedure guideline for the treatment of liver cancer and liver metastases with intra-arterial radioactive compounds 2011', remark: 'Demi vie physique', situation: '' },
  { api_code: 'lutetium177_net', label: 'Lutétium-177 NET', periodHours: 100, reference: 'Fitschen et al, Z Med Phys 2011, Levart et al, EJNMMI Phys 2019', remark: 'Demi vie effective', situation: '' },
  // 10,6 h = t½,elim des enfants (n=6) dans Wafelman et al. 1995, valeur locale historique
  // restaurée. Le Tableau 1 de Carlier et al. 2026 cite la même source pour une valeur de
  // 30,60 h, qui n'apparaît nulle part dans le texte, les tableaux ou les résultats de cette
  // source primaire (plage complète des 11 patients avec t½,elim calculé: 9,1 à 14,3 h,
  // moyenne globale 11,5 h) — erreur identifiée dans le Tableau 1 de l'article 2026, à
  // signaler au groupe Radioprotection de la SFMN.
  { api_code: 'mibg_131i', label: 'MIBG-131I', periodHours: 10.6, reference: 'Wafelman et al., Nucl. Med. Commun. 16 (1995) 767–772 (t½,elim enfants, n=6)', remark: 'Le Tableau 1 de Carlier et al. 2026 indique 30,60 h pour cette même source — erreur, signalée à la SFMN', situation: '' },
  { api_code: 'non_defini', label: 'Non défini', periodHours: null, reference: '-', remark: '-', situation: '' }
];
// 2) scenarios: paramètres d'exposition ligne par ligne (heures, distance, facteur 1m spécifique)
// Vérifiés contre la Fig. 1 de Carlier et al. 2026 (voir ci-dessus): durées/distances/limites
// identiques pour les 6 scénarios lisibles sur la figure. Le scénario "collègues de travail" n'a
// pas pu être revérifié sur la figure (rendu de capture d'écran illisible pour cette ligne dans
// l'article) — valeur conservée du modèle 2004 (6 h à 1 m, limite 1 mSv), cohérente avec les
// 6 autres scénarios qui, eux, correspondent exactement.
const scenarios = [
  { audience_code: 'conjoint_plus_60', label: 'Contact avec le (la) conjoint(e) > 60 ans', exposures: [{ hours: 8, distance: 0.3, unit_factor_at_1m: false }, { hours: 3, distance: 1, unit_factor_at_1m: true }], limit: 15, condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 15 mSv' },
  { audience_code: 'conjoint_moins_60', label: 'Contact avec le (la) conjoint(e) < 60 ans', exposures: [{ hours: 8, distance: 0.3, unit_factor_at_1m: false }, { hours: 3, distance: 1, unit_factor_at_1m: true }], limit: 3, condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 3 mSv' },
  { audience_code: 'conjointe_enceinte', label: 'Contact avec la conjointe enceinte', exposures: [{ hours: 8, distance: 0.3, unit_factor_at_1m: false }, { hours: 3, distance: 1, unit_factor_at_1m: true }], limit: 1, condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 1 mSv' },
  { audience_code: 'transport_commun', label: 'Transport en commun', exposures: [{ hours: 3, distance: 0.5, unit_factor_at_1m: false }], limit: 1, condition: '3 h à 0,5 m,\nlimite 1 mSv' },
  { audience_code: 'enfant_moins_3_ans', label: 'Contact avec un enfant (<3 ans) au retour à la maison', exposures: [{ hours: 9, distance: 1, unit_factor_at_1m: true }], limit: 1, condition: '9 h à 1 m,\nlimite 1 mSv' },
  { audience_code: 'enfant_3_11_ans', label: 'Contact avec un enfant (entre 3 et 11 ans) au retour à la maison', exposures: [{ hours: 2, distance: 0.5, unit_factor_at_1m: false }, { hours: 2, distance: 1, unit_factor_at_1m: true }], limit: 1, condition: '2 h à 0,5 m et 2 h à 1 m,\nlimite 1 mSv' },
  // unit_factor_at_1m corrigé à true (2026-09-02): c'était la seule exposition à 1 m sur les
  // 6 du tableau à avoir false, ce qui appliquait par erreur le facteur géométrique complet
  // (~0,86 pour un patient de 150 cm) au lieu de la référence 1 à cette distance — écart de
  // 2 jours constaté par comparaison directe avec l'outil SFMN de référence (Radium-223,
  // 100 µSv/h, 150 cm: 38 j attendus, 36 j calculés avant ce correctif).
  { audience_code: 'collegues_travail', label: 'Contact avec des collègues de travail', exposures: [{ hours: 6, distance: 1, unit_factor_at_1m: true }], limit: 1, condition: '6 h à 1 m,\nlimite 1 mSv' }
];
const cureOptionsByIsotope = {
  radium223: [1],
  psma_177lu: [1, 4, 6],
  lutetium177_net: [1, 4]
};
const toNumberOrNull = (value) => {
  if (value === '' || value === null || value === undefined) return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
};
const getIsotope = (apiCode) => isotopes.find((i) => i.api_code === apiCode) || isotopes[0];
// 3) formules de calcul: décroissance, géométrie et calcul de durée de restriction
const decayFraction = (hours, effectiveDays) => 1 - Math.exp(-((Math.log(2) / effectiveDays) * (hours / 24)));
const geometryFactor = (distance, patientSizeCm) => Math.atan(patientSizeCm / (2 * distance * 100)) / (patientSizeCm * distance / 200);
const commonFactor = (effectiveDays) => ((effectiveDays * 24) / Math.log(2)) / (1 - Math.exp(-(Math.log(2) / effectiveDays)));
const exposureContribution = (exposure, effectiveDays, patientSizeCm) => {
  const geom = exposure.unit_factor_at_1m && exposure.distance === 1 ? 1 : geometryFactor(exposure.distance, patientSizeCm);
  return decayFraction(exposure.hours, effectiveDays) * geom;
};
const restrictionDays = (effectiveDays, doseRate, patientSizeCm, exposures, limit) => {
  const denominator = exposures
    .map((exposure) => exposureContribution(exposure, effectiveDays, patientSizeCm))
    .reduce((sum, value) => sum + value, 0) * commonFactor(effectiveDays);
  if (!(denominator > 0)) return null;
  const ratio = (limit * 1000) / denominator;
  const day = -(effectiveDays / Math.log(2)) * Math.log(ratio / doseRate);
  if (!Number.isFinite(day)) return null;
  return Math.max(0, roundRestrictionDays(day));
};
const computeDoseRate = (selected, benignActivityMbq, benignFixationPct, doseRate) => {
  if (selected.api_code !== 'iode131_benin') return toNumberOrNull(doseRate);
  if (benignActivityMbq === null || benignFixationPct === null) return null;
  return 2.2 * benignActivityMbq * (benignFixationPct / 100) / 37;
};
function normalizeCureCount(selected, rawValue) {
  const hasSpecificOptions = Object.prototype.hasOwnProperty.call(cureOptionsByIsotope, selected.api_code);
  const allowed = hasSpecificOptions ? cureOptionsByIsotope[selected.api_code] : [1];
  if (!hasSpecificOptions) return { value: 1, allowed, valid: true };
  const parsed = toNumberOrNull(rawValue);
  if (parsed === null) return { value: 1, allowed, valid: true };
  if (!Number.isInteger(parsed) || !allowed.includes(parsed)) {
    return { value: 1, allowed, valid: false };
  }
  return { value: parsed, allowed, valid: true };
}
function expectedPayloadByIsotope(selected) {
  const common = ['calculation_mode', 'isotope_code', 'patient_size_cm', 'user_period_days', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit', 'cure_count'];
  if (selected.api_code === 'iode131_benin') {
    return {
      common,
      isotope_specific_required: ['benign_activity_mbq', 'benign_fixation_pct (en %, ex. 15)'],
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
  // getIsotope() retombe silencieusement sur isotopes[0] si isotope_code ne correspond à
  // rien (faute de frappe, casse, valeur absente) — sans ce contrôle, le calcul se
  // poursuivrait normalement pour le mauvais isotope et renverrait ok:true avec un
  // résultat correct en apparence mais faux, sans aucun signal d'erreur.
  const isotopeCodeRecognized = isotopes.some((i) => i.api_code === payload.isotope_code);
  const cure = normalizeCureCount(selected, payload.cure_count);
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
  if (!isotopeCodeRecognized) {
    errors.push(`isotope_code inconnu ou manquant : ${JSON.stringify(payload.isotope_code ?? null)}. Codes valides : ${isotopes.map((i) => i.api_code).join(', ')}.`);
  }
  if (selected.api_code === 'iode131_benin') {
    if (!(benignActivityMbq > 0)) errors.push('Pour iode131_benin, benign_activity_mbq doit être strictement positif.');
    if (!(benignFixationPct > 0 && benignFixationPct <= 100)) errors.push('Pour iode131_benin, benign_fixation_pct doit être un pourcentage strictement positif et ≤ 100 (ex. 15).');
    if ((benignActivityMbq > 0) && (benignFixationPct > 0) && !(doseRate > 0)) errors.push('Le débit calculé automatiquement pour iode131_benin est invalide. Vérifiez benign_activity_mbq et benign_fixation_pct.');
  } else if (!(doseRate > 0)) {
    errors.push('Pour cet isotope, dose_rate doit être strictement positif.');
  }
  if (!(patientSizeCm > 0)) errors.push('patient_size_cm doit être strictement positif.');
  if (selected.api_code === 'non_defini' && !(effectiveDays > 0)) errors.push('Avec isotope_code=non_defini, user_period_days devient obligatoire et doit être > 0.');
  if (!cure.valid) errors.push(`Pour ${selected.api_code}, cure_count doit être l'une des valeurs suivantes : ${cure.allowed.join(', ')}.`);
  if (effectiveDays !== null && !(effectiveDays > 0)) errors.push('La période effective retenue doit être strictement positive.');
  if (!userEmpty && !userComplete) errors.push('Pour calculer le scénario utilisateur, renseignez les 4 champs bleus du scénario personnalisé, ou laissez-les tous vides.');
  if (userComplete) {
    if (!(user.hours1 >= 0) || !(user.hours2 > 0)) errors.push('Les durées du scénario utilisateur doivent être valides et la durée n°2 doit être strictement positive.');
    if (!(user.distance1 > 0)) errors.push('La distance X du scénario utilisateur doit être strictement positive.');
    if (!(user.limit > 0)) errors.push('La limite dosimétrique du scénario utilisateur doit être strictement positive.');
  }
  const rows = scenarios.map((scenario) => {
    // Cures multiples: la limite dosimétrique de chaque scénario est divisée par le nombre
    // de cures, SAUF transport_commun. Pour les autres scénarios (conjoint, enfant...),
    // c'est la même personne qui cumule l'exposition cure après cure, d'où la division.
    // Pour le transport en commun, chaque cure expose des personnes différentes (autres
    // passagers) : il n'y a pas de cumul à répartir, donc pas de division. Volontaire,
    // aligné sur SFMN (confirmé par le groupe Radioprotection SFMN).
    const isTransportCommun = scenario.audience_code === 'transport_commun';
    const limitForScenario = isTransportCommun ? scenario.limit : scenario.limit / cure.value;
    const conditionSuffix = cure.value > 1
      ? (isTransportCommun ? ' (par cure, non cumulée)' : ` (répartie sur ${cure.value} cures)`)
      : '';
    return {
      audience_code: scenario.audience_code,
      label: scenario.label,
      condition: scenario.condition + conditionSuffix,
      value: errors.length ? null : restrictionDays(effectiveDays, doseRate, patientSizeCm, scenario.exposures, limitForScenario)
    };
  });
  let userRow = { audience_code: 'scenario_utilisateur', label: 'Scénario utilisateur', condition: '-', value: null };
  if (userComplete) {
    userRow = {
      audience_code: 'scenario_utilisateur',
      label: 'Scénario utilisateur',
      condition: `${user.hours1} h à ${user.distance1} m et ${user.hours2} h à 1 m,\nlimite ${user.limit} mSv`,
      value: errors.length ? null : restrictionDays(effectiveDays, doseRate, patientSizeCm, [{ hours: user.hours1, distance: user.distance1, unit_factor_at_1m: false }, { hours: user.hours2, distance: 1, unit_factor_at_1m: true }], user.limit)
    };
  }
  const expected_payload = expectedPayloadByIsotope(selected);
  const allRows = [...rows, userRow];
  const recommendations_days = Object.fromEntries(allRows.map((row) => [row.audience_code, row.value]));
  if (errors.length) {
    return {
      ok: false,
      calculation_mode: 'local',
      error: {
        code: 'VALIDATION_ERROR',
        message: isotopeCodeRecognized
          ? `Échec du calcul pour isotope_code=${selected.api_code}.`
          : `isotope_code inconnu ou manquant : ${JSON.stringify(payload.isotope_code ?? null)}.`,
        reason: !isotopeCodeRecognized
          ? `Codes valides : ${isotopes.map((i) => i.api_code).join(', ')}.`
          : selected.api_code === 'iode131_benin'
            ? 'Pour iode131_benin, benign_activity_mbq et benign_fixation_pct (en %) sont obligatoires (dose_rate est ignoré).'
            : selected.api_code === 'non_defini'
              ? 'Pour non_defini, dose_rate et user_period_days sont obligatoires et strictement positifs.'
              : 'Pour cet isotope, dose_rate est obligatoire et doit être strictement positif.',
        expected_payload
      },
      selected,
      cure_count: cure.value,
      cure_count_allowed: cure.allowed,
      computed_dose_rate: doseRate,
      effective_days: effectiveDays,
      effective_hours: effectiveDays === null ? null : effectiveDays * 24,
      errors,
      recommendations_days,
      rows: allRows
    };
  }
  return {
    ok: true,
    calculation_mode: 'local',
    selected,
    cure_count: cure.value,
    cure_count_allowed: cure.allowed,
    computed_dose_rate: doseRate,
    effective_days: effectiveDays,
    effective_hours: effectiveDays === null ? null : effectiveDays * 24,
    errors: [],
    recommendations_days,
    rows: allRows
  };
}

module.exports = {
  isotopes,
  scenarios,
  cureOptionsByIsotope,
  toNumberOrNull,
  getIsotope,
  normalizeCureCount,
  calculate
};
