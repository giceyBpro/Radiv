<?php
// ===== MOTEUR DE CALCUL RIV — POINT D'ENTRÉE =====
// Il suffit d'inclure ce fichier pour disposer de tout le moteur (espace de noms RivEngine) :
//
//     require '/chemin/vers/core/src/bootstrap.php';
//     $resultat = \RivEngine\calculate(['isotope_code' => 'radium223', 'dose_rate' => 100, 'patient_size_cm' => 170]);
//
// Le moteur n'a AUCUNE dépendance : ni framework, ni base de données, ni réseau, ni secret. Il lit seulement
// les fichiers de data/ (isotopes, scénarios, cures) et accepte, en option, un réglage d'arrondi (rounding.php).
//
// Fichiers (chacun a un rôle unique, lisible isolément) :
//   rounding.php   mode d'arrondi des durées (« round » ou « floor ») et équivalent de Math.round
//   compat.php     conversions à l'identique de JavaScript (Number(), affichage des nombres, JSON.stringify)
//   catalog.php    lecture des données : isotopes, scénarios d'exposition, nombres de cures autorisés
//   physics.php    formules : décroissance, géométrie, durée de restriction, débit de dose de l'iode bénin
//   calculate.php  validation des entrées et assemblage du résultat (calculate())
//
// Contrat stable : mêmes données, mêmes formules, mêmes clés JSON. Les écarts de sémantique PHP/JS qui pourraient
// le casser sont traités et commentés là où ils interviennent.

declare(strict_types=1);

if (!defined('RIV_ENGINE')) define('RIV_ENGINE', __DIR__);

require __DIR__ . '/rounding.php';
require __DIR__ . '/compat.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/physics.php';
require __DIR__ . '/calculate.php';
