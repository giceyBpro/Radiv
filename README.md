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
| `admin-mesures.html` | Consultation et export des mesures journalisées. |
| `api-fonctionnement.html` | Documentation publique du contrat API. |
| `test-api.html` | Page de test manuel des endpoints API. |
| `tox.html` | Page de diagnostic interne (accessible via `/tox`, sans lien depuis l'interface). |
| `config.js` | Configuration runtime (URLs API/site) injectée par `deploy.sh` en production. |
| `downloads/` | Modèles Xplore à importer dans un RIS Xplore (voir `xplore.html`). |
| `robots.txt` | Autorise l'indexation de la page d'accueil uniquement (voir [Référencement](#référencement)). |

## Référencement

Seule `index.html` (l'accueil) doit être indexée par les moteurs de recherche. Deux
niveaux de protection, indépendants l'un de l'autre :

- `robots.txt` interdit le crawl de tout le reste (`Disallow: /` avec deux exceptions
  `Allow: /$` et `Allow: /index.html$`). Les noms des pages cachées (`tox.html`,
  `admin-mesures.html`, `xplore.html`…) n'y sont volontairement pas listés : ce fichier
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
| `deploy.sh` | Script de déploiement/mise à jour idempotent. |
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

- Page web : `admin-mesures.html` (page statique sans lien de navigation, comme `tox.html`/`xplore.html` — accessible en tapant l'URL)
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
jeton reçoit alors ce 404 générique. `admin-mesures.html` reste servie en tant que
fichier statique (Apache, pas Node) mais n'affiche plus rien d'utilisable une fois les
routes coupées.

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
