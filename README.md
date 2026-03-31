# dosimetrieRIV

## Architecture

- `index.ml` : page principale utilisateur (visuel identique), sans formules métier.
- `api-fonctionnement.html` : page explicative du fonctionnement de l'API, reliée à la page principale.
- `server.js` : API Node.js qui contient toutes les formules de calcul.
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
  "isotope_code": "iode131_25_fixation",
  "dose_rate": 20,
  "patient_size_cm": 160,
  "user_period_days": null,
  "user_hours_1": null,
  "user_distance_1": null,
  "user_hours_2": null,
  "user_limit": null,
  "benign_activity_mbq": null,
  "benign_fixation_pct": null
}
```
Les valeurs possibles de `isotope_code` sont listées dans `api-fonctionnement.html`.

Cas particulier : pour `isotope_code=iode131_benin`, les champs obligatoires sont `benign_activity_mbq` et `benign_fixation_pct`; `dose_rate` est ignoré.
Pour `isotope_code=non_defini`, `user_period_days` est aussi obligatoire et doit être strictement positif.

En cas d'erreur, l'API renvoie un objet explicite :
- `ok: false`
- `error.code`
- `error.message`
- `error.reason`
- `error.expected_payload`


## Lancement local

```bash
npm install
npm start
```

## Déploiement o2switch

1. Copier `deploy_update.sh` et un `.env` (basé sur `.env.example`) dans `~/dosimetrie`.
2. Personnaliser au minimum dans `.env` :
   - `API_PORT`
   - `BACKEND_DIR`
   - `FRONTEND_DIR`
   - `SITE_PUBLIC_URL`
   - `API_PUBLIC_URL`
3. Lancer :

```bash
cd ~/dosimetrie
./deploy_update.sh
```

Le script :
- crée les dossiers nécessaires,
- clone/met à jour le dépôt via token,
- déploie le backend et les pages frontend (`index.ml` + `api-fonctionnement.html`),
- écrit `config.js` côté frontend avec l'URL API publique,
- génère `monitor_node.sh` et le lance (contrôle santé + relance automatique).

## Supervision Node.js (site toujours actif)

Le script de déploiement génère :

```bash
$BACKEND_DIR/monitor_node.sh
```

Ce script :
- vérifie `http://127.0.0.1:$API_PORT/health`,
- redémarre via `pm2` si disponible,
- sinon relance via `nohup node server.js`.

### Mise en place cron (manuel)

Ajouter cette ligne au crontab utilisateur :

```bash
* * * * * /home/votre_user/dosimetrie/monitor_node.sh >/dev/null 2>&1
```

Commandes :

```bash
crontab -e
crontab -l
```

### Mise en place cron (automatique)

Si vous mettez `INSTALL_CRON_MONITOR=true` dans `.env`, `deploy_update.sh` ajoute la ligne cron automatiquement (sans doublon).
