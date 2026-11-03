<?php
// Intitulés des isotopes propres à RadIV (surcouche du site : le moteur de calcul n'est pas modifié).
//
// Le moteur fournit, pour chaque isotope, un intitulé (champ « label » de core/data/isotopes.json). RadIV affiche ses propres
// intitulés, définis dans LABELS ci-dessous : le code de l'isotope (api_code) ne change jamais, seul le texte affiché change.
//   - Pour changer un intitulé : modifier la ligne correspondante de LABELS.
//   - Isotope ajouté plus tard dans le moteur : absent de LABELS, il s'affiche sous son intitulé du moteur, sans rien faire.
// La liste des isotopes est triée par ordre alphabétique de l'intitulé affiché (accents ignorés, nombres dans l'ordre
// numérique : « Iode 131 (5 %) » avant « Iode 131 (25 %) »), l'isotope « personnalisé » (non_defini) toujours en dernier.
//
// Appliquée en un seul endroit pour tout le site : /api/config (donc pages, script Xplore, documentation d'API) et le champ
// selected.label des réponses de /api/calculate.
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

// Liste des isotopes du moteur, avec les intitulés de RadIV, triée (« personnalisé » en dernier). Les autres champs sont intacts.
function apply(array $isotopes): array
{
    foreach ($isotopes as &$isotope) $isotope['label'] = label_of($isotope);
    unset($isotope);
    usort($isotopes, static function (array $a, array $b): int {
        if ($a['api_code'] === LAST || $b['api_code'] === LAST) return ($a['api_code'] === LAST) <=> ($b['api_code'] === LAST);
        return strnatcasecmp(sort_key($a['label']), sort_key($b['label']));
    });
    return $isotopes;
}

// Résultat d'un calcul avec l'intitulé de RadIV dans « selected » (le reste de la réponse est inchangé).
function apply_to_result(array $result): array
{
    if (isset($result['selected']['api_code'])) $result['selected']['label'] = label_of($result['selected']);
    return $result;
}
