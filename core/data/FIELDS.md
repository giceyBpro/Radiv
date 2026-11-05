# Champs de saisie (`data/fields.json`)

Les champs dont la saisie **dépend de l'isotope** (débit de dose, activité, fixation, période...) sont décrits dans `data/fields.json`.
Le moteur expose cette description aux interfaces (`RivEngine\input_fields()`, publiée par l'API dans `GET config`, clé `fields`) : un site construit son formulaire à partir d'elle, et
un champ ajouté dans le fichier apparaît donc sur les sites **sans modification de leur code**. Cette page explique comment ajouter ou
modifier un champ.

## Ce qu'est un champ, et ce qu'il n'est pas

- Un champ décrit **une saisie** : son code dans l'API, son libellé, son unité, son type, ses bornes, son aide.
- Il ne dit **pas** s'il est obligatoire : c'est le **profil de calcul** de l'isotope qui décide, pour chaque champ, « obligatoire »,
  « facultatif » ou « ignoré » (`src/profiles.php`, élément `inputs`). Un champ ajouté ici est « ignoré » pour tous les profils
  existants : rien ne change tant qu'un profil ne l'utilise pas.
- Il ne contient **aucune règle de calcul** : la règle s'écrit dans un profil. La marche à suivre complète (déclarer le champ, écrire le
  profil, l'attribuer à des isotopes, tester) est en tête de `src/profiles.php`.

## Les entrées d'une description

| Entrée | Rôle |
|---|---|
| `code` | Nom du champ dans l'API (minuscules, chiffres et `_`, commence par une lettre). Ne peut pas reprendre un champ commun du calcul (`isotope_code`, `patient_size_cm`, `cure_count`, `user_hours_1`, `user_distance_1`, `user_hours_2`, `user_limit`, `provider`). |
| `label` | Libellé par défaut. Chaque site peut afficher le sien (voir « Noms affichés sur les sites »). |
| `unit` | Facultatif. Unité affichée à côté du champ (`MBq`, `kg`...). |
| `type` | `number` (nombre) ou `choice` (liste de choix). |
| `min`, `max` | Facultatifs. Bornes proposées par l'interface. Le moteur ne les contrôle pas lui-même : c'est le profil qui valide ses champs. |
| `step` | Facultatif. Pas de saisie : un nombre, ou `"any"`. |
| `help` | Facultatif. Phrase d'aide affichée avec le champ. |
| `options` | Pour `choice` seulement, obligatoire : liste de `{"value": "...", "label": "..."}`. Le moteur refuse toute autre valeur et signale l'erreur. |

Exemples :

```json
{"code": "poids_kg", "label": "Poids du patient (kg)", "unit": "kg", "type": "number", "min": 1, "step": "any", "help": "Poids au moment de la mesure."}
{"code": "voie", "label": "Voie d'administration", "type": "choice", "options": [{"value": "iv", "label": "Intraveineuse"}, {"value": "oral", "label": "Orale"}]}
```

## Les quatre champs de base

`dose_rate`, `benign_activity_mbq`, `benign_fixation_pct` et `user_period_days` sont utilisés par les profils d'origine : **ne pas les
retirer** (le moteur le refuse). On peut modifier leur libellé, leur unité et leur aide.

## Noms affichés sur les sites

Le libellé de ce fichier est celui du moteur. Un site peut afficher un autre nom : RadIV a un petit tableau
(`backend/src/lib/field_labels.php`) qui remplace le libellé d'un champ par le sien, sans toucher au moteur ni à l'API ; un champ
absent de ce tableau garde le libellé du moteur.

## Vérifications

Le moteur contrôle ce fichier au chargement : code manquant, doublon, type inconnu, `choice` sans options... lèvent une erreur
explicite (numéro et code du champ) plutôt que de produire des résultats faux. Voir `tests/test-fields.php`.
