// SPDX-License-Identifier: MPL-2.0
// Copyright (c) 2026 Projet RadIV et contributeurs
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
    periodDays: 'Période effective (jours) — facultative',
    periodDaysRequired: 'Période effective (jours) — obligatoire pour cet isotope',
    customTitle: 'Scénario personnalisé (facultatif : renseigner les 4 champs ou aucun)',
    customHours1: 'Durée n°1 (h)',
    customDistance1: 'Distance n°1 (m)',
    customHours2: 'Durée n°2 à 1 m (h)',
    customLimit: 'Limite de dose (mSv)',
    calculate: 'Calculer',
    colSituation: 'Situation',
    colCondition: 'Hypothèses',
    colDays: 'Restriction (jours)'
  },
  messages: {
    loading: 'Chargement…',
    apiError: "Le service de calcul n'est pas joignable.",
    noRestriction: '0',
    notComputed: '—'
  }
};
