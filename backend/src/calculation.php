<?php
// Fichier de TRANSITION, sans effet : il n'est chargé par rien. Les sites installés avec la version 71e4999 (liste de
// fichiers requis ancienne) exigent encore sa présence dans toute version qu'ils installent depuis /auth ; le moteur
// de calcul est maintenant dans core/ (installé dans backend/core/). À retirer d'une version ultérieure, une fois
// tous les sites passés par une version qui ne l'exige plus (le retrait se fait ensuite tout seul à la mise à jour).
declare(strict_types=1);

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API
