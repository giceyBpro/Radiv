# Moteur de calcul RIV (durées de restriction après radiothérapie interne vectorisée)

Moteur **autonome** (PHP ≥ 8.0, sans dépendance) qui calcule les durées de restriction de contact pour l'entourage d'un
patient traité par radiothérapie interne vectorisée. Il est utilisé par le site du dépôt parent et conçu pour être repris
tel quel par un autre site.

## Démarrage rapide

Trois façons de l'utiliser, de la plus simple à la plus complète. Prérequis : **PHP 8.0 ou plus** (rien d'autre à installer).

### A. Essayer en 1 minute (sur votre poste)

```bash
php -S 127.0.0.1:8080 core/api/index.php
```

Ouvrez http://127.0.0.1:8080/ : la page de saisie s'affiche. Ou en ligne de commande :

```bash
curl http://127.0.0.1:8080/config
curl -X POST http://127.0.0.1:8080/calculate -d '{"isotope_code":"radium223","dose_rate":100,"patient_size_cm":170}'
```

### B. L'utiliser dans votre propre code PHP (site, Joomla, WordPress, script…)

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

### C. Déployer le « minisite » (page + API) sur un hébergement web

1. Ouvrez **`install-core.php`** et remplacez `CHANGEZ-MOI` (`INSTALL_KEY`) par une clé secrète de 24 caractères minimum
   (`openssl rand -hex 24`). Vérifiez `REPO` et `REF` (de préférence une **étiquette** de version, pas `main`) ; `GITHUB_TOKEN`
   seulement si le dépôt est privé.
2. Envoyez ce **seul fichier** par FTP dans le dossier web, puis ouvrez `https://<votre-domaine>/install-core.php`.
3. Saisissez la clé et le nom du sous-dossier (`riv` par défaut) : le script installe, vérifie que le moteur calcule, puis
   **se supprime**. Le minisite est alors à `https://<votre-domaine>/riv/`.
4. Personnalisez titre et textes dans **`riv/config.js`** (seul fichier à modifier pour l'affichage).

Mise à jour : relancez la même procédure ; `config.js` et `data/settings.json` sont conservés, l'ancien moteur est rétabli
automatiquement en cas d'échec.

### Au quotidien

| Je veux… | Je fais… |
|---|---|
| Vérifier que tout fonctionne | `node core/tests/test-calcul.js` (doit afficher « 0 écart »), `php core/tests/test-api.php`, `php core/tests/test-profiles.php` |
| Ajouter un isotope | Une entrée dans `data/isotopes.json` (période, référence publiée, `cures`, `profile` si besoin), un cas de test, `VERSION` +1 mineure — voir `AGENTS.md` |
| Changer un titre, un libellé de la page | `web/config.js` (ou `riv/config.js` sur le site) |
| Connaître la version | `RivEngine\version()` ou le fichier `VERSION` |
| Savoir si une modification est compatible | `AGENTS.md` (règles) et section « Compatibilité » ci-dessous |

## Contenu

| Fichier | Rôle |
|---|---|
| `src/bootstrap.php` | Point d'entrée : `require` ce fichier suffit. |
| `src/rounding.php` | Mode d'arrondi (`round` ou `floor`), réglable par `RivEngine\set_rounding_mode()`. |
| `src/compat.php` | Conversions identiques à JavaScript (garantit des résultats stables pour les clients existants). |
| `src/catalog.php` | Lecture de `data/*.json` : isotopes, scénarios, cures. |
| `src/profiles.php` | Règles propres à certains isotopes (iode bénin, période libre), choisies par le champ `profile` de `isotopes.json`. `isotope_inputs()` annonce aux interfaces les champs à saisir pour chaque isotope (`required` / `optional` / `ignored`). |
| `src/physics.php` | Formules (décroissance, géométrie, durée de restriction). |
| `src/calculate.php` | `RivEngine\calculate(array $payload)` : validation + résultat. |
| `data/settings.json` | Réglage du moteur : mode d'arrondi par défaut (`rounding_mode`). |
| `data/*.json` | **Les données** (à modifier ici, pas dans le code) ; `data/SOURCES.md` : références et choix. |
| `api/handler.php` | Interface d'API minimale (`GET config`, `POST calculate`) sans état ni dépendance. |
| `api/index.php` | Exemple autonome : `php -S 127.0.0.1:8080 core/api/index.php`. |
| `web/config.js` | **Titre, textes et libellés de la page** : le seul fichier à modifier pour personnaliser l'affichage. |
| `web/index.html`, `app.js`, `style.css` | Page minimale (saisie, tableau des résultats). Tout texte visible vient de `config.js`. Essai : `php -S 127.0.0.1:8080 core/api/index.php` puis http://127.0.0.1:8080/ |
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
