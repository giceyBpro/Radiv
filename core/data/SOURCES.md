# Sources et choix des données du moteur

Les valeurs de `isotopes.json` (qui porte aussi les nombres de cures autorisés, champ `cures`) et `scenarios.json` proviennent des publications citées dans le champ
`reference` de chaque isotope, et du formalisme suivant :

- Carlier T, Denizot B, Prevot-Bitot N, Nioche C, Courbon F, Cachin F. *Harmonization of exposure constraints for
  relatives following targeted radionuclide therapy: a French perspective.* Médecine Nucléaire 2026;50:131-136.
  DOI : 10.1016/j.mednuc.2026.03.002 (scénarios, durées, distances, limites, demi-vies effectives du Tableau 1).
- Carlier et al., Radioprotection 2004;39:481-492, DOI : 10.1051/radiopro:2004012 (modèle de calcul d'origine).

## Origine des périodes effectives (champ `period_hours` / `period_days` de `isotopes.json`)

| Isotope | Période retenue | Origine |
|---|---|---|
| Iode 131 (fixation 0 %, 5 %, 25 %) | 16 h | Carlier 2004 : période effective de 16 ± 6 h (même valeur à l'abdomen et au cou) |
| Iode 131, indication bénigne | 122,4 h (5,1 jours) | Carlier 2006 (*Nucl Med Commun* 27:559-66) : demi-vie effective moyenne de 5,1 ± 1,6 jours |
| Lipiodol-131I | 8,04 jours | Période physique de l'iode 131 (Giammarile 2011) |
| Microsphères 90Y | 64,05 h (2,67 jours) | Période physique de l'yttrium 90 (Giammarile 2011 : 2,67 jours) |
| Microsphères 166Ho | 26,81 h | Période physique de l'holmium 166 |
| Lutétium 177 (tumeurs neuroendocrines) | 100 h | Maximum des demi-vies effectives observées (52 traitements, moyenne 55 h) : Fitschen 2011, qui recommande ce maximum plutôt que la période physique ; Levart 2019 (composante lente 87,2 ± 14,7 h) |
| MIBG-131I | 10,6 h | Wafelman 1995 : t½ d'élimination des enfants (10,6 ± 1,8 h, n = 6) ; adultes 12,4 ± 1,3 h |
| PSMA-177Lu | 40 h | Kratochwil 2019 (tableau 2) : 30 ± 10 h et 40 ± 16 h, valeur la plus longue retenue |
| Radium-223 | 11,43 jours | Période physique |
| Synovectomie 90Y, 186Re, 169Er | 2,67 jours, 3,7 jours, 9,4 jours | Périodes physiques (Clunie 2003 : 2,7 jours, 3,7 jours, 9,4 jours ; le moteur garde la valeur plus précise pour l'yttrium 90) |

Le facteur géométrique (source linéaire) est celui de Siegel et al., *J Nucl Med* 2002;43:354-63. Le modèle de 2004 (Carlier) arrondit les
durées à l'entier supérieur et décrit d'autres scénarios (durées, distances et limites) que ceux de 2026 repris par ce moteur.

## Choix de données

- **MIBG-131I** : 10,6 h (t½,elim des enfants, n = 6, Wafelman et al., Nucl. Med. Commun. 1995;16:767-772).

## Points de vigilance connus

- `unit_factor_at_1m` vaut `true` pour toute exposition à 1 m : le facteur géométrique de référence est 1 à cette distance
  (un `false` appliquait par erreur ~0,86 pour 150 cm et décalait le résultat de 2 jours).
- **Cures multiples** : la limite de chaque scénario est divisée par le nombre de cures, sauf `transport_commun` (chaque
  cure expose d'autres passagers, aucun cumul).
- **Arrondi** : `round` (au plus proche) ou `floor` (troncature, reproduit la Fig. 1 de l'article de référence). Voir `src/rounding.php`.
