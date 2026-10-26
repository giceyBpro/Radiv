# Moteur de calcul RIV (durées de restriction après radiothérapie interne vectorisée)

Moteur **autonome** (PHP ≥ 8.0, sans dépendance) qui calcule les durées de restriction de contact pour l'entourage d'un
patient traité par radiothérapie interne vectorisée. Il est utilisé par le site du dépôt parent et conçu pour être repris
tel quel par un autre site.

## Contenu

| Fichier | Rôle |
|---|---|
| `src/bootstrap.php` | Point d'entrée : `require` ce fichier suffit. |
| `src/rounding.php` | Mode d'arrondi (`round` ou `floor`), réglable par `RivEngine\set_rounding_mode()`. |
| `src/compat.php` | Conversions identiques à JavaScript (garantit des résultats stables pour les clients existants). |
| `src/catalog.php` | Lecture de `data/*.json` : isotopes, scénarios, cures. |
| `src/profiles.php` | Règles propres à certains isotopes (iode bénin, période libre), choisies par le champ `profile` de `isotopes.json`. |
| `src/physics.php` | Formules (décroissance, géométrie, durée de restriction). |
| `src/calculate.php` | `RivEngine\calculate(array $payload)` : validation + résultat. |
| `data/settings.json` | Réglage du moteur : mode d'arrondi par défaut (`rounding_mode`). |
| `data/*.json` | **Les données** (à modifier ici, pas dans le code) ; `data/SOURCES.md` : références et choix. |
| `api/handler.php` | Interface d'API minimale (`GET config`, `POST calculate`) sans état ni dépendance. |
| `api/index.php` | Exemple autonome : `php -S 127.0.0.1:8080 core/api/index.php`. |
| `web/config.js` | **Titre, textes et libellés de la page** : le seul fichier à modifier pour personnaliser l'affichage. |
| `web/index.html`, `app.js`, `style.css` | Page minimale (saisie, tableau des résultats), Tout texte visible vient de `config.js`. Essai : `php -S 127.0.0.1:8080 core/api/index.php` puis http://127.0.0.1:8080/ |
| `install-core.php` | Installeur autonome du « minisite » (page + API + moteur) : un seul fichier à envoyer par FTP, clé à définir en tête, se supprime après succès, conserve `config.js` et `data/settings.json` aux mises à jour. À épingler sur une étiquette (`REF`). |
| `tests/` | `node core/tests/test-calcul.js` : 381 cas figés, 2 modes d'arrondi ; `php core/tests/test-api.php` ; `php core/tests/test-profiles.php`. |
| `AGENTS.md` | Instructions aux agents et contributeurs : périmètre, règles de rétro-compatibilité, versionnement, tests à lancer. |
| `VERSION` | Version du moteur. |

## Utilisation

```php
require 'core/src/bootstrap.php';
RivEngine\set_rounding_mode('floor');      // optionnel : surcharge le mode de data/settings.json ('round' ou 'floor')
$r = RivEngine\calculate(['isotope_code' => 'radium223', 'dose_rate' => 100, 'patient_size_cm' => 170]);
```

Le résultat contient `ok`, `rows` (une durée en jours par scénario) et `recommendations_days`. Voir `src/calculate.php`
pour les champs d'entrée et les messages d'erreur.

## Règles pour garder le moteur transférable

- Aucun appel au reste du dépôt (`backend/`, `public/`), aucune variable d'environnement lue directement : les réglages
  du moteur sont dans `data/settings.json`, la surcharge par l'hébergeur passe par `set_rounding_mode`.
- Les fichiers ne s'exécutent pas seuls (garde `RIV_ENGINE`), seul `bootstrap.php` les charge.
- Toute modification des formules ou des données doit garder `tests/test-calcul.js` vert ; une évolution voulue de résultat
  se fait en regénérant le fichier de référence et en l'expliquant dans `data/SOURCES.md`.
- Aucune licence n'est encore définie : à choisir avant publication.

Ce moteur est une aide au calcul ; il ne remplace ni l'avis du médecin nucléaire ni celui de la personne compétente en
radioprotection.

## Ce que le moteur ne contient pas

Aucun mode de calcul alternatif, aucune interrogation d'un service externe, aucun champ propre à un site : le moteur ne renvoie que ses propres champs.

## Compatibilité (contrat entre le moteur et les sites qui l'utilisent)

- **Version sémantique** dans `VERSION` : le numéro de *majeure* change dès qu'une entrée acceptée, une clé du résultat
  ou une valeur calculée change de façon incompatible. Mineure : ajout compatible. Correctif : sans effet sur les contrats.
- **Le contrat, ce sont les cas figés** (`tests/golden-calcul.json`) : toute modification du moteur doit les laisser
  inchangés, ou être déclarée comme évolution de majeure avec fichier de référence regénéré et justifié (`data/SOURCES.md`).
- `RivEngine\version()` renvoie le contenu de `VERSION` : un site peut vérifier la version qu'il utilise.
- **Un site consommateur épingle une version** (étiquette ou commit, jamais `main`) et ne la change qu'après avoir rejoué
  sa propre copie des cas figés.
- Les clés du résultat et des entrées ne sont jamais renommées ni supprimées sans changement de majeure.
