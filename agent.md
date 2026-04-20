Travaille comme dans un dépôt avec plusieurs changements en parallèle.
Objectif: éviter les conflits Git.

Contraintes:
- modifie uniquement les fichiers strictement nécessaires;
- diff minimal et atomique;
- aucun refactor hors sujet;
- aucun formatage global;
- aucun renommage ou déplacement sauf nécessité absolue;
- évite les fichiers récemment touchés si possible;
- resynchronise avec la branche de base avant d’écrire;
- si un conflit est probable, arrête-toi et propose un patch plus petit.

Priorité absolue: minimiser le risque de conflit et garder la PR triviale à merger.
