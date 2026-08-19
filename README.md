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
| `config.js` | Configuration runtime (URLs API/site) injectée par `deploy_update.sh` en production. |
| `downloads/` | Modèles Xplore à importer dans un RIS Xplore (voir `xplore.html`). |

## Backend

| Fichier | Description |
|---|---|
| `server.js` | API Node.js : formules de calcul, proxy SFMN, journalisation, contact. |
| `deploy_update.sh` | Script de déploiement/mise à jour idempotent. |
| `monitor_node.sh` | Script de supervision généré par `deploy_update.sh` (health-check + relance). |
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

En mode `sfmn`, le backend interroge `SFMN_CALCULATOR_URL` et parse le HTML de résultats.
Ajouter `sfmn_debug: true` dans le payload (ou `SFMN_DEBUG=true` côté serveur) pour des traces détaillées.

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

Chaque appel `POST /api/calculate` est loggé dans `logs/measurements.jsonl` (backend) :
`timestamp`, `ip`, `input`, `result`.

- Page web : `GET /admin/mesures` → `admin-mesures.html`
- API JSON : `GET /api/admin/measurements?year=2026`
- Export CSV : `GET /api/admin/measurements.csv?year=2026`

L'accès requiert `ADMIN_TOKEN` fourni via le header `X-Admin-Token`.
Le jeton ne doit jamais être passé en query string : il serait enregistré dans les journaux
d'accès du serveur, l'historique du navigateur et l'en-tête `Referer`.

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

1. Copier `deploy_update.sh` et un `.env` (basé sur `.env.example`) dans le répertoire d'exploitation.
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
./deploy_update.sh
```

Le script : crée les dossiers, fait un `git pull`, synchronise backend/frontend, protège `.env` / `.htaccess`, génère `config.js` et `monitor_node.sh`, et lance la supervision.

> **Hébergements cPanel / o2switch** : le script ne crée pas l'application Node dans le panel. Le routage proxy/passenger doit être configuré manuellement au moins une fois. Si `/api` n'est pas routé automatiquement, définir `API_PUBLIC_URL` avec une URL absolue.

## Supervision

`deploy_update.sh` génère `monitor_node.sh` qui :
- vérifie `http://127.0.0.1:$API_PORT/health`,
- vérifie `MONITOR_PUBLIC_CONFIG_URL` (défaut : `$API_PUBLIC_URL/config`) pour détecter un proxy cassé,
- redémarre via `pm2` si disponible, sinon via `nohup node server.js`.

### Cron (manuel)

```bash
* * * * * /home/votre_user/[Backend]/monitor_node.sh >/dev/null 2>&1
```

### Cron (automatique)

Définir `INSTALL_CRON_MONITOR=true` dans `.env` : `deploy_update.sh` ajoute la ligne sans doublon.
