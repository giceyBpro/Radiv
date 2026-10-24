// ===== RÉGLAGES ET TEXTES DE LA PAGE (le seul fichier à modifier pour personnaliser l'affichage) =====
// Aucun autre fichier de web/ ne contient de texte visible, hormis la structure de la page (index.html).
window.RIV_CONFIG = {
  // --- Titre et textes d'en-tête --------------------------------------------------------------------------------
  title: 'Durées de restriction après radiothérapie interne vectorisée',
  intro: "Renseignez les mesures du patient pour obtenir, pour chaque situation de la vie quotidienne, la durée pendant laquelle les contacts doivent être limités.",
  disclaimer: "Aide au calcul : ne remplace ni l'avis du médecin nucléaire ni celui de la personne compétente en radioprotection.",

  // --- Adresse de l'API du moteur (core/api) : « ../ » depuis un serveur lancé avec core/api/index.php ------------
  apiBase: '',                       // '' = même adresse que la page ; sinon par ex. 'https://exemple.fr/api'

  // --- Libellés des champs -----------------------------------------------------------------------------------------
  labels: {
    isotope: 'Radiopharmaceutique',
    cures: 'Nombre de cures planifiées',
    doseRate: 'Débit de dose mesuré à 1 m du patient à sa sortie de chambre (µSv/h)',
    patientSize: 'Taille du patient (cm)',
    benignActivity: 'Activité administrée (MBq)',
    benignFixation: 'Fixation thyroïdienne (%)',
    periodDays: 'Période effective (jours) — facultative, obligatoire pour « Non défini »',
    customTitle: 'Scénario personnalisé (facultatif : renseigner les 4 champs ou aucun)',
    customHours1: 'Durée n°1 (h)',
    customDistance1: 'Distance n°1 (m)',
    customHours2: 'Durée n°2 à 1 m (h)',
    customLimit: 'Limite de dose (mSv)',
    calculate: 'Calculer',
    colSituation: 'Situation',
    colCondition: 'Hypothèses',
    colDays: 'Restriction (jours)',
    printSheet: 'Fiche imprimable (soignant)',
    patientSheet: 'Consignes pour le patient'
  },
  messages: {
    loading: 'Chargement…',
    apiError: "Le service de calcul n'est pas joignable.",
    noRestriction: '0',
    notComputed: '—'
  },

  // --- Fiche imprimable à destination du soignant (print.html) -------------------------------------------------------
  printSheet: {
    title: 'Durées de restriction des contacts',
    dateLabel: 'Date',
    patientBox: 'Identité patient / étiquette :',
    inputsTitle: 'Données saisies',
    resultTitle: 'Résultat du calcul',
    effectivePeriod: 'Période effective appliquée au calcul',
    reference: 'Référence : Carlier et al., Médecine Nucléaire 2026;50:131-136 — https://doi.org/10.1016/j.mednuc.2026.03.002',
    colSituation: 'Situation',
    colDays: 'Durée de restriction (jours)',
    warning: "Attention : ce calculateur ne tient compte que de l'exposition externe aux rayonnements ionisants. Les risques de contamination (externe et interne) doivent être gérés conformément aux recommandations en vigueur.",
    footer: '',                      // par ex. « © 2026 Mon service »
    printButton: 'Imprimer',
    noData: 'Aucune donnée disponible : calculez d\'abord depuis la page principale.'
  },

  // --- Consignes à remettre au patient (patient.html) : phrases simples, sans calcul ----------------------------------
  patientSheet: {
    title: 'Consignes simples à suivre à la maison',
    lead: "Vous n'avez rien à calculer. Suivez simplement les durées ci-dessous. Si vous avez un doute, contactez votre équipe soignante.",
    treatmentTitle: 'Votre traitement',
    treatmentLabel: "Type d'exposition",
    rulesTitle: 'Durées à respecter',
    duration: (n) => `${n} jour${n > 1 ? 's' : ''}`,             // « 1 jour », « 38 jours »
    withDuration: (text, duration) => `${text} Pendant ${duration}.`,
    noRestriction: 'aucune restriction particulière.',            // durée calculée égale à 0 jour
    noRule: 'Aucune durée calculée automatiquement. Contactez votre équipe soignante pour obtenir des consignes personnalisées.',
    doubt: 'En cas de doute ou de symptôme inhabituel, contactez votre équipe soignante.',
    printButton: 'Imprimer',
    footer: '',
    noData: 'Aucune donnée disponible : calculez d\'abord depuis la page principale.',
    // Une consigne par situation (clé = audience_code du moteur). Modifiable librement.
    guidance: {
      conjoint_plus_60: 'Si votre conjoint(e) a plus de 60 ans : évitez les contacts très rapprochés et prolongés ; privilégiez une certaine distance et, si possible, dormez dans une chambre séparée.',
      conjoint_moins_60: 'Si votre conjoint(e) a moins de 60 ans : limitez les contacts collés pendant de longues périodes, les câlins prolongés et le sommeil côte à côte.',
      conjointe_enceinte: 'Si votre conjointe est enceinte : évitez les contacts rapprochés prolongés, ne dormez pas dans le même lit et gardez une distance raisonnable la plupart du temps.',
      transport_commun: 'Trajets : transports urbains courts possibles ; évitez les longs trajets en train ou en avion en proximité prolongée. Si un déplacement long est indispensable, augmentez la distance dès que possible.',
      enfant_moins_3_ans: 'Enfant de moins de 3 ans : évitez de le garder longtemps sur vous, les câlins prolongés et de rester collé plusieurs heures. Si possible, confiez les soins rapprochés prolongés à un autre adulte.',
      enfant_3_11_ans: 'Enfant de 3 à 11 ans : évitez les jeux très collés, les longs câlins, dormir à côté et les moments serrés longtemps (ex. film collé sur le canapé). Gardez une distance raisonnable la plupart du temps.',
      collegues_travail: "Au travail : évitez le bureau côte à côte toute la journée et les réunions très longues en proximité ; gardez un peu d'espace avec vos collègues.",
      scenario_utilisateur: 'Scénario personnalisé : suivez la durée calculée pour votre situation et gardez autant que possible une distance avec les contacts proches.'
    },
    genericGuidance: 'Respectez une limitation des contacts rapprochés prolongés et gardez une distance raisonnable.'
  }
};
