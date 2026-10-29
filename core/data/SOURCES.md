# Sources et choix des données du moteur

Les valeurs de `isotopes.json` (qui porte aussi les nombres de cures autorisés, champ `cures`) et `scenarios.json` proviennent des publications citées dans le champ
`reference` de chaque isotope, et du formalisme suivant :

- Carlier T, Denizot B, Prevot-Bitot N, Nioche C, Courbon F, Cachin F. *Harmonization of exposure constraints for
  relatives following targeted radionuclide therapy: a French perspective.* Médecine Nucléaire 2026;50:131-136.
  DOI : 10.1016/j.mednuc.2026.03.002 (scénarios, durées, distances, limites, demi-vies effectives du Tableau 1).
- Carlier et al., Radioprotection 2004;39:481-492, DOI : 10.1051/radiopro:2004012 (modèle de calcul d'origine).

## Choix de données

- **MIBG-131I** : 10,6 h (t½,elim des enfants, n = 6, Wafelman et al., Nucl. Med. Commun. 1995;16:767-772).

## Points de vigilance connus

- `unit_factor_at_1m` vaut `true` pour toute exposition à 1 m : le facteur géométrique de référence est 1 à cette distance
  (un `false` appliquait par erreur ~0,86 pour 150 cm et décalait le résultat de 2 jours).
- **Cures multiples** : la limite de chaque scénario est divisée par le nombre de cures, sauf `transport_commun` (chaque
  cure expose d'autres passagers, aucun cumul).
- **Arrondi** : `round` (au plus proche) ou `floor` (troncature, reproduit la Fig. 1 de l'article de référence). Voir `src/rounding.php`.
