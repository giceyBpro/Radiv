# Instructions pour les agents (et les contributeurs) — moteur de calcul RIV

Ce dossier est un **moteur partagé** : plusieurs sites (le site radiv, d'autres sites éventuels, dont ceux construits par la SFMN) s'appuient dessus et en dépendent pour des décisions de radioprotection. La priorité absolue est de **ne rien casser chez eux** : un résultat qui change sans que personne l'ait voulu est un incident.

Répondre en français. Diff minimal et atomique ; aucun refactor ni formatage hors sujet.

## 1. Périmètre : ce que le moteur est (et n'est pas)

- Fait : données (`data/*.json`), formules, validation, assemblage du résultat (`src/*.php`), API minimale (`api/`), page minimale (`web/`), installeur du minisite (`install-core.php`), tests (`tests/`).
- **Ne contient jamais** : référence au reste d'un site (`backend/`, `public/`, espaces de noms `Radiv\…`), variable d'environnement d'un site, base de données, accès réseau, écriture de fichier, secret, mode de calcul alternatif, interrogation d'un service externe (aucune trace d'un « mode SFMN »), champ de réponse propre à un site (par exemple `calculation_mode`).
- Les réglages passent par des fonctions (`set_rounding_mode`) ou par `data/settings.json`, jamais par l'environnement.
- Les fichiers `src/*.php` ne s'exécutent pas seuls (garde `RIV_ENGINE`, défini par `bootstrap.php`). Tout nouveau fichier de `src/` : recopier ce garde, l'ajouter à la liste de `bootstrap.php`, écrire du PHP 8.0 compatible.
- Le moteur est analysé par la zone de test des sites avant adoption : aucun appel système, réseau, d'écriture de fichier, d'`eval`, d'inclusion dynamique ni de `unserialize` (liste dans `backend/src/lib/core_lab.php` du dépôt radiv, constante `FORBIDDEN_CALLS`).

## 2. Rétro-compatibilité : le contrat

Le contrat entre le moteur et les sites est constitué de :

1. **les fonctions publiques** utilisées par les sites : `calculate`, `isotopes`, `scenarios`, `cure_options_by_isotope`, `get_isotope`, `normalize_cure_count`, `to_number_or_null`, `js_number_to_string`, `js_json_stringify`, `set_rounding_mode`, `version`, `isotope_inputs` (même nom, même signature, même type de retour) ;
2. **les entrées acceptées** par `calculate()` (noms de champs, conversions, valeurs invalides rejetées avec les mêmes messages) ;
3. **la forme de la réponse** (clés, ordre, types) et **toutes les valeurs calculées** (durées en jours, périodes, textes de conditions, messages d'erreur) ;
4. **les codes** (`api_code` des isotopes, `audience_code` des scénarios) et les nombres de cures autorisés ;
5. **les fichiers de données** lus (`data/*.json`) et leur format.

Règles :

- **Interdit sans changement de version majeure** : supprimer ou renommer une fonction publique, un champ d'entrée ou de réponse, un code d'isotope ou de scénario ; changer le type d'un champ ; modifier une valeur calculée pour une entrée valide ; modifier le texte d'un message d'erreur ou d'une condition ; réordonner les clés de la réponse.
- **Autorisé en version mineure** : ajouter un isotope, un scénario, un champ de réponse optionnel, une fonction publique, une entrée facultative — sans changer le résultat des entrées existantes.
- **Correctif** : bug sans effet sur le contrat (performances, commentaire, documentation, test).
- Une correction scientifique qui change des résultats (donnée, formule, arrondi) est une **majeure**, avec sa justification dans `data/SOURCES.md` (référence, valeur avant/après, raison).
- Ne jamais « corriger » en silence une valeur du tableau d'une publication : toute divergence assumée par rapport à la source est documentée dans `data/SOURCES.md`.
- Déprécier avant de retirer : une fonction ou un champ à supprimer reste fonctionnel au moins une version majeure complète, signalé dans `data/SOURCES.md` et le README.

## 3. Versionnement

- `VERSION` contient `MAJEURE.MINEURE.CORRECTIF` (trois nombres, rien d'autre). Il est lu par `RivEngine\version()` et contrôlé par les sites.
- Toute modification de `src/` ou `data/` incrémente `VERSION` dans le même commit, selon la règle ci-dessus. Un contenu différent avec le même numéro est refusé par les relecteurs.
- Étiqueter chaque version livrée (`core-vX.Y.Z`) : les sites et l'installeur épinglent une étiquette, jamais la branche principale.

## 4. Tests : à lancer avant tout commit

```bash
node core/tests/test-calcul.js   # 381 cas figés, deux modes d'arrondi : doit afficher 0 écart
php core/tests/test-api.php      # interface d'API minimale
php core/tests/test-profiles.php # profils de calcul (isotopes ajoutés dans les données)
php core/tests/test-scenarios.php # scénarios déclarés dans les données (ajout, génération des conditions, contrôles)
```

- `core/tests/golden-calcul.json` **est** le contrat chiffré. Il ne se modifie que pour une évolution voulue de résultats (donc une majeure) : régénérer le fichier, relire **chaque** écart, l'expliquer dans `data/SOURCES.md` et dans le message de commit.
- Ajouter un cas figé pour tout nouveau comportement (nouvel isotope, nouveau scénario, nouvelle erreur), en plus du code.
- Vérifier aussi les tests côté site si le dépôt radiv est présent : `node tests/test-core-contract.js` (copie du contrat propre au site, volontairement distincte) et `node tests/test-core-lab.js` (zone de test des candidats).
- Si le dépôt radiv est présent : `NODE_PATH=$(npm root -g) node tests/test-minisite.js` vérifie la page minimale (`web/`) dans un navigateur (isotope et scénario ajoutés dans les données, sans modifier la page).
- Aucun test ne doit être désactivé, ignoré ou assoupli pour obtenir un résultat vert.

## 5. Données (`data/`)

- Les valeurs scientifiques vivent **uniquement** dans les JSON, jamais en dur dans le code.
- Chaque isotope porte sa référence publiée (`reference`) ; la source doit être une publication vérifiable (DOI ou référence complète), jamais un simple lien vers un site.
- Une période est donnée soit en `period_hours`, soit en `period_days` (multipliée par 24), soit `null` (« non défini »). Ne pas changer cette représentation : elle fixe les arrondis flottants des résultats historiques.
- Les scénarios d'exposition se déclarent **uniquement** dans `data/scenarios.json` (guide : `data/SCENARIOS.md`). Ajouter un scénario = version **mineure** (une ligne de plus dans `rows` et `recommendations_days`, lignes existantes inchangées ; régénérer les cas figés en vérifiant que seules des lignes sont ajoutées) ; modifier la limite, les durées, les distances ou le partage entre cures d'un scénario existant change des résultats = version **majeure**. Ne jamais renommer un `audience_code` publié. La règle « limite non divisée par les cures » est le champ `shares_limit_across_cures`, jamais un test sur un code dans le PHP.
- Les nombres de cures d'un isotope se déclarent **dans son entrée de `isotopes.json`** (champ facultatif `cures`, ex. `[1, 4]` ; absent = une seule cure), pas dans un fichier à part.
- Une règle de calcul propre à un isotope se choisit par le champ facultatif `profile` de son entrée (`standard` par défaut, `benign_iodine`, `free_period`). Un isotope qui suit une règle existante s'ajoute **sans code** ; une règle nouvelle exige un profil dans `src/profiles.php` (évolution du moteur : contrat, version, cas figés). Le profil n'est jamais émis dans la réponse.
- Les JSON restent valides, UTF-8, sans commentaires ; `settings.json` ne contient que des réglages du moteur.

## 6. Déploiement

Les sites n'installent de ce dossier que `src/*.php`, `data/*.json` et `VERSION` (liste blanche des outils de mise à jour). Tout autre fichier ajouté ici (`api/`, `web/`, `tests/`, `install-core.php`, documentation) n'est jamais déployé sur un site. Ne pas ajouter d'autre exigence de déploiement sans l'accord des mainteneurs des sites.

## 7. Avant de proposer une modification

- [ ] Le périmètre du §1 est respecté (rien de propre à un site, aucun appel interdit).
- [ ] Le contrat du §2 est intact, ou la version majeure est incrémentée et justifiée.
- [ ] `VERSION` est à jour.
- [ ] `node core/tests/test-calcul.js` : 0 écart ; `php core/tests/test-api.php` : réussi.
- [ ] `data/SOURCES.md` et `README.md` reflètent le changement.
- [ ] Le message de commit dit ce qui change **pour les sites** (résultats, champs, version).

En cas de doute sur la compatibilité, ne pas trancher seul : décrire le risque et proposer une version majeure.
