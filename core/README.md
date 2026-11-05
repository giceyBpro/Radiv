# Moteur de calcul RIV (durées de restriction après radiothérapie interne vectorisée)

Moteur **autonome** (PHP ≥ 8.0, sans dépendance) qui calcule les durées de restriction de contact pour l'entourage d'un
patient traité par radiothérapie interne vectorisée. Il est utilisé par le site RadIV et conçu pour être repris tel quel par un
autre site ou un autre logiciel. Ce dossier ne contient **que le moteur** : le calcul, et les données (isotopes, champs de
saisie, scénarios) qui le décrivent. L'API HTTP et la page de saisie en sont séparées.

## Démarrage rapide

Prérequis : **PHP 8.0 ou plus** (rien d'autre à installer).

### Utiliser le moteur dans votre propre code PHP (site, Joomla, WordPress, script…)

1. Copiez dans votre projet les dossiers **`src/`** et **`data/`** et le fichier **`VERSION`** (gardez-les côte à côte).
2. Chargez le moteur et calculez :

```php
require '/chemin/vers/core/src/bootstrap.php';

$r = RivEngine\calculate([
    'isotope_code'    => 'radium223',   // voir la liste : RivEngine\isotopes()
    'dose_rate'       => 100,           // débit de dose mesuré à 1 m (µSv/h)
    'patient_size_cm' => 170,
]);

if ($r['ok']) {
    foreach ($r['rows'] as $ligne) {
        if ($ligne['value'] !== null) echo $ligne['label'], ' : ', $ligne['value'], " jours\n";   // null = scénario non demandé
    }
} else {
    print_r($r['errors']);              // messages de validation, en français
}
```

**Entrées** (toutes dans un tableau) : `isotope_code` et `patient_size_cm` toujours ; selon l'isotope, `dose_rate` **ou**
`benign_activity_mbq` + `benign_fixation_pct` (iode bénin), et `user_period_days` si la période est libre. Facultatifs :
`cure_count` (si l'isotope en accepte plusieurs) et un scénario personnalisé (`user_hours_1`, `user_distance_1`,
`user_hours_2`, `user_limit`, les 4 ensemble). **`RivEngine\isotope_inputs()`** indique, pour chaque isotope, quels champs
demander (`required` / `optional` / `ignored`) : construisez votre formulaire à partir de cette table.

**Sortie** : `ok` (vrai/faux), `rows` (une ligne par situation : `label`, `condition`, `value` en jours),
`recommendations_days` (mêmes valeurs, indexées par situation), `effective_days`, `selected` (isotope et sa référence
publiée), `errors`. Exemple pour le Radium-223 : `recommendations_days` = `{"conjoint_plus_60": 25, …, "collegues_travail": 38}`.

Réglage facultatif : `RivEngine\set_rounding_mode('floor')` (troncature) à la place de l'arrondi au plus proche (défaut,
modifiable aussi dans `data/settings.json`).

### Au quotidien

| Je veux… | Je fais… |
|---|---|
| Vérifier que tout fonctionne | `node core/tests/test-calcul.js` (doit afficher « 0 écart »), `php core/tests/test-profiles.php`, `php core/tests/test-fields.php` |
| Ajouter ou modifier un scénario d'exposition | `data/scenarios.json` (voir `data/SCENARIOS.md`) : une ligne de résultat de plus partout, sans code |
| Ajouter un isotope | Une entrée dans `data/isotopes.json` (période, référence publiée, `cures`, `profile` si besoin), un cas de test, `VERSION` +1 mineure — voir `AGENTS.md` |
| Connaître la version | `RivEngine\version()` ou le fichier `VERSION` |
| Savoir si une modification est compatible | `AGENTS.md` (règles) et section « Compatibilité » ci-dessous |

## Contenu

| Fichier | Rôle |
|---|---|
| `src/bootstrap.php` | Point d'entrée : `require` ce fichier suffit. |
| `src/rounding.php` | Mode d'arrondi (`round` ou `floor`), réglable par `RivEngine\set_rounding_mode()`. |
| `src/compat.php` | Conversions identiques à JavaScript (garantit des résultats stables pour les clients existants). |
| `src/catalog.php` | Lecture de `data/*.json` : isotopes, scénarios, cures. |
| `src/profiles.php` | **Le fichier à modifier pour ajouter une règle de calcul** : les règles propres à certains isotopes (iode bénin, période libre), choisies par le champ `profile` de `isotopes.json`, et un guide en 4 étapes (déclarer le champ, écrire le profil d'après l'exemple commenté, l'attribuer à des isotopes, tester). `isotope_inputs()` annonce aux interfaces les champs à saisir pour chaque isotope (`required` / `optional` / `ignored`). |
| `src/physics.php` | Formules (décroissance, géométrie, durée de restriction), commentées une à une. |
| `src/calculate.php` | `RivEngine\calculate(array $payload)` : lecture des entrées, erreurs de saisie, une ligne par scénario, assemblage du résultat. |
| `data/settings.json` | Réglage du moteur : mode d'arrondi par défaut (`rounding_mode`). |
| `data/fields.json` | **Les champs de saisie propres aux isotopes** (code, libellé, unité, type, bornes, aide) : annoncés par `RivEngine\input_fields()` (que l'API publie dans `GET config`), ils permettent aux sites de construire leur formulaire sans modification. Guide : `data/FIELDS.md`. |
| `data/scenarios.json` | **Les scénarios d'exposition** (conjoint, enfant, collègues, transports…) : en ajouter un crée automatiquement une ligne dans les résultats de tous les sites. Guide : `data/SCENARIOS.md`. |
| `data/*.json` | **Les données** (à modifier ici, pas dans le code) ; `data/SOURCES.md` : références et choix. |
| `tests/` | `node core/tests/test-calcul.js` : 381 cas figés, 2 modes d'arrondi ; `php core/tests/test-profiles.php` ; `php core/tests/test-scenarios.php` ; `php core/tests/test-fields.php`. |
| `AGENTS.md` | Instructions aux agents et contributeurs : périmètre, règles de rétro-compatibilité, versionnement, tests à lancer. |
| `VERSION` | Version du moteur. |

## Lire le moteur

Le code est court et chaque fichier se lit seul. Ordre conseillé : `data/` (ce que le moteur sait), puis `src/calculate.php`
(le déroulement d'un calcul, en quatre fonctions : `read_entries`, `entry_errors`, `scenario_rows`, `calculate`), puis
`src/physics.php` (les formules) et `src/profiles.php` (ce qui change d'un isotope à l'autre). `src/compat.php` et
`src/rounding.php` règlent des détails de reproduction à l'identique de JavaScript. Les commentaires expliquent le **pourquoi** de chaque règle, pas seulement son effet.

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
- Licence : MPL-2.0 (en-tête SPDX dans chaque fichier ; le texte complet est dans `LICENSE`, à la racine du dépôt public).

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
