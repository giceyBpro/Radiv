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

## Workflow Git obligatoire

À la fin de chaque tâche, toujours enchaîner dans cet ordre :
1. `git add <fichiers modifiés>` (cibler les fichiers, pas `git add -A`)
2. `git commit -m "..."` sur la branche de travail (`claude/...`)
3. `git push -u origin <branche>`
4. `git checkout main && git merge --no-ff <branche> -m "Merge <branche> into main" && git push origin main`
5. Revenir sur la branche de travail : `git checkout <branche>`

Ne jamais laisser des changements commités uniquement sur la branche de travail sans merger main.
