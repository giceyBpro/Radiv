<?php
// Noms des champs de saisie affichés par RadIV (surcouche du site : le moteur de calcul n'est pas modifié).
//
// Le moteur décrit chaque champ de saisie dans core/data/fields.json (libellé, unité, type, bornes, aide : voir core/data/FIELDS.md)
// et l'annonce par GET /api/config. Cette table permet à RadIV d'afficher un AUTRE nom pour un champ, sans toucher au moteur ni à l'API :
//   - pour renommer un champ : ajouter une ligne  'code_du_champ' => 'Nom affiché par RadIV'  dans LABELS ci-dessous ;
//   - un champ absent de LABELS s'affiche sous le libellé du moteur : un champ ajouté plus tard dans le moteur apparaît donc sans rien faire.
// S'applique aux champs construits d'après la description du moteur (ceux qui n'ont pas de saisie intégrée à la page) et à la taille du
// patient (champ commun). Les autres champs de base ont leur libellé dans les pages elles-mêmes.
//
// Publié par GET /api/public-config (clé field_labels : code → nom affiché), jamais dans GET /api/config.
declare(strict_types=1);

namespace Radiv\FieldLabels;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

// code du champ (core/data/fields.json) → nom affiché par RadIV. Un champ absent de cette liste garde le libellé du moteur.
const LABELS = [
    'patient_size_cm' => 'Taille du patient, en cm',   // champ commun : le libellé de RadIV remplace « Taille du patient (cm) » du moteur
];

// Noms à afficher pour une liste de champs du moteur : code → nom de RadIV, ou libellé du moteur à défaut.
function labels(array $fields): array
{
    $out = [];
    foreach ($fields as $field) $out[$field['code']] = LABELS[$field['code']] ?? (string) $field['label'];
    return $out;
}
