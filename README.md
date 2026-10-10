# dosimetrieRIV

Application web de calcul des durées de restriction de contact après Radiothérapie Interne Vectorisée (RIV), intégrant l'API RadIV / SFMN et des modèles Xplore téléchargeables.

## Pages et fichiers frontend

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
| `config.js` | Configuration runtime (URLs API/site) injectée par `deploy.sh` en production. |
| `downloads/` | Modèles Xplore à importer dans un RIS Xplore (voir `xplore.html`). |
| `robots.txt` | Autorise l'indexation de la page d'accueil uniquement (voir [Référencement](#référencement)). |

`deploy.sh` ajoute dans `.htaccess` des raccourcis sans `.html` pour ces pages : `/legal`, `/contact`, `/print`, `/explain`, `/doc` (→ `api-fonctionnement.html`),
`/test-api`, `/xplore`. La consultation des mesures n'a plus de page statique : elle fait partie
de l'administration du site, derrière la connexion Google (voir [Version PHP](#version-php-api)).

Les pages `tox.html` et `.tox-complet.html` ont été retirées du dépôt (`tox` devient un site indépendant). Les scripts de déploiement et les mises à jour de `/auth` les suppriment des sites déjà installés (sauvegardées d'abord par `/auth`) et retirent l'ancienne règle `/tox` du `.htaccess`.

## Référencement

Seule `index.html` (l'accueil) doit être indexée par les moteurs de recherche. Deux
niveaux de protection, indépendants l'un de l'autre :

- `robots.txt` interdit le crawl de tout le reste (`Disallow: /` avec deux exceptions
  `Allow: /$` et `Allow: /index.html$`). Les noms des pages cachées (`xplore.html`…) n'y sont volontairement pas listés : ce fichier
  est public, les y nommer reviendrait à les annoncer.
- Chaque page sauf l'accueil porte `<meta name="robots" content="noindex, nofollow">`,
  qui bloque l'indexation même si un lien externe venait un jour à pointer vers elle.

`sitemap.xml` est généré par `deploy.sh` (comme `config.js`) car il doit contenir
une URL absolue (`SITE_PUBLIC_URL`) — inutile de le commiter avec un domaine factice.
Il ne référence que la page d'accueil.

**Important** : ces mécanismes empêchent l'indexation *future*. Ils ne retirent pas une
page déjà indexée. Si une des pages actuellement en `noindex` a pu être trouvée par
Google avant ce changement, il faut vérifier la Google Search Console du domaine et
demander une suppression manuelle si nécessaire — `robots.txt`/`noindex` seuls n'y
suffisent pas rétroactivement.

## Backend

| Fichier | Description |
|---|---|
| `server.js` | API Node.js : routage HTTP, proxy SFMN, journalisation, contact, sécurité. |
| `calculation.js` | Mode de calcul « local » : données (isotopes, scénarios), formalisme et validation. Fichier autonome (aucune dépendance au reste du serveur), pensé pour être audité ou testé isolément. |
| `deploy.sh` | Script de déploiement/mise à jour idempotent (backend Node). |
| `api/` + `deploy-php.sh` | Port PHP du backend, à contrat d'API identique (voir [Version PHP](#version-php-api)). Pas encore en production. |
| `monitor_node.sh` | Script de supervision généré par `deploy.sh` (health-check + relance). |
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
  "calculation_mode": "local",
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
  "cure_count": 1
}
```

- Les champs absents sont traités comme `null`.
- `calculation_mode` : `local` (défaut) ou `sfmn` (interroge le formulaire SFMN distant).
- Pour `iode131_benin` : `benign_activity_mbq` et `benign_fixation_pct` sont obligatoires ; `dose_rate` est ignoré.
- Pour `non_defini` : `user_period_days` est obligatoire (> 0).
- `cure_count` : optionnel (défaut `1`). Pris en compte pour `radium223`, `psma_177lu`, `lutetium177_net`.

En mode `local`, l'arrondi des durées de restriction (en jours) dépend de `RESTRICTION_ROUNDING_MODE` :
`round` (défaut, arrondit au jour le plus proche — légèrement plus protecteur) ou `floor`
(troncature, reproduit exactement les valeurs de l'outil SFMN de référence — vérifié sur les
7 scénarios avec Radium-223, 100 µSv/h, 150 cm).

En mode `sfmn`, le backend interroge `SFMN_CALCULATOR_URL` et parse le HTML de résultats.
Ajouter `sfmn_debug: true` dans le payload (ou `SFMN_DEBUG=true` côté serveur) pour des traces détaillées.

`SFMN_MODE_ENABLED=false` désactive entièrement ce mode : absent de `GET /api/config`
(`calculation_modes`, `default_calculation_mode` retombe sur `local`), toute requête
`POST /api/calculate` avec `calculation_mode: "sfmn"` bascule silencieusement sur le calcul
local (réponse avec `calculation_mode: "local"`, sans erreur — les deux formalismes sont
alignés), sélecteur de mode masqué sur la page d'accueil, exemples de payload adaptés sur
`test-api.html` et `api-fonctionnement.html`.

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

Chaque appel `POST /api/calculate` est loggé dans `logs/measurements-AAAA-MM.jsonl` (un
fichier par mois, backend) : `timestamp`, `ip`, `input`, `result`. Le découpage mensuel
et la lecture en flux évitent de bloquer le serveur le temps de charger un historique
qui grossit sans limite. Un ancien `logs/measurements.jsonl` (avant ce découpage) est
scindé automatiquement en fichiers mensuels au démarrage, puis archivé en `.migrated`.
Chaque fichier mensuel est en plus limité par `LOGS_MAX_BYTES` (défaut 50 Mo) : au-delà,
il est basculé en `.1` avant de reprendre à zéro.

`MEASUREMENT_LOGGING_LEVEL` contrôle ce qui est écrit (minimisation RGPD) : `none` (rien),
`user` (horodatage + IP + géolocalisation uniquement, pas les données du calcul), `full`
(défaut, comportement ci-dessus). `LOGS_RETENTION_MONTHS` purge automatiquement (au
démarrage puis une fois par jour) les fichiers mensuels plus vieux que N mois ; vide par
défaut = rétention illimitée.

Ces deux réglages sont reflétés automatiquement sur `mentions-legales.html` (via
`GET /api/public-config`) : la section protection des données décrit toujours l'état réel
de la configuration, sans édition manuelle à chaque changement de `.env`.

**Backend Node uniquement** — dans la version PHP il n'y a plus de jeton ni de ces routes : la
consultation passe par la connexion Google de la page `/auth` (voir plus bas). La page statique
`admin-mesures.html` a été supprimée ; ces routes ne restent utilisables que par appel direct.

- Périodes disponibles : `GET /api/admin/measurements/periods`
- API JSON : `GET /api/admin/measurements?year=2026&month=08`
- Export CSV : `GET /api/admin/measurements.csv?year=2026&month=08`

`year` seul renvoie tous les mois de cette année ; sans aucun paramètre, le mois en
cours. `month` sans `year` est ignoré.

L'accès requiert `ADMIN_TOKEN` fourni via le header `X-Admin-Token`.
Le jeton ne doit jamais être passé en query string : il serait enregistré dans les journaux
d'accès du serveur, l'historique du navigateur et l'en-tête `Referer`.
Tentatives limitées à 10/minute/IP (jeton correct ou non), pour rendre un brute-force
sur `ADMIN_TOKEN` impraticable.

Un jeton absent, invalide, ou une tentative déjà bloquée par la limite ci-dessus
répondent 404 (pas 401) — exactement comme une route inexistante, pour ne pas même
confirmer que ces routes existent à qui n'a pas le bon jeton. Seul un jeton correct
révèle qu'elles existent, via une vraie réponse 200.

`ADMIN_MEASUREMENTS_ENABLED=false` désactive entièrement ces trois routes : même le bon
jeton reçoit alors ce 404 générique.

## Modèles Xplore RIS

Le dossier `downloads/` contient deux modèles prêts à importer dans Xplore :

| Fichier | Type | Description |
|---|---|---|
| `MN_Radiopro_APIRADIV_generique.xml` | QUDEM | Questionnaire de saisie dosimétrique avec bouton RadIV intégré. |
| `MN_Consignes-radioprotection_RadIV.xml` | INSER | Insertion automatique générant la fiche consignes patient. |

Voir `xplore.html` pour les instructions d'installation et les points d'adaptation par centre.

## Lancement local

```bash
npm install
npm start
```

Version PHP (voir plus bas), depuis la racine du dépôt — sert aussi les pages statiques :

```bash
php -S 127.0.0.1:8081 -t . api/tests/dev-router.php
```

## Déploiement

1. Copier `deploy.sh` et un `.env` (basé sur `.env.example`) dans le répertoire d'exploitation.
2. Configurer dans `.env` :

```
API_PORT
BACKEND_DIR
FRONTEND_DIR          # défaut : $HOME/public_html/frontend
SITE_PUBLIC_URL
API_PUBLIC_URL
ADMIN_TOKEN           # recommandé
```

3. Lancer :

```bash
./deploy.sh
```

Le script : crée les dossiers, fait un `git pull`, synchronise backend/frontend, protège `.env` / `.htaccess`, génère `config.js` et `monitor_node.sh`, et lance la supervision.

Si `SITE_PUBLIC_URL` commence par `www.`, le script ajoute automatiquement dans `.htaccess`
une redirection 301 (http et non-www confondus) vers ce domaine canonique — idempotent,
sans effet si la règle existe déjà. Si `SITE_PUBLIC_URL` n'est pas en `www.`, aucune
redirection n'est forcée. En cas de changement de domaine entre deux déploiements,
l'ancienne règle n'est pas supprimée automatiquement : le script avertit et il faut
nettoyer `.htaccess` à la main.

Le script bloque aussi, toujours dans `.htaccess`, l'accès HTTP direct à `.env`,
`.runtime.env`, `server.js`, `package(-lock).json`, `logs/`, `node_modules/`, etc.
Sur les hébergements cPanel/Passenger où `FRONTEND_DIR` et `BACKEND_DIR` pointent vers
le même dossier (`PassengerAppRoot`), ces fichiers backend se retrouvent physiquement
dans le docroot public — ce blocage évite qu'ils soient servis tels quels. Sans effet
si `FRONTEND_DIR` est déjà un dossier purement statique séparé.

> **Hébergements cPanel / o2switch** : le script ne crée pas l'application Node dans le panel. Le routage proxy/passenger doit être configuré manuellement au moins une fois. Si `/api` n'est pas routé automatiquement, définir `API_PUBLIC_URL` avec une URL absolue.

## Supervision

`deploy.sh` génère `monitor_node.sh` qui :
- vérifie `http://127.0.0.1:$API_PORT/health`,
- vérifie `MONITOR_PUBLIC_CONFIG_URL` (défaut : `$API_PUBLIC_URL/config`) pour détecter un proxy cassé,
- redémarre via `pm2` si disponible, sinon via `nohup node server.js`.

En usage cron (sans argument), `monitor_node.sh` ne redémarre que si ces contrôles échouent —
un service déjà sain n'est pas interrompu à chaque passage. `deploy.sh` l'appelle lui-même avec
`--force-restart` en fin de déploiement : un service déjà sain n'a jamais de raison de repasser
le health-check en échec, donc sans cet argument le nouveau `server.js`/`.runtime.env` tout juste
synchronisés sur le disque ne seraient jamais chargés par le process en cours d'exécution.

> **Hébergements cPanel/Passenger** : Passenger démarre et gère sa propre instance de l'app
> Node (`PassengerAppRoot`), indépendamment du process nohup/pm2 que `monitor_node.sh` relance
> ci-dessus — les deux peuvent tourner en parallèle, et c'est celui de Passenger qui sert
> réellement le site public. `deploy.sh` touche donc aussi `tmp/restart.txt` en fin de
> déploiement (convention Passenger : redémarrage à la requête suivante). Sans effet si
> Passenger n'est pas utilisé pour ce déploiement.

### Cron (manuel)

```bash
* * * * * /home/votre_user/[Backend]/monitor_node.sh >/dev/null 2>&1
```

### Cron (automatique)

Définir `INSTALL_CRON_MONITOR=true` dans `.env` : `deploy.sh` ajoute la ligne sans doublon.

## Version PHP (`api/`)

Port PHP du backend Node, à **contrat d'API strictement identique** : mêmes routes
(`/health`, `/api/config`, `/api/public-config`, `/api/calculate`, `/api/contact`), mêmes réponses JSON, mêmes en-têtes, mêmes variables d'environnement.
Aucun `.php` n'apparaît dans les URLs : les appelants externes (notamment le questionnaire
RIS Xplore) n'ont rien à changer. **État : validé en local contre le Node inchangé, pas encore
déployé en production** ; `server.js`, `calculation.js` et `deploy.sh` restent la référence
tant que la bascule n'est pas faite.

Pourquoi : Node est un process persistant à superviser (`monitor_node.sh`, cron, PID,
Passenger). PHP s'exécute par requête sous Apache : déployer = copier des fichiers, plus
aucun process à relancer ni à surveiller.

| Fichier | Rôle |
|---|---|
| `api/index.php` | Point d'entrée unique et routage (équivalent de `server.js`). |
| `api/calculation.php` | Calcul local — port de `calculation.js` (mêmes données, mêmes formules). |
| `api/lib/*.php` | Config/.env, état partagé (rate-limit, cache géoIP), HTTP, journal des mesures, géoIP, SFMN, contact/SMTP, accès admin. |
| `api/.htaccess` | Réécrit tout `/api/*` vers `index.php` et refuse les fichiers de configuration/données. |
| `api/tests/` | Outillage de développement (jamais déployé) : comparaisons Node/PHP, faux serveur SMTP, routeur de dev. |
| `deploy-php.sh` | Déploiement simplifié (voir ci-dessous). |

### Prérequis hébergement

- **PHP ≥ 8.0** choisi pour le site dans cPanel (Sélecteur PHP), extensions `curl`, `mbstring`, `json`, `openssl` (activées par défaut sur o2switch).
- `APCu` est **optionnel** : s'il est présent il porte le rate-limit par IP et le cache géoIP (équivalent direct des `Map` en mémoire de Node) ; sinon ils sont stockés dans `api/var/` (fichiers JSON verrouillés). `RATE_LIMIT_BACKEND=auto|apcu|file` force le choix. Sous LiteSpeed/LSAPI la mémoire APCu n'est pas forcément partagée entre processus : en cas de doute, `RATE_LIMIT_BACKEND=file` est le choix sûr.
- Vérifier APCu : cPanel → Sélecteur PHP → Extensions, ou `php -m | grep apcu`.

### Configuration

Mêmes noms de variables que `.env.example` (rien à renommer). `api/` lit `api/.runtime.env`
(généré par `deploy-php.sh`) puis `api/.env` (réglages purement locaux, jamais écrasé) ; une
variable déjà définie dans l'environnement du serveur l'emporte. Variables propres à Node et sans
objet en PHP : `API_PORT`, `MONITOR_PUBLIC_CONFIG_URL`, `INSTALL_CRON_MONITOR`,
`ALERT_ON_API_DOWN_*` (l'alerte « API indisponible » n'existe plus : elle ne servait qu'à
surveiller le process Node). Nouveauté : `RATE_LIMIT_BACKEND`.

### Déploiement (`deploy-php.sh`)

Même `.env` de déploiement que `deploy.sh` (`GIT_REPO`, `GIT_TOKEN`, `FRONTEND_DIR`, `SITE_PUBLIC_URL`,
`API_PUBLIC_URL`...). Frontend et API vivent dans **un seul dossier** (`FRONTEND_DIR`, la racine
web) : l'API est déployée dans `FRONTEND_DIR/api/`. Le script contrôle la syntaxe PHP avant
toute publication, synchronise frontend et `api/` (en protégeant `.env`, `logs/`, `var/`),
génère `config.js`, `sitemap.xml`, `api/.runtime.env` (600) et les règles `.htaccess` (raccourcis
sans `.html`, `/health`, redirection www). Il ne gère plus ni `monitor_node.sh`, ni cron, ni PID,
ni `tmp/restart.txt`, ni `npm install`.

### Première installation sans SSH (`install.php`, ex. hébergement mutualisé OVH)

Quand l'hébergement n'offre ni SSH ni `git` (cas de l'offre gratuite incluse avec un nom de domaine chez OVH), le site s'installe avec **un seul fichier** envoyé par FTP :

1. Générez une clé secrète (`openssl rand -hex 24`) et collez-la dans `install.php`, à la place de `CHANGEZ-MOI` (`const INSTALL_KEY`). Sans clé d'au moins 24 caractères, la page refuse tout.
2. Envoyez **uniquement** `install.php` à la racine web (dossier `www/` chez OVH) puis ouvrez `https://<votre-domaine>/install.php`. HTTPS est exigé (secrets saisis dans la page).
3. Saisissez la clé, la page **vérifie l'hébergement** (PHP ≥ 8.0, extensions cURL / zip / mbstring / OpenSSL, droits d'écriture, accès à GitHub, espace disque, `mod_rewrite` si détectable) et bloque l'installation si un point critique échoue.
4. Renseignez le formulaire : dépôt et version à installer (un **tag validé** de préférence), **jeton d'accès GitHub** (lecture seule, jamais enregistré), adresse du site, connexion Google (identifiant, secret, adresses autorisées), formulaire de contact (reCAPTCHA, SMTP), options (arrondi, journalisation, conservation, taille des journaux, mode SFMN). **Ou, plus simple : fournissez un fichier `.env`** (voir ci-dessous), la page ne demande alors plus que la version et le jeton.
5. La page télécharge l'archive du commit exact, la valide **avant la moindre écriture** (mêmes contrôles que les mises à jour : chemins, liens symboliques, tailles, liste blanche, syntaxe PHP, version capable de se mettre à jour), sauvegarde ce qui existait (ex. page d'attente de l'hébergeur), installe, génère `config.js`, `sitemap.xml`, `api/.runtime.env` (600), complète le `.htaccess` **sans toucher à ses règles existantes**, puis contrôle `/api/config`, `/health` et `/auth`. **Au moindre échec pendant l'écriture, tout est remis dans l'état précédent.**
6. Elle se **supprime** (`install.php` n'est jamais publié par `deploy.sh`, `deploy-php.sh` ni par `/auth`) et pose un verrou ; si la suppression automatique est impossible, supprimez-le par FTP (il est déjà inactif). Si un contrôle final échoue (réécriture d'URL absente...), le fichier est conservé et la page peut être relancée.

**Installer à partir d'un `.env`** : `install.php` lit un fichier `.env` au même format que `.env.example` (celui de `deploy-php.sh`). Il le cherche à l'emplacement de la constante `ENV_FILE`, en tête de `install.php` : par défaut `../.env`, le dossier **parent** du site (chez OVH, au-dessus de `www/`, donc **non accessible depuis le web**). Elle accepte aussi un chemin absolu (`/home/utilisateur/prive/.env`) ou depuis le dossier personnel (`~/prive/.env`) ; `''` désactive la lecture. Si le fichier est trouvé, la page affiche la configuration lue (secrets masqués, jamais renvoyés au navigateur), puis ne demande que **la version** (préremplie par `GIT_BRANCH`) et **le jeton** : les champs du formulaire ne peuvent pas modifier la configuration du `.env`. La configuration est contrôlée comme dans le formulaire (adresses, URL, ports...) ; une valeur invalide bloque l'installation et est signalée. Les variables reconnues : celles de `.env.example` (`SITE_PUBLIC_URL`, `GOOGLE_*`, `ADMIN_GOOGLE_EMAILS`, `ADMIN_UPDATE_ENABLED`, `SMTP_*`, `CONTACT_DEST`, `RECAPTCHA_*`, `SFMN_*`, `RESTRICTION_ROUNDING_MODE`, `MEASUREMENT_LOGGING_LEVEL`, `LOGS_*`, `CALCULATE_RATE_LIMIT`, `TRUSTED_PROXIES`...), et le dépôt vient de `UPDATE_GITHUB_REPO` ou de `GIT_REPO`. Le fichier lu n'est jamais modifié ; s'il avait été placé dans le dossier public (lisible par tous), il est supprimé après l'installation.

Le jeton peut être saisi dans la page, ou mis dans ce `.env` pour ne plus rien taper : `UPDATE_GITHUB_TOKEN` (reporté dans `api/.runtime.env`, 600, pour que `/auth` ne le redemande pas) ou `GIT_TOKEN` (utilisé pour l'installation seulement, non reporté ; `/auth` en demandera un). Un jeton du `.env` refusé par GitHub est ignoré et la page en redemande un. **Ne mettez jamais le jeton dans `install.php` lui-même** : ce fichier est servi par le site, et un échec de sa suppression le laisserait en ligne.

Ensuite : déclarez dans Google Cloud l'URI de redirection `https://<votre-domaine>/auth/callback`, connectez-vous sur `/auth` ; les mises à jour suivantes se font depuis cette page. Au plus 10 clés incorrectes par heure et par adresse. Écrit en PHP 7.0 pour afficher un message clair, plutôt qu'une erreur blanche, sur un hébergement resté en PHP ancien. Si l'extension `zip` ou les connexions sortantes manquent, la page l'indique : installez alors par FTP avec `deploy-php.sh` exécuté ailleurs.

Vérification : `api/tests/test-install.js` (110 contrôles sur un Apache vierge : clé absente/courte/fausse/limitée, lecture du `.env` (emplacements parent / public / `~/` / absolu, jetons valides ou périmés, `.env` invalide, secrets jamais renvoyés), diagnostic, 15 saisies invalides, jeton refusé, 6 archives piégées, retour arrière complet sur échec d'écriture, fichiers préexistants de l'hébergeur conservés ou restaurés, version qui ne démarre pas, installation réussie, jeton jamais écrit, listes blanches identiques à `updater.php` et `deploy-php.sh`).

### Bascule Node → PHP (cPanel / o2switch)

1. **Essai à blanc, sans toucher à la production** : lancer `deploy-php.sh` avec un `FRONTEND_DIR` de test (sous-domaine séparé), puis vérifier `GET /health`, `GET /api/config`, un `POST /api/calculate` et la page de contact.
2. Quand c'est concluant, sur la production : **arrêter puis supprimer l'application dans cPanel → Setup Node.js App** (sinon `/api/*` reste servi par Node via Passenger ; le script avertit si le `.htaccess` contient encore une configuration Passenger).
3. Lancer `deploy-php.sh` sur la racine web de production.
4. Reprendre l'historique des mesures (même format, rien à convertir) : copier `BACKEND_DIR/logs/measurements-*.jsonl*` vers `FRONTEND_DIR/api/logs/`.
5. Supprimer l'entrée cron `monitor_node.sh` (`crontab -e`) : elle relancerait Node en boucle. Le script signale sa présence.
6. Contrôler `GET <API_PUBLIC_URL>/config`, puis un appel réel depuis le questionnaire Xplore.

**Retour arrière** : recréer l'application Node dans cPanel, relancer l'ancien `deploy.sh` (inchangé dans le dépôt), recopier `api/logs/` vers `BACKEND_DIR/logs/` si des mesures ont été collectées entre-temps.

### Vérification (Node et PHP en parallèle)

Depuis la racine du dépôt, avec Node sur `:3003` et PHP sur `:8081` (`php -S`, voir *Lancement local*) — l'administration (`/auth`) se teste séparément, voir ci-dessus :

```bash
node api/tests/compare-node-php.js 20000   # calcul: 20 031 cas, deux modes d'arrondi, sortie JSON identique
node api/tests/compare-http.js             # statut, en-têtes et corps de chaque route
```

`api/tests/fake-smtp.js` simule un serveur SMTP (TLS direct et STARTTLS) pour tester l'envoi du contact.

### Écarts connus avec la version Node

- Corps de requête > 64 Ko : réponse `413` JSON propre (Node coupait la connexion sans répondre).
- La géolocalisation IP et la journalisation s'exécutent **après** l'envoi de la réponse (Node attendait jusqu'à 3 appels réseau avant de répondre) : l'appelant n'est plus retardé.
- Purge des vieux journaux : au plus une fois par jour, déclenchée par une requête (pas de process persistant pour la planifier).
- `/api/contact` : en cas d'échec réseau vers Google, la réponse est `400 Échec vérification reCAPTCHA.` (Node répondait `400 JSON invalide`).
- Un tableau/objet JSON passé comme nombre (`"dose_rate": []`) donne une erreur de validation (JS le convertissait en 0).

### Administration du site (`/auth` : connexion Google, mesures, mises à jour)

Une seule page d'administration, à l'adresse **`https://<votre-domaine>/auth`**. Aucune page du site n'y renvoie, aucun raccourci, et `/admin` n'existe pas : seule la personne qui connaît l'adresse la trouve. Sans session, `/auth` envoie directement vers la **connexion Google** ; avec une session valide, elle affiche la page. Elle remplace l'ancienne page `admin-mesures.html` et son jeton `ADMIN_TOKEN`, **supprimés** de la version PHP.

- **Qui peut entrer** : uniquement les adresses écrites dans `ADMIN_GOOGLE_EMAILS` (`.env`), comparées exactement après vérification par Google (pas de domaine entier, pas de joker). Un autre compte Google, même valide, reçoit « Accès refusé ».
- **Ce que la page propose** : la version installée ; la **consultation des mesures** (mois en cours ou mois choisi, 300 lignes les plus récentes affichées, **export CSV** complet) ; la **mise à jour** du site depuis le dépôt GitHub privé ; le **retour à la version précédente** ; l'historique des opérations.
- **Sécurité** : OpenID Connect avec PKCE, state et nonce à usage unique ; cookie `HttpOnly` / `SameSite=Lax` / `Secure` limité au chemin `/auth` ; session de 30 min d'inactivité (8 h au maximum) ; jeton CSRF et contrôle de l'origine sur chaque formulaire ; **nouvelle connexion Google exigée si la dernière date de plus de 10 minutes** avant une mise à jour ; HTTPS exigé ; politique CSP stricte sans aucun JavaScript ; toutes les valeurs des journaux (venues d'appelants anonymes) sont échappées ; export CSV protégé contre l'injection de formules. Tant que la configuration n'est pas complète, ou sans session valide, ou si une règle n'est pas respectée, les adresses autres que `/auth` répondent le 404 générique de l'API.

| URL | Rôle |
|---|---|
| `/auth` | Entrée : redirige vers Google, ou affiche la page si la session est ouverte. |
| `/auth/callback` | Retour de Google (à déclarer comme URI de redirection). |
| `/auth/mesures`, `/auth/mesures.csv` | Mesures (`?year=2026&month=9`) et export, sur session valide. |
| `/auth/update`, `/auth/rollback`, `/auth/logout` | Actions (formulaires `POST`). |

**Mise en place Google** : console Google Cloud → *API et services* → *Identifiants* → client OAuth de type *Application Web*, avec pour URI de redirection `https://<votre-domaine>/auth/callback`. En mode « Test » de l'écran de consentement, ajoutez vos adresses comme utilisateurs de test : aucune validation par Google n'est nécessaire. Renseignez `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `ADMIN_GOOGLE_EMAILS` et `SITE_PUBLIC_URL` (voir `.env.example`). `deploy-php.sh` ajoute la règle `/auth` au `.htaccess`. `ADMIN_MEASUREMENTS_ENABLED=false` retire la section Mesures ; `ADMIN_SITE_ENABLED=false` coupe toute l'administration. Les mises à jour exigent en plus `ADMIN_UPDATE_ENABLED=true`.

**Jeton GitHub** : le dépôt étant privé, le téléchargement exige un jeton en lecture seule (*fine-grained token*, permission « Contents : lecture » sur ce seul dépôt). S'il est dans `.env` (`UPDATE_GITHUB_TOKEN`) et accepté par GitHub, il est utilisé. **S'il est absent ou refusé, la page affiche simplement un champ « Jeton d'accès »** pour en saisir un, valable pour l'opération en cours : il n'est jamais enregistré (ni disque, ni session, ni journal). Il n'est envoyé qu'à l'API GitHub, jamais à l'adresse de téléchargement vers laquelle GitHub redirige.

**Déroulement d'une mise à jour** : (1) le commit demandé (tag, branche ou SHA ; un tag validé est recommandé) est résolu en SHA exact ; (2) l'archive ZIP est téléchargée dans `api/var/tmp/` (20 Mo maximum) ; (3) tout est validé **avant la moindre écriture sur le site** : dossier racine correspondant au SHA, aucun chemin `..`, absolu ou lien symbolique (l'archive entière est refusée), tailles et nombre de fichiers plafonnés, seuls les fichiers de la liste blanche sont extraits (jamais `.env`, `.htaccess` racine, `config.js`, `sitemap.xml`, `logs/`, `var/`, `tests/`), syntaxe de chaque fichier PHP contrôlée, version cible capable de se mettre elle-même à jour (sinon refusée) ; (4) la version en place est sauvegardée (une génération) ; (5) les fichiers sont remplacés un par un par renommage atomique, `api/index.php` en dernier ; (6) l'API est interrogée : **si elle ne répond pas correctement, l'ancienne version est rétablie automatiquement** ; (7) l'archive et les fichiers de préparation sont supprimés (même en cas d'échec), l'opération est journalisée (`api/logs/updates.jsonl`) et un e-mail est envoyé à `CONTACT_DEST` si le SMTP est configuré. « Rétablir la version précédente » restaure la sauvegarde.

Limites : le `.htaccess` racine, `config.js` et `sitemap.xml` restent gérés par `deploy-php.sh` ; l'extension PHP `zip` est requise (la page l'indique) ; la première installation se fait par `deploy-php.sh` (avec SSH) ou par `install.php` (voir plus haut), la connexion Google n'existant pas encore à ce stade ; la lecture d'un mois de mesures charge le fichier en mémoire (d'où l'intérêt de `LOGS_MAX_BYTES` et `LOGS_RETENTION_MONTHS` sur un petit hébergement).

Vérification : `api/tests/test-admin-site.js` (112 contrôles : connexion refusée/acceptée, PKCE, CSRF, connexion ancienne, mesures et export CSV avec valeurs hostiles, jeton invalide/valide/du `.env`, archives piégées, retour arrière automatique et manuel, fichiers hors liste blanche, jeton jamais écrit ni transmis au téléchargement, anciennes adresses `/api/admin/*` en 404) contre un site déployé sous Apache et `api/tests/fake-services.js` (faux Google + faux GitHub) ; les archives de test sont fabriquées par `api/tests/make-fixtures.php`.

### Non vérifié de bout en bout

L'appel réel à Google (reCAPTCHA **et** connexion de `/auth`), le téléchargement depuis le vrai GitHub et l'envoi vers un vrai serveur SMTP n'ont pas pu être testés (pas de réseau sortant dans l'environnement de test) : à contrôler avec les vraies clés lors de l'essai à blanc.
