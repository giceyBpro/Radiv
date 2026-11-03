<?php
// Intitulés des isotopes propres à RadIV (surcouche du site : le moteur de calcul n'est pas modifié).
//
// Le moteur fournit, pour chaque isotope, un intitulé (champ « label » de core/data/isotopes.json). RadIV affiche ses propres
// intitulés, définis dans LABELS ci-dessous : le code de l'isotope (api_code) ne change jamais.
//   - Pour changer un intitulé : modifier la ligne correspondante de LABELS.
//   - Isotope ajouté plus tard dans le moteur : absent de LABELS, il s'affiche sous son intitulé du moteur, sans rien faire.
//
// Ces intitulés ne sont destinés qu'aux pages de RadIV et à la page « Info API ». Ils sont publiés par GET /api/public-config (la
// configuration propre au site), jamais dans GET /api/config ni dans les réponses de calcul : l'API reste celle du moteur,
// avec ses intitulés. /api/public-config ajoute :
//   - isotope_labels : code de l'isotope → intitulé de RadIV ;
//   - isotope_order  : les codes dans l'ordre d'affichage, soit l'ordre alphabétique de l'intitulé affiché (accents ignorés,
//     nombres dans l'ordre numérique : « Iode 131 (5 %) » avant « Iode 131 (25 %) »), l'isotope « personnalisé » en dernier.
declare(strict_types=1);

namespace Radiv\IsotopeLabels;

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

// api_code du moteur → intitulé affiché par RadIV.
const LABELS = [
    'iode131_0_fixation'  => 'Iode 131 (0 %) · néoplasie',
    'iode131_5_fixation'  => 'Iode 131 (5 %) · néoplasie oligométastatique',
    'iode131_25_fixation' => 'Iode 131 (25 %) · néoplasie multimétastatique',
    'iode131_benin'       => 'Iode 131 · indication non néoplasique',
    'lipiodol_131i'       => 'Lipiodol marqué à l\'iode 131',
    'lutetium177_net'     => 'Lutétium 177 · tumeurs neuroendocrines',
    'mibg_131i'           => 'Iobenguane (MIBG) · iode 131',
    'microspheres_90y'    => 'Microsphères d\'yttrium 90',
    'microspheres_166ho'  => 'Microsphères d\'holmium 166',
    'psma_177lu'          => 'Lutétium 177 · PSMA',
    'radium223'           => 'Radium 223',
    'synovectomie_90y'    => 'Radiosynoviorthèse · yttrium 90',
    'synovectomie_169er'  => 'Radiosynoviorthèse · erbium 169',
    'synovectomie_186re'  => 'Radiosynoviorthèse · rhénium 186',
];

// Isotope qui reste toujours en dernier dans la liste (celui dont l'appelant fournit la période).
const LAST = 'non_defini';

// Intitulé affiché d'un isotope du moteur : celui de RadIV s'il existe, sinon celui du moteur.
function label_of(array $isotope): string
{
    return LABELS[$isotope['api_code']] ?? (string) $isotope['label'];
}

// Clé de tri : minuscules, sans accents ni ponctuation. Comparée avec strnatcasecmp (nombres dans l'ordre numérique).
function sort_key(string $label): string
{
    $plain = strtr(mb_strtolower($label, 'UTF-8'), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
    ]);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $plain));
}

// Intitulés de RadIV et ordre d'affichage pour une liste d'isotopes du moteur : [code → intitulé, codes triés].
function display(array $isotopes): array
{
    $labels = [];
    foreach ($isotopes as $isotope) $labels[$isotope['api_code']] = label_of($isotope);

    $codes = array_keys($labels);
    usort($codes, static function (string $a, string $b) use ($labels): int {
        if ($a === LAST || $b === LAST) return ($a === LAST) <=> ($b === LAST);
        return strnatcasecmp(sort_key($labels[$a]), sort_key($labels[$b]));
    });
    return [$labels, $codes];
}
