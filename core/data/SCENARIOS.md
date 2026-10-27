# Scénarios d'exposition — guide pour les ajouter ou les modifier

Les scénarios sont décrits dans **`scenarios.json`**, rien d'autre. Chaque scénario est une situation de la vie quotidienne de
l'entourage (conjoint, enfant, collègues, transports…) pour laquelle le moteur calcule **une durée de restriction**.

> **Un scénario ajouté dans ce fichier apparaît automatiquement** comme une ligne de plus dans les résultats (`rows` et
> `recommendations_days`), donc dans le tableau de résultats de tout site ou interface qui affiche ces lignes (page d'accueil de
> radiv, version imprimable, consignes patient, modèle Xplore…). Aucun code à modifier.

## Champs d'un scénario

| Champ | Obligatoire | Rôle |
|---|---|---|
| `audience_code` | oui | Identifiant stable : minuscules, chiffres et `_` (ex. `voisin_palier`). Clé de la ligne dans `recommendations_days`. **Ne jamais le renommer** une fois publié (contrat des sites). `scenario_utilisateur` est réservé au scénario personnalisé. |
| `label` | oui | Libellé affiché : qui est exposé, dans quelles circonstances. |
| `exposures` | oui | Liste d'une ou plusieurs périodes d'exposition **par jour** (voir ci-dessous). |
| `limit` | oui | Limite de dose du scénario, en mSv (> 0). |
| `shares_limit_across_cures` | non (`true`) | `true` : la même personne cumule les doses cure après cure → la limite est **divisée** par le nombre de cures. `false` : chaque cure expose d'autres personnes (ex. passagers de transports en commun) → limite non divisée. |
| `condition` | non | Texte des conditions. **Omettez-le** : il est généré à partir de `exposures` et `limit`, donc toujours cohérent avec ce qui est calculé. |

Une exposition (`exposures[]`) :

| Champ | Obligatoire | Rôle |
|---|---|---|
| `hours` | oui | Durée d'exposition par jour, en heures (> 0). |
| `distance` | oui | Distance au patient, en mètres (> 0). |
| `unit_factor_at_1m` | non (`false`) | `true` : à exactement 1 m, le facteur géométrique vaut 1 (valeur de référence). `false` : le facteur est calculé. À mettre à `true` pour toute exposition à 1 m. |
| `note` | non | Précision affichée entre parenthèses dans le texte des conditions (ex. `"nuit"`). |

## Exemple : ajouter un scénario

```json
{
  "audience_code": "voisin_palier",
  "label": "Contact régulier avec un voisin de palier",
  "exposures": [
    { "hours": 1, "distance": 1, "unit_factor_at_1m": true }
  ],
  "limit": 1
}
```

Condition générée : « Exposition : 1 h à 1 m · limite de dose : 1 mSv ». Avec deux expositions (`{"hours": 2, "distance": 0.5, "unit_factor_at_1m": false}` puis
`{"hours": 2, "distance": 1, "unit_factor_at_1m": true}`) : « Exposition : 2 h à 0,5 m + 2 h à 1 m · limite de dose : 1 mSv ».

## Liste de contrôle

1. Le scénario et sa limite proviennent d'une **publication citée** (voir `SOURCES.md`).
2. `audience_code` unique et définitif ; `label` clair.
3. Ajouter le scénario **à la fin** du fichier (l'ordre du fichier est l'ordre des lignes ; le scénario personnalisé reste toujours en dernier).
4. Régénérer le fichier de cas figés (`tests/golden-calcul.json`) : chaque calcul gagne une ligne ; vérifier que **les lignes existantes n'ont pas changé**.
5. Incrémenter `VERSION` (ajout d'un scénario = version **mineure** ; modifier la limite, les durées ou les distances d'un scénario existant change des résultats = version **majeure**).
6. Lancer `node core/tests/test-calcul.js`, `php core/tests/test-scenarios.php`, `php core/tests/test-api.php`.

Le moteur contrôle ce fichier à chaque calcul : champ manquant, doublon, valeur négative ou nulle… provoquent une erreur explicite (avec le
numéro et le code du scénario) plutôt qu'un résultat faux.

## Ce qui n'est pas automatique

- Les **consignes patient rédigées** (version patient du site, modèle Xplore) existent en texte détaillé pour les scénarios historiques ; un
  scénario nouveau reçoit une consigne générique (« limiter les contacts rapprochés prolongés et garder une distance raisonnable »)
  avec son libellé et sa durée. Pour un texte spécifique, adapter ces interfaces.
- L'outil en ligne de la SFMN ne connaît que les scénarios historiques : la page de test de l'administration ne compare que ceux-là.
