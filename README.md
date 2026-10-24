# dosimetrieRIV

Application web de calcul des durées de restriction de contact après Radiothérapie Interne Vectorisée (RIV), intégrant l'API RadIV / SFMN et des modèles Xplore téléchargeables.

## Architecture du dépôt et du site

Le dépôt sépare ce qui est **public** de ce qui est **privé** :

```
public/            → copié dans la racine web (front-end)
  index.html, pages, app.js, vendor/, downloads/, config.js (généré), robots.txt...
  api/index.php      façade publique : la SEULE partie PHP servie (≈ 25 lignes)
  api/.htaccess      réécrit /api/* vers index.php
  api/backend.php    (généré) emplacement du backend
core/              → copié dans backend/core/ (moteur de calcul partagé, privé ; voir core/README.md)
  src/ data/ VERSION  moteur (src/*.php), données (data/*.json), version ; api/ et tests/ ne sont jamais déployés
backend/           → copié dans le dossier du backend (privé)
  .htaccess          « Require all denied » (toujours, même hors de la racine web)
  src/               code PHP (app.php, lib/*.php)
  assets/            ressources de l'administration (Leaflet, mesures.js, test-sfmn.*), servies par PHP derrière la session
  config/            runtime.env (généré, 600) et local.env (réglages locaux, jamais écrasé)
  data/              logs/ (mesures), var/ (sessions, version installée, sauvegarde, compteurs)
tests/             outillage de développement (jamais déployé)
docs/references/   articles scientifiques de référence (PDF) : dans le dépôt, jamais déployés ni publiés (voir leur README)
install.php, update.php   installation web (seule méthode) et mise à jour de secours (jamais publiés par une mise à jour)
```

- **Où est le backend ?** Par défaut dans la racine web, sous `backend/`, protégé ; il peut être placé ailleurs (de préférence **hors** de la racine web) avec le champ « Dossier du backend » d'`install.php` (ou `BACKEND_DIR` dans le fichier de configuration qu'il lit). La façade `api/index.php` le trouve dans cet ordre : variable d'environnement `RADIV_BACKEND`, fichier `api/backend.php` (généré par l'installation), puis `<racine web>/backend`. Déplacer le backend plus tard = déplacer le dossier et changer une ligne de `api/backend.php`.
- **Où sont les données ?** Dans `backend/data/` par défaut ; `DATA_DIR` (dans `config/runtime.env`) les place ailleurs (chemin absolu ou relatif au backend).
- **Le navigateur ne connaît aucun dossier** : `config.js` ne contient que des adresses (site, API).
- **Protection** (le backend peut être dans la racine web, donc plusieurs couches indépendantes) : `backend/.htaccess` refuse tout (recréé s'il manque) ; une règle en tête du `.htaccess` racine interdit l'adresse du backend ; chaque fichier PHP du backend refuse de s'exécuter hors de l'API ; l'installation **vérifie en HTTP, avant d'y écrire des secrets**, que le backend est illisible et s'interrompt sinon (demandant un dossier hors de la racine web) ; droits `600` sur les secrets, `750` sur `config/` et `data/`.

## Pages et fichiers frontend (`public/`)

| Fichier | Description |
|---|---|
| `index.html` | Application principale : saisie dosimétrique, calcul des consignes, résultat interactif. |
| `print.html` | Version imprimable A4 du résultat (PDF, image, copie). |
| `explain.html` | Version explicative simplifiée destinée au patient. |
| `xplore.html` | Page de téléchargement des modèles Xplore RIS (questionnaire QUDEM + insertion automatique). |
| `contact.html` | Formulaire de contact protégé par reCAPTCHA, envoi email côté backend. |
| `mentions-legales.html` | Mentions légales (éditeur anonyme, hébergeur O2SWITCH), conditions générales d'utilisation (absence de garantie de justesse et de disponibilité) et protection des données (RGPD) : la section RGPD est générée depuis `GET /api/config` et `GET /api/public-config`, reste donc correcte sans édition manuelle si la configuration change. |
| `api-fonctionnement.html` | Documentation publique du contrat API. |
| `test-api.html` | Page de test manuel des endpoints API. |
| `config.js` | Configuration runtime (URLs API/site) générée par `install.php` (jamais écrasée par les mises à jour). |
| `vendor/` | Polices et bibliothèques d'impression hébergées sur le site (aucun CDN ; licences dans `vendor/LICENSES.txt`). |
| `downloads/` | Modèles Xplore à importer dans un RIS Xplore (voir `xplore.html`). |
| `robots.txt` | Autorise l'indexation de la page d'accueil uniquement (voir [Référencement](#référencement)). |

`install.php` ajoute dans `.htaccess` des raccourcis sans `.html` pour ces pages : `/legal`, `/contact`, `/print`, `/explain`, `/doc` (→ `api-fonctionnement.html`),
`/test-api`, `/xplore`. La consultation des mesures n'a plus de page statique : elle fait partie
de l'administration du site, derrière la connexion Google (voir [Administration](#administration-du-site-auth--connexion-google-mesures-mises-à-jour)).

`/tox` (et `/tox.html`, `/tox/<chemin>`) est **redirigé de façon permanente (301) vers `https://tox.radiv.fr/`**, le site `tox` étant devenu indépendant : `install.php` (et `update.php` pour un site déjà installé) place la règle en tête du `.htaccess`, avant toute ancienne règle `/tox`. Les pages `tox.html` et `.tox-complet.html` ne font plus partie du dépôt.

## Référencement

Deux pages sont indexables : l'accueil (`/`) et les mentions légales (`/legal`). Toutes les autres portent `<meta name="robots" content="noindex, nofollow">`
(aide patient, contact, impression, documentation de l'API, page de test, modèles Xplore, ancienne version `v1.html`) : cela bloque l'indexation même si un lien
externe pointait vers elles, et elles ne sont liées depuis aucune page publique.

- **Balises dépendant de l'adresse du site, rendues par le backend** : l'adresse du site n'étant connue qu'à l'installation, `index.html` et
  `mentions-legales.html` contiennent un repère `<!--SEO-HEAD-->` que `backend/src/lib/seo.php` remplace à la volée, d'après `SITE_PUBLIC_URL` : **adresse canonique
  absolue** (`/` et `/legal`), `og:url`, `og:site_name`, `og:image` / `twitter:image` (`/og-image.png`, 1200×630), **données structurées JSON-LD** (`WebSite`,
  `Organization`, `WebApplication` pour l'accueil avec `dateModified` = date de la version installée, `WebPage` pour les mentions légales) et **nom du site dans le
  `h1`** dès le HTML (sans attendre le JavaScript). Réponses avec `ETag`, `Cache-Control: public, max-age=300` et `304`. Si l'adresse du site n'est pas configurée
  ou si le fichier manque, la page est servie telle quelle (aucune erreur visible).
- **`sitemap.xml`** est servi par le backend (accueil et mentions légales, avec leur date de dernière modification) ; `install.php` en écrit aussi une version statique
  de repli.
- **`robots.txt`** : tout est autorisé sauf `/api/` (les deux adresses `/api/config` et `/api/public-config` restent lisibles pour que les robots puissent afficher la
  page) et `/downloads/` (modèles XML). L'adresse de l'administration n'y figure volontairement pas : le fichier est public. La ligne `Sitemap:` est ajoutée à
  l'installation et conservée par les mises à jour.
- **Vitesse** : le `.htaccess` active la compression (`mod_deflate`) et le cache navigateur (`mod_expires` : polices 1 an, images 1 mois, scripts et styles 1 heure). Les pages HTML sont revalidées à chaque visite (`Cache-Control: no-cache`, bloc `# Pages HTML toujours revalidées v1`), et `app.js` est appelé avec une version (`app.js?v=…`, empreinte de son contenu : `node tests/check-asset-version.js --fix` après toute modification) pour qu'une mise à jour s'applique sans attendre l'expiration du cache.
  Les polices et bibliothèques sont hébergées par le site (aucune requête vers un tiers). Une adresse `www` ↔ sans `www` redirige vers l'adresse officielle
  (301) quand `SITE_PUBLIC_URL` commence par `www.`.
- **Activation** : ces règles sont des blocs du `.htaccess` racine (`# /seo …`, `# Compression et cache v1`) ajoutés par `install.php`. Sur un site déjà installé,
  **lancer `update.php` une fois** (réparation idempotente) les ajoute ; sans eux, le site fonctionne comme avant (accueil statique, sans canonique absolue).

**Important** : ces mécanismes agissent sur l'indexation *future*. Ils ne retirent pas une page déjà indexée : en cas de doute, vérifiez la Google Search Console du
domaine et demandez une suppression manuelle si nécessaire.

Vérifié par `tests/test-install.js` (canonique, Open Graph, JSON-LD, ETag/304, plan du site, robots, `noindex`) et `tests/test-admin-site.js` (réparation par `update.php`).

## Backend

| Fichier | Description |
|---|---|
| `public/api/index.php` | Façade publique de l'API : trouve le backend et lui passe la requête. |
| `backend/src/app.php` | Application de l'API PHP : routage HTTP, sécurité. |
| `core/` | Moteur de calcul partagé (mode « local ») : données JSON, formules, validation, API minimale et tests. Sans dépendance au reste du site, destiné à être transféré tel quel dans un dépôt indépendant (open source) ; installé dans `backend/core/`. |
| `backend/src/lib/*.php` | Configuration, état partagé, HTTP, journal des mesures, géoIP, proxy SFMN, contact/SMTP, administration `/auth`, mises à jour. |
| `update.php` | Mise à jour de secours autonome et idempotente (voir [Mise à jour de secours](#mise-à-jour-de-secours-updatephp)). |
| `install.php` | Première installation sans SSH (voir [Première installation](#première-installation-sans-ssh-installphp-ex-hébergement-mutualisé-ovh)). |
| `.env.example` | Modèle de configuration pour le déploiement. |
| `formules.txt` | Documentation interne des formules et méthodes de calcul. |

## API

### `GET /api/config`

Retourne les radiopharmaceutiques disponibles et l'isotope par défaut.

### `POST /api/calculate`

Calcule les durées de restriction recommandées.

Payload JSON :

```json
{
  "isotope_code": "iode131_25_fixation",
  "dose_rate": 20,
  "patient_size_cm": 160,
  "user_period_days": null,
  "user_hours_1": null,
  "user_distance_1": null,
  "user_hours_2": null,
  "user_limit": null,
  "benign_activity_mbq": null,
  "benign_fixation_pct": null,
  "cure_count": 1,
  "provider": "MonRIS 2.1"
}
```

- Les champs absents sont traités comme `null`.
- Un ancien paramètre `calculation_mode` (quelle que soit sa valeur) est **ignoré sans erreur** : l'API ne propose qu'un calcul, et la réponse ne contient plus ce champ.
- Pour `iode131_benin` : `benign_activity_mbq` et `benign_fixation_pct` sont obligatoires ; `dose_rate` est ignoré.
- Pour `non_defini` : `user_period_days` est obligatoire (> 0).
- `provider` : optionnel, **origine de la requête** (nom du logiciel ou du service qui appelle l'API, ex. `MonRIS 2.1`). Réduit à 64 caractères (lettres, chiffres, espace et `. _ : / @ + ( ) -`), retiré du calcul, il est enregistré dans le journal des mesures (champ `provider`, absent si non déclaré), affiché dans la colonne « Origine » de l'onglet Mesures de `/auth` (recherchable) et exporté en CSV. Les requêtes du site envoient le nom du site (`radiv.fr`). C'est une simple déclaration de l'appelant, non vérifiée : à ne pas utiliser pour une décision de sécurité.
- `cure_count` : optionnel (défaut `1`). Pris en compte pour `radium223`, `psma_177lu`, `lutetium177_net`.

En mode `local`, l'arrondi des durées de restriction (en jours) dépend de `RESTRICTION_ROUNDING_MODE` :
`round` (défaut, arrondit au jour le plus proche — légèrement plus protecteur) ou `floor`
(troncature, reproduit exactement les valeurs de l'outil SFMN de référence — vérifié sur les
7 scénarios avec Radium-223, 100 µSv/h, 150 cm).

`SFMN_CALCULATOR_URL` est l'adresse de l'outil en ligne de la SFMN : proposée en lien (« accéder à l'outil de la SFMN ») sur l'accueil, les mentions légales, la version imprimable et la version patient, avec la mention d'indépendance du site ; elle est lue à chaud dans `config/runtime.env` (rendue côté serveur pour l'accueil et les mentions légales, par `/api/public-config` pour les autres pages ; le fichier HTML porte une adresse de repli). Elle sert aussi à la seule page de test de l'administration (voir plus bas) : l'interrogation de cet outil n'est **pas** accessible par l'API publique, qui ne calcule qu'avec le moteur (`core/`).

### Exemple de réponse

```json
{
  "ok": true,
  "selected": { "api_code": "iode131_25_fixation", "label": "Iode-131-25%-fixation" },
  "cure_count": 1,
  "cure_count_allowed": [1],
  "computed_dose_rate": 20,
  "effective_days": 0.66,
  "effective_hours": 16,
  "errors": [],
  "recommendations_days": {
    "conjoint_plus_60": 0,
    "conjoint_moins_60": 0,
    "conjointe_enceinte": 0,
    "transport_commun": 0,
    "enfant_moins_3_ans": 0,
    "enfant_3_11_ans": 0,
    "collegues_travail": 0,
    "scenario_utilisateur": null
  }
}
```

**Recommandation d'intégration** : identifier un scénario par son `audience_code` (ou sa clé dans `recommendations_days`), **jamais par le texte** de `label` ou de `condition` de `rows`. Ces textes sont destinés à l'affichage et peuvent être reformulés d'une version à l'autre sans changer le sens ni les valeurs ; les codes et les noms de champs, eux, sont stables.

En cas d'erreur : `ok: false` + `error.code`, `error.message`, `error.reason`, `error.expected_payload`.

## Formulaire de contact

- Page : `contact.html`
- `GET /api/public-config` : retourne `recaptcha_site_key`
- `POST /api/contact` : envoi email côté backend (destinataire non exposé au frontend)

Variables `.env` requises :

```
RECAPTCHA_SITE_KEY
RECAPTCHA_SECRET_KEY
SMTP_HOST
SMTP_PORT
SMTP_SECURE
SMTP_USER
SMTP_PASS
SMTP_FROM
CONTACT_DEST
```

## Journalisation des mesures

Chaque appel `POST /api/calculate` est loggé dans `backend/data/logs/measurements-AAAA-MM.jsonl` (un
fichier par mois) : `timestamp`, `ip`, `input`, `result`. Le découpage mensuel
et la lecture en flux évitent de bloquer le serveur le temps de charger un historique
qui grossit sans limite. Un ancien `measurements.jsonl` (avant ce découpage) est
scindé automatiquement en fichiers mensuels, puis archivé en `.migrated`.
Chaque fichier mensuel est en plus limité par `LOGS_MAX_BYTES` (défaut 50 Mo) : au-delà,
il est basculé en `.1` avant de reprendre à zéro.

`MEASUREMENT_LOGGING_LEVEL` contrôle ce qui est écrit (minimisation RGPD) : `none` (rien),
`user` (horodatage + IP + géolocalisation uniquement, pas les données du calcul), `full`
(défaut, comportement ci-dessus). `LOGS_RETENTION_MONTHS` purge automatiquement (au plus
une fois par jour, déclenchée par une requête) les fichiers mensuels plus vieux que N mois ; vide par
défaut = 12 mois ; `0` = rétention illimitée.

Ces deux réglages sont reflétés automatiquement sur `mentions-legales.html` (via
`GET /api/public-config`) : la section protection des données décrit toujours l'état réel
de la configuration, sans édition manuelle à chaque changement de `.env`.

**Consultation** : réservée à l'administration du site, derrière la connexion Google de la page
`/auth` (onglet « Mesures », voir [Administration](#administration-du-site-auth--connexion-google-mesures-mises-à-jour)).
Il n'existe aucune route d'API publique ni jeton pour lire les mesures.
`ADMIN_MEASUREMENTS_ENABLED=false` désactive entièrement cet onglet.

## Modèles Xplore RIS

Le dossier `downloads/` contient deux modèles prêts à importer dans Xplore :

| Fichier | Type | Description |
|---|---|---|
| `MN_Radiopro_APIRADIV_generique.xml` | QUDEM | Questionnaire de saisie dosimétrique avec bouton RadIV intégré. |
| `MN_Consignes-radioprotection_RadIV.xml` | INSER | Insertion automatique générant la fiche consignes patient. |

Voir `xplore.html` pour les instructions d'installation et les points d'adaptation par centre.

## Lancement local

Depuis la racine du dépôt (sert aussi les pages statiques) :

```bash
php -S 127.0.0.1:8081 -t public tests/dev-router.php
```

## Installation et mises à jour

- **Installation** : uniquement par la page web `install.php` (voir [Première installation](#première-installation-sans-ssh-installphp-ex-hébergement-mutualisé-ovh)), sans SSH ni `git`.
- **Mises à jour courantes** : depuis la page d'administration `/auth` (voir [Administration](#administration-du-site-auth--connexion-google-mesures-mises-à-jour)), avec sauvegarde, contrôle de fonctionnement et retour arrière.
- **Secours** : `update.php`, à copier dans la racine web si `/auth` est inutilisable (voir [Mise à jour de secours](#mise-à-jour-de-secours-updatephp)).

## Façade et backend PHP

Backend PHP : routes `/health`, `/api/config`, `/api/public-config`, `/api/calculate`, `/api/contact`.
Aucun `.php` n'apparaît dans les URLs : les appelants externes (notamment le questionnaire
RIS Xplore) utilisent `/api/...` tel quel.

PHP s'exécute par requête sous Apache : déployer = copier des fichiers, aucun process à
relancer ni à surveiller.

| Fichier | Rôle |
|---|---|
| `public/api/index.php` | Façade publique (la seule partie PHP servie) : localise le backend. |
| `public/api/.htaccess` | Réécrit tout `/api/*` vers la façade. |
| `backend/src/app.php` | Routage et sécurité de l'API. |
| `core/src/*.php`, `core/data/*.json` | Moteur de calcul (formules, validation, données). |
| `backend/src/lib/*.php` | Config, état partagé (rate-limit, cache géoIP), HTTP, journal des mesures, géoIP, SFMN, contact/SMTP, accès admin. |
| `tests/` | Outillage de développement (jamais déployé) : tests, faux services (Google, GitHub, SMTP), routeur de dev. |

### Prérequis hébergement

- **PHP ≥ 8.0** choisi pour le site dans cPanel (Sélecteur PHP), extensions `curl`, `mbstring`, `json`, `openssl` (activées par défaut sur o2switch).
- `APCu` est **optionnel** : s'il est présent il porte le rate-limit par IP et le cache géoIP ; sinon ils sont stockés dans `backend/data/var/` (fichiers JSON verrouillés). `RATE_LIMIT_BACKEND=auto|apcu|file` force le choix. Sous LiteSpeed/LSAPI la mémoire APCu n'est pas forcément partagée entre processus : en cas de doute, `RATE_LIMIT_BACKEND=file` est le choix sûr.
- Vérifier APCu : cPanel → Sélecteur PHP → Extensions, ou `php -m | grep apcu`.

### Configuration

Variables documentées dans `.env.example`. Le backend lit `config/runtime.env`
(généré par `install.php`) puis `config/local.env` (réglages purement locaux, jamais
écrasé) ; une variable déjà définie dans l'environnement du serveur l'emporte. Ces fichiers sont relus à
chaque requête : toute modification est immédiate. `RATE_LIMIT_BACKEND` choisit le stockage du rate-limit,
`DATA_DIR` l'emplacement des données.

### Première installation sans SSH (`install.php`, ex. hébergement mutualisé OVH)

Quand l'hébergement n'offre ni SSH ni `git` (cas de l'offre gratuite incluse avec un nom de domaine chez OVH), le site s'installe avec **un seul fichier** envoyé par FTP :

1. Générez une clé secrète (`openssl rand -hex 24`) et collez-la dans `install.php`, à la place de `CHANGEZ-MOI` (`const INSTALL_KEY`). Sans clé d'au moins 24 caractères, la page refuse tout.
2. Envoyez **uniquement** `install.php` à la racine web (dossier `www/` chez OVH) puis ouvrez `https://<votre-domaine>/install.php`. HTTPS est exigé (secrets saisis dans la page).
3. Saisissez la clé, la page **vérifie l'hébergement** (PHP ≥ 8.0, extensions cURL / zip / mbstring / OpenSSL, droits d'écriture, accès à GitHub, espace disque, `mod_rewrite` si détectable) et bloque l'installation si un point critique échoue.
4. Renseignez le formulaire : dépôt et version à installer (un **tag validé** de préférence), **jeton d'accès GitHub** (lecture seule, jamais enregistré), adresse du site, connexion Google (identifiant, secret, adresses autorisées), formulaire de contact (reCAPTCHA, SMTP), options (arrondi, journalisation, conservation, taille des journaux, mode SFMN). **Ou, plus simple : fournissez un fichier de configuration** (modèle : `.env.example`) (voir ci-dessous), la page ne demande alors plus que la version, le jeton et ce qui manque dans le fichier. Le champ **Dossier du backend** (vide = `backend` dans la racine web ; sinon relatif, absolu ou `~/…`, de préférence hors de la racine web) place le code, la configuration et les données.
5. La page télécharge l'archive du commit exact, la valide **avant la moindre écriture** (mêmes contrôles que les mises à jour : chemins, liens symboliques, tailles, liste blanche, syntaxe PHP, version capable de se mettre à jour), sauvegarde ce qui existait (ex. page d'attente de l'hébergeur), installe (`public/` dans la racine web, `backend/` dans le dossier du backend), génère `config.js`, `sitemap.xml`, `api/backend.php`, `config/runtime.env` (600), complète le `.htaccess` **sans toucher à ses règles existantes**, **vérifie en HTTP, avant d'y écrire des secrets, que le backend n'est pas lisible depuis le web** (sinon tout est annulé et la page demande un dossier hors de la racine web), puis contrôle `/api/config`, `/health` et `/auth`. **Au moindre échec pendant l'écriture, tout est remis dans l'état précédent.**
6. Elle se **supprime** (`install.php` n'est jamais publié par une mise à jour) et pose un verrou ; si la suppression automatique est impossible, supprimez-le par FTP (il est déjà inactif). Si un contrôle final échoue (réécriture d'URL absente...), le fichier est conservé et la page peut être relancée.

**Installer à partir d'un fichier de configuration (`.env` ou ancien `runtime.env`)** : `install.php` lit un fichier au même format que `.env.example`. Il le cherche, dans l'ordre : à l'emplacement de la constante `ENV_FILE` en tête de `install.php` (par défaut `../.env`, le dossier **parent** du site : chez OVH, au-dessus de `www/`, donc **non accessible depuis le web** ; chemin absolu ou `~/…` acceptés, `''` désactive cet emplacement), puis `.env` et `.runtime.env` **dans la racine du site**. Ce dernier cas permet de **réinstaller sur un site existant en copiant simplement son ancien `config/runtime.env` (ou `.runtime.env`) dans la racine** : il sert de `.env`, et il est supprimé à la fin de l'installation puisqu'il se trouvait dans le dossier public. Quand le fichier est trouvé, la page affiche la configuration lue (secrets masqués, jamais renvoyés au navigateur), puis ne demande que **la version** (préremplie par `GIT_BRANCH`), **le jeton** et, au besoin, **le dossier du backend**. **Ce qui manque ou est invalide dans le fichier est demandé à ce moment-là** (par groupe : site, connexion Google, reCAPTCHA, SMTP, options) ; les valeurs saisies remplacent seulement celles qui manquaient, un secret laissé vide reste celui du fichier, et le navigateur ne peut jamais modifier ce que le fichier renseigne correctement. Les variables reconnues : celles de `.env.example` (`SITE_PUBLIC_URL`, `GOOGLE_*`, `ADMIN_GOOGLE_EMAILS`, `ADMIN_UPDATE_ENABLED`, `SMTP_*`, `CONTACT_DEST`, `RECAPTCHA_*`, `SFMN_*`, `RESTRICTION_ROUNDING_MODE`, `MEASUREMENT_LOGGING_LEVEL`, `LOGS_*`, `CALCULATE_RATE_LIMIT`, `TRUSTED_PROXIES`, `SITE_NAME`, `COPYRIGHT_OWNER`, `DATA_DIR`, `BACKEND_DIR`...), et le dépôt vient de `UPDATE_GITHUB_REPO` ou de `GIT_REPO`. Le fichier lu n'est jamais modifié, sauf s'il se trouvait dans le dossier public (supprimé après l'installation).

Le jeton peut être saisi dans la page, ou mis dans ce `.env` pour ne plus rien taper : `UPDATE_GITHUB_TOKEN` (reporté dans `config/runtime.env`, 600, pour que `/auth` ne le redemande pas) ou `GIT_TOKEN` (utilisé pour l'installation seulement, non reporté ; `/auth` en demandera un). Un jeton du `.env` refusé par GitHub est ignoré et la page en redemande un. **Ne mettez jamais le jeton dans `install.php` lui-même** : ce fichier est servi par le site, et un échec de sa suppression le laisserait en ligne.

Ensuite : déclarez dans Google Cloud l'URI de redirection `https://<votre-domaine>/auth/callback`, connectez-vous sur `/auth` ; les mises à jour suivantes se font depuis cette page. Au plus 10 clés incorrectes par heure et par adresse. Écrit en PHP 7.0 pour afficher un message clair, plutôt qu'une erreur blanche, sur un hébergement resté en PHP ancien. Si l'extension `zip` ou les connexions sortantes manquent, la page l'indique : installez alors les fichiers par FTP (voir le dépôt : `public/` dans la racine web, `backend/` dans le dossier du backend).

Vérification : `tests/test-install.js` (140 contrôles sur un Apache vierge : clé absente/courte/fausse/limitée, lecture du `.env` (emplacements parent / public / `~/` / absolu, jetons valides ou périmés, `.env` invalide, secrets jamais renvoyés), diagnostic, 15 saisies invalides, jeton refusé, 6 archives piégées, retour arrière complet sur échec d'écriture, fichiers préexistants de l'hébergeur conservés ou restaurés, version qui ne démarre pas, installation réussie, jeton jamais écrit, listes blanches).

### Mise à jour de secours (`update.php`)

Pour mettre le site à jour ou le **réparer** quand `/auth` est inutilisable (connexion Google indisponible, code du site endommagé ou incomplet...) :
1. Remplacez la clé `UPDATE_KEY` en tête de `update.php` par une valeur secrète d'au moins 24 caractères.
2. Envoyez le fichier seul à la racine web (FTP) et ouvrez `https://<votre-domaine>/update.php` (HTTPS exigé).
3. Saisissez la clé et la version voulue (tag, branche ou commit, préremplie avec la version installée) ; un jeton d'accès n'est demandé que si celui de `config/runtime.env` manque ou n'est plus accepté par GitHub, et n'est jamais conservé.

**Autonome** : le script ne dépend d'aucun code du site installé ; il retrouve le backend comme la façade (variable `RADIV_BACKEND`, `api/backend.php`, `<racine>/backend`) et lit seulement `config/runtime.env` (dépôt, adresse du site, jeton éventuel). Il télécharge la version, la valide **avant toute écriture** (chemins, liens symboliques, tailles, liste blanche, syntaxe PHP), sauvegarde ce qu'il remplace dans le même format que `/auth` (le retour arrière manuel y reste possible), remplace les fichiers (backend d'abord, façade en dernier), **répare ce qui manque** (`.htaccess` du backend et des dossiers de données, `api/backend.php`, blocs du `.htaccess` racine, `config.js`, `sitemap.xml`, ligne `Sitemap` de `robots.txt` — sans jamais écraser l'existant), contrôle que l'API répond et **revient en arrière automatiquement** sinon. La configuration (`config/`) et les données (`data/`) ne sont jamais touchées.

**Migration de l'ancienne structure** : si le site est encore dans l'ancienne disposition (tout dans `api/` : `api/.runtime.env`, `api/lib/`, `api/logs/`, `api/var/` — l'ancienne page `/auth` refuse alors la nouvelle version avec « Version cible incomplète ou trop ancienne »), `update.php` la détecte, l'annonce, puis **déplace** (renomme, sans copie) `api/.runtime.env` → `backend/config/runtime.env`, `api/.env` → `backend/config/local.env`, `api/logs/` → `backend/data/logs/` et `api/var/` → `backend/data/var/` (configuration, journaux et sessions conservés), installe la nouvelle version, crée `api/backend.php`, complète le `.htaccess`, vérifie l'API puis supprime l'ancien code d'`api/` (`lib/`, `assets/`, `calculation.php`). Tout est validé avant le premier déplacement ; en cas d'échec ou si l'API ne répond pas, déplacements et fichiers sont **rétablis** (ancienne structure intacte). L'ancienne sauvegarde de `/auth` (autre format) n'est plus utilisable après une migration réussie.

**Redistribution des dossiers (`BACKEND_DIR`, `DATA_DIR`)** : `config/runtime.env` porte l'emplacement voulu du backend et des données. Pour déplacer le backend (par exemple hors de la racine web), modifiez la ligne `BACKEND_DIR="..."` de ce fichier (chemin absolu, `~/...` ou relatif à la racine du site ; non défini = emplacement actuel), puis lancez `update.php` : il **déplace** le dossier (renommage ou, entre deux volumes, copie vérifiée dont l'original n'est supprimé qu'après le succès de tout), met à jour `api/backend.php`, complète le `.htaccess`, installe la version et contrôle l'API. Il en va de même pour `DATA_DIR` (journaux, sessions, sauvegarde), mémorisé dans `config/.data-dir`. Le nouvel emplacement doit être inexistant ou vide et ne pas être imbriqué dans l'ancien ; rien n'est jamais supprimé, et en cas d'échec (ou d'API muette) tout est remis à sa place.

**Fichier `runtime.env` lisible** : s'il date d'un ancien format, `update.php` le réécrit une fois avec des sections, un commentaire par réglage et les valeurs conservées (réglages inconnus regroupés en fin de fichier ; droits 600 ; ancienne version gardée dans `config/runtime.env.bak`). `install.php` produit le même format.

**Idempotent** : le relancer avec la version déjà installée réinstalle les mêmes fichiers et redonne exactement le même état (c'est une réparation) ; la sauvegarde de la version précédente (retour arrière de `/auth`) est alors conservée. Après un succès le fichier **se supprime** ; en cas d'échec il est conservé pour un nouvel essai (supprimez-le par FTP si vous y renoncez). Au plus 10 clés incorrectes par heure et par adresse. Écrit en PHP 7.0 pour afficher un message clair sur un hébergement resté en PHP ancien. Ses fonctions communes avec `install.php` sont des copies identiques (vérifiées par `tests/check-shared-code.js`, de même que les listes blanches). Vérifié par `tests/test-admin-site.js` (migration, déplacement du backend et des données, remise en forme de `runtime.env`, clé, jeton, archives piégées, retour arrière automatique, idempotence, réparation d'un site endommagé, jeton de la configuration).

**Configuration lue** : la page `/auth` affiche une zone qui compare `config/runtime.env` à ce que chaque fonctionnalité exige (adresse du site, connexion Google, formulaire de contact, SFMN) : « complet », ou la liste des **champs manquants** avec la conséquence (ex. « envoi des messages refusé »). Un volet replié liste les réglages facultatifs non définis (valeur par défaut utilisée). Aucune valeur n'est affichée, seulement les noms.

#### Onglet « Fournisseurs » : clés d'API (obligatoires pour les intégrateurs)

Tout appel **extérieur** à `POST /api/calculate` (logiciel de RIS, script Xplore, service tiers...) doit présenter une **clé d'API** propre à son fournisseur (c'est ce que la documentation destinée aux intégrateurs annonce), dans l'en-tête `X-API-Key: rdv_xxxxxxxx_…` (ou `Authorization: Bearer …`), jamais dans l'adresse. Sans clé, **quand le serveur applique l'obligation** (voir « Application de l'obligation ») : `401` `API_KEY_REQUIRED` ; clé invalide, expirée, révoquée ou fournisseur suspendu : `401` `API_KEY_INVALID` (même message dans tous les cas, la raison précise n'est qu'inscrite au journal d'erreurs). `GET /api/config` et `/health` restent publics. Les **pages du site** (navigateur sur le site lui-même, en-tête `Origin` du site) en sont dispensées : un site web ne peut pas garder de secret. Cette dispense n'est donc pas une preuve d'identité (un script peut écrire cet en-tête) : elle laisse le site fonctionner, sous le plafond habituel par adresse IP.

- **Créer et gérer** : onglet **Fournisseurs** de `/auth` (connexion Google récente exigée, comme pour les mises à jour). Un fournisseur a un identifiant (minuscules, chiffres, `-`, `_`), un nom, un plafond de requêtes par minute (défaut `PROVIDER_RATE_LIMIT`, 300) et une ou plusieurs clés ; chaque clé peut avoir une durée de validité. **La clé n'est affichée qu'une fois**, à sa création : seule son empreinte SHA-256 est conservée (`config/providers.json`, droits 600, jamais écrasé par une mise à jour). Révoquer une clé ou suspendre un fournisseur est immédiat. Rotation : générer la nouvelle clé, la déployer, puis révoquer l'ancienne.
- **Identification** : les mesures portent `provider_id` (fournisseur identifié par sa clé, ou nom du site pour ses propres pages) et, à part, le `provider` que l'appelant déclare (nom et version de son logiciel) ; l'onglet Mesures les affiche dans « Origine » et l'export CSV a les colonnes `provider_id` et `provider`.
- **Protection** : comparaison à temps constant ; 20 clés refusées par minute et par adresse IP au plus (puis `429`) ; plafond propre à chaque fournisseur (`429`).
- **Application de l'obligation (désactivée pour le moment)** : la documentation (`api-fonctionnement.html`, `xplore.html`, script Xplore) présente la clé comme obligatoire, mais le serveur ne la fait respecter que lorsque l'administrateur l'active. Tant qu'elle n'est pas activée, les appels extérieurs **sans clé sont acceptés** (une clé valide reste reconnue et identifie son fournisseur ; une clé invalide est toujours refusée). L'activer ou la désactiver se fait par le bouton **« Rendre la clé obligatoire / facultative »** de l'onglet Fournisseurs (effet immédiat, sans fichier à modifier) ; à défaut de ce choix, la variable `API_KEY_REQUIRED` de `config/runtime.env` s'applique (`false` par défaut). Le choix de l'administration est enregistré dans `config/providers.json` et l'emporte sur la variable ; la zone « Configuration lue » de `/auth` indique l'état. Conseil : distribuer d'abord les clés, vérifier dans les mesures (colonne « Origine ») que chaque intégration est identifiée, puis activer l'obligation.
- **Script Xplore** : dans `downloads/MN_Radiopro_APIRADIV_generique.xml`, la clé se renseigne **tout en haut du script** du questionnaire, ligne `var RADIV_API_KEY = "";` (la page `/xplore.html` le rappelle) ; le script l'envoie dans `X-API-Key` et affiche un message explicite si elle est refusée. Ne pas diffuser une copie du modèle qui contient la clé.

Vérifié par `tests/test-admin-site.js` (création, clé affichée une fois, empreinte seule dans le registre, X-API-Key et Bearer, clé dans l'adresse ignorée, altérée, révoquée, expirée, rotation, suspension, plafond par fournisseur, limitation des essais, dispense du site, CORS, migration) et `tests/test-install.js`.

#### Onglet « Test SFMN »

`https://<votre-domaine>/auth/test-sfmn` permet d'essayer le calcul de l'outil SFMN en le comparant au moteur : c'est la **page d'accueil du site elle-même** (`index.html` + `app.js`, donc toujours à jour avec elle), servie avec une autre feuille de style (fond orangé, bandeau « TEST SFMN », `api/assets/test-sfmn.css`) pour ne pas la confondre avec le site. Le calcul SFMN y est proposé et choisi par défaut ; les appels passent par `/auth/test-sfmn/api/*` (réservés à une session d'administration, même origine exigée, 20 essais par minute), jamais par l'API publique. **Rien n'est enregistré dans les mesures.** Sous le tableau, deux volets repliés (`api/assets/test-sfmn.js`) : la **comparaison avec le calcul local** (écart par scénario) et les **détails techniques SFMN** (échanges avec le site distant, même si `SFMN_DEBUG=false`). La page n'ajoute aucun script tiers : la CSP n'autorise que les scripts du site. Vérifié par `test-admin-site.js` et `test-browser-admin.js`.

### Sécurité et conformité (RGPD)

- **Aucun service tiers au simple affichage d'une page** : polices (Archivo, Source Sans 3, IBM Plex Mono) et bibliothèques d'impression (html2canvas, jsPDF) sont hébergées dans `vendor/` (licences dans `vendor/LICENSES.txt`) ; Leaflet l'est dans `api/assets/`. Les empreintes SRI de l'ancienne version CDN ont été vérifiées identiques aux fichiers hébergés.
- **reCAPTCHA** (formulaire de contact uniquement) : le script de Google n'est chargé qu'après avoir coché une case d'accord explicite ; sans elle, l'envoi est bloqué. Côté serveur, le jeton est contrôlé (succès, action `contact_form`, nom de domaine, score ≥ `RECAPTCHA_MIN_SCORE`, 0,5 par défaut).
- **Taille des journaux** : une ligne ne garde que ce qui varie d'un calcul à l'autre : date, IP, lieu, **valeurs saisies** (`input`) et **résultat chiffré** (`result` : réussite, durées en jours par scénario `recommendations_days`, période effective, erreurs). Les textes identiques à chaque ligne (fiche du radiopharmaceutique `selected`, libellés et conditions des scénarios `rows`) et les données de diagnostic SFMN ne sont jamais écrits : environ 700 octets par ligne au lieu de 2 400 (≈ 20 Mo par mois à 1 000 calculs par jour au lieu de 68). Les anciennes lignes restent lisibles telles quelles ; l'export CSV de l'onglet Mesures a une colonne `recommendations_days` à la place de `rows`. De plus, l'adresse de `ip_geo` n'est pas réécrite quand elle est identique à `ip` (doublon exact) ; elle est rétablie à la lecture, donc l'onglet Mesures et l'export CSV voient les mêmes données, et les anciennes lignes restent lisibles.
- **Espace disque** : l'occupation du site (racine web, backend et données) est mesurée par rapport à `DISK_QUOTA_MB` (le quota de l'hébergement, ex. 100 ; vide = disque du serveur, peu utile en mutualisé) et affichée sur la page `/auth`. Quand l'espace **libre** (quota moins occupation) passe sous `DISK_MIN_FREE_MB` Mo (30 par défaut, `0` = jamais), les journaux de mesures **les plus anciens sont supprimés**, un par un (les rotations `.1` avant le fichier du mois), jusqu'à retrouver ce minimum plus 10 % (3 Mo pour 30) ; le fichier du mois en cours n'est jamais supprimé (seule sa rotation `.1` l'est en dernier recours). Sans cron : contrôle déclenché par les requêtes, au plus toutes les 10 minutes ; chaque purge est inscrite dans le journal (`updates.jsonl`, action `disk_purge`) et dans le journal d'erreurs PHP.
- **Conservation des journaux** : 12 mois par défaut (`LOGS_RETENTION_MONTHS`), `0` pour une conservation illimitée. La purge s'applique aussi aux journaux existants, au plus une fois par jour. Les mentions légales (`/legal`) décrivent automatiquement la configuration réelle ; services de géolocalisation d'IP et reCAPTCHA y sont déclarés.
- **HTTPS et en-têtes** : `install.php` (et `update.php` si besoin) ajoute dans `.htaccess` (si le site est en `https://`) une redirection HTTP → HTTPS en tête de fichier (308 : un POST garde sa méthode et son corps) et, pour les pages, des en-têtes `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, une CSP limitée aux directives sans effet sur les scripts en ligne (`frame-ancestors`, `base-uri`, `object-src`, `form-action`) et HSTS (sans `includeSubDomains`, pour ne pas imposer HTTPS à d'éventuels sous-domaines). Ils ne remplacent pas ceux de l'API et de `/auth`, plus stricts (`setifempty`). Nécessite `mod_headers` (sinon ignorés sans erreur).
- **Envois multipart refusés** (précaution après les failles PHP « PHP2Shell », CVE-2026-109618 et CVE-2026-109619, d'octobre 2026, déclenchées par un envoi multipart forgé que PHP traite avant le code du site) : `public/api/.htaccess` répond 403 à toute requête dont le `Content-Type` est `multipart` ; le site n'a aucun envoi de fichier (JSON et formulaires classiques). C'est une couche de plus, qui **ne remplace pas la mise à jour de PHP par l'hébergeur**. Elle ne couvre pas les fichiers PHP de la racine (`install.php`, `update.php`, supprimés après usage) : si l'un d'eux est présent, ajoutez les trois lignes `RewriteCond %{HTTP:Content-Type} multipart [NC]` / `RewriteRule ^ - [F,L]` en tête du `.htaccess` racine.
- **Adresse IP derrière un proxy** : `X-Forwarded-For` est lu de droite à gauche en ignorant les proxys déclarés dans `TRUSTED_PROXIES` ; ce qui est à gauche est écrit par le client et n'est jamais cru.
- **À faire de votre côté** (hors code) : registre des traitements, accords de sous-traitance (hébergeur, messagerie), procédure en cas de violation de données, qualification éventuelle du logiciel au regard du règlement (UE) 2017/745 sur les dispositifs médicaux, et accord du site SFMN pour l'interrogation automatisée de son outil.

Vérifié par `test-client-ip.php`, `test-config-store.php`, `test-recaptcha.js`, `test-browser-contact.js`, `test-install.js` et `test-admin-site.js`.

### Tests

Depuis la racine du dépôt (Node ne sert qu'aux tests : faux services et navigateur de test) :

```bash
node tests/test-core-lab.js            # zone de test du moteur (/auth/moteur) : scénarios de candidats, application, retour arrière
node tests/test-core-contract.js       # contrat du site envers le moteur (version + cas figés propres au site)
node core/tests/test-calcul.js         # calcul: 381 cas figés, deux modes d'arrondi
node tests/check-runtime-layout.js     # mise en forme de config/runtime.env
```

`test-calcul.js` compare le moteur (`core/`) à `core/tests/golden-calcul.json`. Les autres tests
(`test-install.js`, `test-admin-site.js`, `test-browser-admin.js`) ont besoin d'un Apache avec
mod_php et des faux services `fake-services.js` / `fake-smtp.js`.

### Administration du site (`/auth` : connexion Google, mesures, mises à jour)

Une seule page d'administration, à l'adresse **`https://<votre-domaine>/auth`**. Aucune page du site n'y renvoie, aucun raccourci, et `/admin` n'existe pas : seule la personne qui connaît l'adresse la trouve. Sans session, `/auth` envoie directement vers la **connexion Google** ; avec une session valide, elle affiche la page.

- **Qui peut entrer** : uniquement les adresses écrites dans `ADMIN_GOOGLE_EMAILS` (`.env`), comparées exactement après vérification par Google (pas de domaine entier, pas de joker). Un autre compte Google, même valide, reçoit « Accès refusé ».
- **Ce que la page propose** : la version installée ; un onglet **Mesures** (choix de l'antériorité 1/7/30/90 jours, 1 an ou tout, ou d'un mois précis ; statistiques, graphique par jour/mois, **carte Leaflet** auto-hébergée, filtres, pagination, **export CSV**) ; la **mise à jour** du site depuis le dépôt GitHub privé ; le **retour à la version précédente** ; l'historique des opérations.
- **Sécurité** : OpenID Connect avec PKCE, state et nonce à usage unique ; cookie `HttpOnly` / `SameSite=Lax` / `Secure` limité au chemin `/auth` ; session de 30 min d'inactivité (8 h au maximum) ; jeton CSRF et contrôle de l'origine sur chaque formulaire ; **nouvelle connexion Google exigée si la dernière date de plus de 10 minutes** avant une mise à jour ; HTTPS exigé ; politique CSP stricte (JavaScript uniquement sur la carte, servi par le site lui-même, aucune ressource tierce hors tuiles OpenStreetMap) ; toutes les valeurs des journaux (venues d'appelants anonymes) sont échappées ; export CSV protégé contre l'injection de formules. Tant que la configuration n'est pas complète, ou sans session valide, ou si une règle n'est pas respectée, les adresses autres que `/auth` répondent le 404 générique de l'API.

| URL | Rôle |
|---|---|
| `/auth` | Entrée : redirige vers Google, ou affiche la page si la session est ouverte. |
| `/auth/callback` | Retour de Google (à déclarer comme URI de redirection). |
| `/auth/mesures`, `/auth/mesures.csv` | Mesures (`?year=2026&month=9`) et export, sur session valide. |
| `/auth/update`, `/auth/rollback`, `/auth/logout` | Actions (formulaires `POST`). |

**Mise en place Google** : console Google Cloud → *API et services* → *Identifiants* → client OAuth de type *Application Web*, avec pour URI de redirection `https://<votre-domaine>/auth/callback`. En mode « Test » de l'écran de consentement, ajoutez vos adresses comme utilisateurs de test : aucune validation par Google n'est nécessaire. Renseignez `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `ADMIN_GOOGLE_EMAILS` et `SITE_PUBLIC_URL` (voir `.env.example`). `install.php` ajoute la règle `/auth` au `.htaccess`. `ADMIN_MEASUREMENTS_ENABLED=false` retire la section Mesures ; `ADMIN_SITE_ENABLED=false` coupe toute l'administration. Les mises à jour exigent en plus `ADMIN_UPDATE_ENABLED=true`.

**Jeton GitHub** : le dépôt étant privé, le téléchargement exige un jeton en lecture seule (*fine-grained token*, permission « Contents : lecture » sur ce seul dépôt). S'il est dans `.env` (`UPDATE_GITHUB_TOKEN`) et accepté par GitHub, il est utilisé. **S'il est absent ou refusé, la page affiche simplement un champ « Jeton d'accès »** pour en saisir un, valable pour l'opération en cours : il n'est jamais enregistré (ni disque, ni session, ni journal). Il n'est envoyé qu'à l'API GitHub, jamais à l'adresse de téléchargement vers laquelle GitHub redirige.

**Déroulement d'une mise à jour** : (1) le commit demandé (tag, branche ou SHA ; un tag validé est recommandé) est résolu en SHA exact ; (2) l'archive ZIP est téléchargée dans `backend/data/var/tmp/` (20 Mo maximum) ; (3) tout est validé **avant la moindre écriture sur le site** : dossier racine correspondant au SHA, aucun chemin `..`, absolu ou lien symbolique (l'archive entière est refusée), tailles et nombre de fichiers plafonnés, seuls les fichiers de la liste blanche sont extraits (jamais `.env`, `.htaccess` racine, `config.js`, `sitemap.xml`, `api/backend.php`, `backend/config/`, `backend/data/`, `tests/`), syntaxe de chaque fichier PHP contrôlée, version cible capable de se mettre elle-même à jour (sinon refusée) ; (4) la version en place est sauvegardée (une génération) ; (5) les fichiers sont remplacés un par un par renommage atomique (le code du backend d'abord, la façade `api/index.php` en dernier) ; (6) l'API est interrogée : **si elle ne répond pas correctement, l'ancienne version est rétablie automatiquement** ; (7) l'archive et les fichiers de préparation sont supprimés (même en cas d'échec), l'opération est journalisée (`backend/data/logs/updates.jsonl`) et un e-mail est envoyé à `CONTACT_DEST` si le SMTP est configuré. « Rétablir la version précédente » restaure la sauvegarde.

Limites : le `.htaccess` racine, `config.js`, `sitemap.xml` et `api/backend.php` restent gérés par `install.php` (et réparés au besoin par `update.php`) ; l'extension PHP `zip` est requise (la page l'indique) ; la première installation se fait par `install.php` (voir plus haut), la connexion Google n'existant pas encore à ce stade ; la lecture d'un mois de mesures charge le fichier en mémoire (d'où l'intérêt de `LOGS_MAX_BYTES` et `LOGS_RETENTION_MONTHS` sur un petit hébergement).

Vérification : `tests/test-admin-site.js` (112 contrôles : connexion refusée/acceptée, PKCE, CSRF, connexion ancienne, mesures et export CSV avec valeurs hostiles, jeton invalide/valide/du `.env`, archives piégées, retour arrière automatique et manuel, fichiers hors liste blanche, jeton jamais écrit ni transmis au téléchargement, anciennes adresses `/api/admin/*` en 404) contre un site déployé sous Apache et `tests/fake-services.js` (faux Google + faux GitHub) ; les archives de test sont fabriquées par `tests/make-fixtures.php`.

### Maintenance (onglet Site de `/auth`)

Deux interrupteurs, mémorisés dans `data/var/maintenance.json` (créer ou supprimer ce fichier par FTP a le même effet si `/auth` est inaccessible ; `{"mode":"site"}` ou `{"mode":"full"}`) :

- **Maintenance** : la page d'accueil (`/`) répond 503 (`Retry-After: 3600`, `noindex`) et n'affiche que « Site en maintenance » avec le lien vers l'outil de la SFMN (`SFMN_CALCULATOR_URL`) ; l'API continue de fonctionner. Si l'accueil est servi sans passer par PHP (blocs `.htaccess` SEO absents), `/api/config` annonce `maintenance` et `app.js` affiche le même avis en plein écran.
- **Maintenance complète** : en plus, `POST /api/calculate` et `POST /api/contact` répondent 503 avec `{"ok":false,"error":{"code":"MAINTENANCE",…}}`.

**Page d'attente de l'accueil** : `index.html` s'ouvre sur un avis de maintenance quasi vide (le calculateur est masqué par une feuille de style en ligne) ; un script en ligne interroge `/api/config` et ne retire l'avis qu'une fois le site confirmé en service. API injoignable ou en maintenance : l'avis reste. Le bloc est balisé `<!--MAINTENANCE-GATE-->` (la page de test SFMN de l'administration le retire).

Restent toujours disponibles : `/auth` (pour lever la maintenance), `/health`, `/api/config` et `/api/public-config` (lecture seule, utilisés par les contrôles des mises à jour). Les changements sont inscrits au journal des mises à jour.

### Zone de test du moteur (`/auth/moteur`)

Pour essayer une nouvelle version du moteur partagé (`core/`, typiquement une nouvelle majeure) **sans toucher au site** :

1. **Télécharger un candidat** (dépôt `propriétaire/nom`, tag/branche/commit) : seuls `src/`, `data/` et `VERSION` du moteur sont conservés, dans `data/var/core-lab/candidate/`. Le dépôt peut contenir le moteur dans `core/` ou à sa racine.
2. **Lancer les tests** : une batterie automatisée (≈ 180 cas × 2 modes d'arrondi) compare le candidat au moteur en service et le contrôle seul : identité et version, analyse statique du code (refus d'exécuter un candidat qui appelle des fonctions système, réseau ou d'écriture), chargement, fonctions utilisées par le site, catalogue (isotopes, scénarios, cures), robustesse (exceptions, avertissements PHP), structure des réponses (champ supprimé ou changé de type = rupture, champ ajouté = compatible), écarts de résultats détaillés par scénario, cohérence physique (monotonie selon le débit, la limite et les cures, arrondis, déterminisme, dose faible) et performance. Le **compte rendu** (verdict *identique* / *compatible* / *évolution* / *bloquant*, explication de chaque test, détails) s'affiche dans la page et se télécharge en Markdown.
3. **Appliquer au site** (impossible si le verdict est *bloquant* ou *identique*, case de confirmation de lecture) : le moteur en service est sauvegardé (une génération), remplacé par le candidat (le réglage `data/settings.json` du site est conservé), le site est contrôlé et l'ancien moteur rétabli automatiquement s'il ne répond plus. Le nouveau moteur est **épinglé** (`data/var/core-pin.json`) : les mises à jour du site (`/auth` et `update.php`) ne modifient plus `backend/core/` tant que l'épinglage n'est pas retiré (bouton « Retirer l'épinglage »). **Rétablir le moteur précédent** annule l'application.

Tests : `node tests/test-core-lab.js` (candidat identique, évolution, code dangereux, fonction manquante, bug d'arrondi, champ supprimé ; application, épinglage et retour arrière).

### Non vérifié de bout en bout

L'appel réel à Google (reCAPTCHA **et** connexion de `/auth`), le téléchargement depuis le vrai GitHub et l'envoi vers un vrai serveur SMTP n'ont pas pu être testés (pas de réseau sortant dans l'environnement de test) : à contrôler avec les vraies clés lors de l'essai à blanc.
