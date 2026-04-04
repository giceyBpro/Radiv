# dosimetrieRIV

## Architecture

- `index.html` : page principale utilisateur (visuel identique), sans formules métier.
- `index-legacy.html` : copie rétro-compatible de l'ancienne page principale.
- `api-fonctionnement.html` : page explicative du contrat API.
- `contact.html` : formulaire de contact protégé par Google reCAPTCHA, envoi email côté backend.
- `server.js` : API Node.js qui contient toutes les formules de calcul.
- `admin-mesures.html` : page de consultation/export des mesures collectées.
- `deploy_update.sh` : script autonome et idempotent de déploiement/mise à jour.
- `monitor_node.sh` : script généré automatiquement dans le backend pour superviser l'API et la relancer si elle tombe.
- `.env.example` : modèle de configuration pour le déploiement.

## API

### `GET /api/config`
Retourne les radiopharmaceutiques disponibles et l'isotope par défaut.

### `POST /api/calculate`
Calcule les durées recommandées.

Exemple de payload JSON :

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
  "cure_count": 4
}
```
Les valeurs possibles de `isotope_code` sont listées dans `api-fonctionnement.html`.
Les champs à `null` peuvent être omis : les champs absents sont traités comme `null` par l'API.
`calculation_mode` accepte `local` (par défaut) ou `sfmn` (fonction spécifique en préparation).

Cas particulier : pour `isotope_code=iode131_benin`, les champs obligatoires sont `benign_activity_mbq` et `benign_fixation_pct`; `dose_rate` est ignoré.
Pour `isotope_code=non_defini`, `user_period_days` est aussi obligatoire et doit être strictement positif.
`cure_count` est optionnel (défaut `1`). Il est pris en charge pour `radium223` (1/4/6), `psma_177lu` (1/4/6), `lutetium177_net` (1/4).

La réponse contient aussi `recommendations_days` : dictionnaire des recommandations en jours par type de public (`conjoint_plus_60`, `conjoint_moins_60`, `conjointe_enceinte`, `transport_commun`, `enfant_moins_3_ans`, `enfant_3_11_ans`, `collegues_travail`, `scenario_utilisateur`).

### Exemple de retour (`POST /api/calculate`)

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


En cas d'erreur, l'API renvoie un objet explicite :
- `ok: false`
- `error.code`
- `error.message`
- `error.reason`
- `error.expected_payload`

## Formulaire de contact (backend)

- Page : `contact.html` (lien depuis `index.html`)
- Endpoint config publique : `GET /api/public-config` (retourne `recaptcha_site_key`)
- Endpoint envoi : `POST /api/contact`

Sécurité / confidentialité :
- adresse destinataire non exposée au frontend,
- vérification reCAPTCHA faite côté backend,
- envoi email réalisé côté backend.

Variables `.env` requises :

### Configuration Google reCAPTCHA (v2 Checkbox)

1. Ouvrir Google reCAPTCHA Admin : https://www.google.com/recaptcha/admin/create
2. Type recommandé : **reCAPTCHA v2** puis **"Je ne suis pas un robot" (Checkbox)**.
3. Ajouter votre/vos domaine(s) (ex: `www.example.org`).
4. Récupérer :
   - **Site key** → `RECAPTCHA_SITE_KEY`
   - **Secret key** → `RECAPTCHA_SECRET_KEY`
5. Redéployer (`./deploy_update.sh`) pour injecter les clés côté backend.

Documentation Google : https://developers.google.com/recaptcha/docs/display

- `RECAPTCHA_SITE_KEY`
- `RECAPTCHA_SECRET_KEY`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_SECURE`
- `SMTP_USER`
- `SMTP_PASS`
- `SMTP_FROM`
- `CONTACT_DEST`

## Journal des mesures (backend)

Chaque appel `POST /api/calculate` est journalisé côté backend dans :
- `logs/measurements.jsonl`

Champs loggés :
- date/heure (`timestamp`),
- IP demandeur (`ip`),
- données d'entrée (`input`),
- résultats (`result`).

## Consultation des mesures (backend)

- Page web : `GET /admin/mesures`
- API JSON : `GET /api/admin/measurements?year=2026`
- Export CSV : `GET /api/admin/measurements.csv?year=2026`

Le filtre année est appliqué côté backend (non traité côté frontend).

Si `ADMIN_TOKEN` est défini, fournir le token :
- header `X-Admin-Token`, ou
- query string `?token=...`

## Lancement local

```bash
npm install
npm start
```

## Déploiement

1. Copier `deploy_update.sh` et un `.env` (basé sur `.env.example`) dans votre répertoire d'exploitation.
2. Personnaliser au minimum dans `.env` :
   - `API_PORT`
   - `BACKEND_DIR`
   - `FRONTEND_DIR` (par défaut: `$HOME/public_html/frontend`)
   - `SITE_PUBLIC_URL`
   - `API_PUBLIC_URL`
   - `ADMIN_TOKEN` (recommandé)
3. Lancer :

```bash
cd ~/[Backend]
./deploy_update.sh
```

### Important (hébergements avec interface type cPanel/o2switch)

`deploy_update.sh` **ne crée pas** automatiquement une application Node.js dans l’interface d’hébergement.

Il déploie le code, génère la configuration runtime, lance le monitor et redémarre l’API via `pm2`/`nohup`, mais la partie “création/routage” de l’app Node (proxy/passenger) dans le panel doit être faite **au moins une fois** manuellement.

> Si votre hébergement ne route pas automatiquement `/api` vers Node.js, définissez `API_PUBLIC_URL` avec une URL absolue joignable (ex: `https://api.votre-domaine.tld/api`).

Le script :
- crée les dossiers nécessaires,
- fait un `git pull` sur la branche configurée,
- synchronise backend/frontend vers les bons répertoires (les fichiers frontend du repo sont remplacés/supprimés selon l'état git),
- supprime localement les fichiers supprimés du repo,
- protège les fichiers de configuration statiques locaux (`.env`, `.runtime.env`, `.htaccess`, etc.),
- écrit `config.js` côté frontend avec l'URL API publique,
- génère `monitor_node.sh` et le lance (contrôle santé + relance automatique),
- affiche des messages `[DEPLOY]` pendant l’exécution pour suivre chaque étape.

## Supervision Node.js (site toujours actif)

Le script de déploiement génère :

```bash
$BACKEND_DIR/monitor_node.sh
```

Ce script :
- vérifie `http://127.0.0.1:$API_PORT/health`,
- vérifie aussi `MONITOR_PUBLIC_CONFIG_URL` (par défaut: `$API_PUBLIC_URL/config`) pour détecter un proxy public cassé,
- redémarre via `pm2` si disponible,
- sinon relance via `nohup node server.js`.

### Mise en place cron (manuel)

Ajouter cette ligne au crontab utilisateur :

```bash
* * * * * /home/votre_user/[Backend]/monitor_node.sh >/dev/null 2>&1
```

Commandes :

```bash
crontab -e
crontab -l
```

### Mise en place cron (automatique)

Si vous mettez `INSTALL_CRON_MONITOR=true` dans `.env`, `deploy_update.sh` ajoute la ligne cron automatiquement (sans doublon).
