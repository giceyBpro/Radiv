# Instructions pour Claude

Répondre en français.

## Façon de travailler (plusieurs changements en parallèle : éviter les conflits Git)

- Modifier uniquement les fichiers strictement nécessaires ; diff minimal et atomique ; aucun refactor hors sujet ; aucun formatage global ;
  aucun renommage ou déplacement sauf nécessité absolue ; éviter les fichiers récemment touchés si possible.
- Resynchroniser avec la branche de base avant d'écrire ; si un conflit est probable, s'arrêter et proposer un patch plus petit.
- Priorité absolue : minimiser le risque de conflit et garder la fusion triviale.

## Workflow Git obligatoire (à la fin de chaque tâche, dans cet ordre)

1. `git add <fichiers modifiés>` (cibler les fichiers, pas `git add -A`).
2. `git commit -m "..."` sur la branche de travail (celle désignée pour la session).
3. `git push -u origin <branche>`.
4. `git checkout main && git merge --no-ff <branche> -m "Merge <branche> into main" && git push origin main`.
5. Revenir sur la branche de travail : `git checkout <branche>`.

Ne jamais laisser des changements commités uniquement sur la branche de travail sans fusionner dans `main`. Ne pas créer de pull request sans demande explicite.

## Architecture (voir le README, section « Architecture du dépôt et du site »)

- `public/` → racine web ; seule partie PHP publique : la façade `public/api/index.php`.
- `backend/` → code (`src/`), ressources de l'administration (`assets/`), configuration (`config/`) et données (`data/`), privés.
- `install.php` (installation web, seule méthode d'installation), `update.php` (mise à jour de secours autonome), mises à jour courantes par `/auth`
  (`backend/src/lib/updater.php`). `tests/` : outillage de développement, jamais déployé. Il n'y a ni Node ni `deploy.sh`.

## Règle de maintenance : `update.php` suit `install.php`

`update.php` est **autonome** (il ne dépend d'aucun code du site installé, c'est son rôle de secours) : une partie de son code est donc **copiée
telle quelle** depuis `install.php`. À chaque modification d'`install.php`, de `backend/src/lib/updater.php` ou de la structure installée, **mettre
`update.php` à jour dans le même commit** quand c'est nécessaire :

1. **Fonctions communes** : toute modification d'une de ces fonctions d'`install.php` doit être reportée à l'identique dans `update.php`
   (liste dans `tests/check-shared-code.js`, tableau `shared`) : `h`, `page`, `starts_with`, `ends_with`, `has_text`, `rrmdir`, `make_dir`, `guard_dir`,
   `gh_headers`, `http_get`, `download_to`, `allowed`, `dest_of`, `extract_archive`, `lint_php`, `parse_env_file`, `normalize_path`, `first_valid_token`,
   `htaccess_blocks`, `backend_deny_block`, `copy_atomic`, `health_get`, `backend_url_path`. Ajouter à ce tableau toute nouvelle fonction partagée.
2. **Listes blanches** : `$FRONTEND_FILES`, `$REQUIRED_FILES` et la logique de `allowed()` doivent rester identiques dans `install.php`, `update.php` et
   `backend/src/lib/updater.php`. Un nouveau fichier à installer (page, dossier, ressource...) s'ajoute aux trois.
3. **Réparations** : tout nouvel élément généré par l'installation et hors liste blanche (fichier généré, bloc de `.htaccess`, dossier protégé, pointeur...)
   doit aussi être recréé, sans écraser l'existant, par la section « Réparations idempotentes » d'`update.php`.
4. **Format de l'état** : `version.json`, `manifest.json`, `backup/prev/` et `updates.jsonl` d'`update.php` doivent rester compatibles avec
   `updater.php` (retour arrière de `/auth` possible après un secours).
5. **Écrire en PHP 7.0** (`install.php` et `update.php` doivent afficher un message clair sur un PHP ancien : pas de types scalaires, de `??`, de `: void`...).

Après toute modification, lancer au minimum : `node tests/check-shared-code.js` (aucun réseau, aussi dans la CI) ; si `update.php` ou la structure change,
refaire les tests d'intégration (`tests/test-admin-site.js` pour le secours, `tests/test-install.js` pour l'installation : Apache + faux services, voir le README).

## Autres règles du projet

- Aucun secret en clair dans le dépôt ; le jeton GitHub n'est jamais conservé par `install.php`/`update.php` (sauf `UPDATE_GITHUB_TOKEN` écrit par l'utilisateur).
- Les fichiers PHP du backend commencent par le garde `RADIV_ENTRY` ; en ajouter un (nouveau fichier de `backend/src`) = recopier ce garde et
  `node tests/test-backend-guard.js` le vérifie.
- Le README documente tout changement de comportement visible (installation, configuration, sécurité, RGPD).
