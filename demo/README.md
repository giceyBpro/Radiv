# Application simple (demo/) : API et page de saisie au-dessus du moteur

Ce dossier contient une **petite application complète** qui utilise le moteur de `core/` : une API HTTP, une page de saisie en un seul
fichier HTML, et un installeur autonome pour la déployer sur un hébergement web. Elle n'est **pas** dans `core/` : le moteur reste
seulement ce qui est utile à tous (calcul, isotopes, champs de saisie, scénarios).

Ce dossier n'est pas publié avec le moteur : il appartient au dépôt de RadIV.

## Essayer en 1 minute (sur votre poste)

Prérequis : **PHP 8.0 ou plus**.

```bash
php -S 127.0.0.1:8080 demo/api/index.php
```

Ouvrez http://127.0.0.1:8080/ : la page de saisie s'affiche. Ou en ligne de commande :

```bash
curl http://127.0.0.1:8080/config
curl -X POST http://127.0.0.1:8080/calculate -d '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":170}'
```

## Contenu

| Fichier | Rôle |
|---|---|
| `api/handler.php` | Interface d'API minimale (`GET config`, `POST calculate`), sans état ni dépendance autre que le moteur. Utilisable seule : `[$statut, $donnees] = RivEngine\Api\handle($methode, $route, $corps)`. |
| `api/index.php` | Exemple autonome : sert l'API et la page. En production, placez-le derrière votre propre routage et ajoutez vos protections (limitation de débit...). |
| `web/index.html` | Page de saisie en **un seul fichier HTML** (structure, présentation et logique ; textes dans `config.js`). Les champs affichés suivent `isotope_inputs` et `fields` du moteur et le tableau affiche toutes les lignes reçues : un isotope, un scénario ou un champ ajouté dans `core/data/` apparaît sans modifier la page. |
| `web/config.js` | **Titre, textes et libellés de la page** : le seul fichier à modifier pour personnaliser l'affichage. |
| `install-core.php` | Installeur autonome (page + API + moteur) : un seul fichier à envoyer par FTP, clé à définir en tête, se supprime après succès, conserve `config.js` et `data/settings.json` aux mises à jour. À épingler sur une étiquette de version. |
| `tests/test-api.php` | Contrôles de l'API sans serveur web : `php demo/tests/test-api.php`. |

Autres tests de cette application, dans `tests/` à la racine du dépôt : `test-minisite.js` (la page dans un vrai navigateur, avec un
isotope, un scénario et un champ ajoutés dans le moteur) et `test-radiv-fields.js` (la page d'accueil de RadIV avec une nouvelle règle de calcul).

## Déployer sur un hébergement web

1. Ouvrez **`install-core.php`** et remplacez `CHANGEZ-MOI` (`INSTALL_KEY`) par une clé secrète de 24 caractères minimum
   (`openssl rand -hex 24`). Vérifiez `REPO` et `REF` (de préférence une **étiquette** de version, pas `main`) ; `GITHUB_TOKEN`
   seulement si le dépôt est privé (c'est le cas du dépôt de RadIV).
2. Envoyez ce **seul fichier** par FTP dans le dossier web, puis ouvrez `https://<votre-domaine>/install-core.php`.
3. Saisissez la clé et le nom du sous-dossier (`riv` par défaut) : le script installe le moteur (`core/`), l'API et la page
   (`demo/`), vérifie que le moteur calcule, puis **se supprime**. L'application est alors à `https://<votre-domaine>/riv/`.
4. Personnalisez titre et textes dans **`riv/config.js`** (seul fichier à modifier pour l'affichage).

Mise à jour : relancez la même procédure ; `config.js` et `data/settings.json` sont conservés, l'ancien moteur est rétabli
automatiquement en cas d'échec.

## Dans RadIV

Le site RadIV n'utilise pas cette application telle quelle, mais son administration affiche la page `web/` dans l'onglet **Mini-site**
(`/auth/minisite`) pour essayer le moteur sans la surcouche de RadIV. Les mises à jour du site n'installent donc de `demo/` que
`web/index.html` et `web/config.js`, dans `backend/demo/web/`.
