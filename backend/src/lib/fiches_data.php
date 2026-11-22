<?php
// Contenu des fiches d'information par radiopharmaceutique (grand public). BROUILLONS : non publiés sur le site public, consultables
// seulement dans l'administration (/auth/fiches), pour relecture. Une fiche = un tableau :
//   slug, title (titre de page), h1, description (meta), lead (introduction), blocks (liste de sections), faq (questions fréquentes),
//   review (points à vérifier : affichés uniquement dans l'aperçu de l'administration, jamais sur le site public), status ('draft').
// Un bloc = ['h' => titre, 'level' => 2|3, 'p' => [paragraphes HTML simples], 'ul' => [puces HTML simples]].
// Les textes sont rédigés sans durée chiffrée : la durée de restriction est donnée par l'équipe de médecine nucléaire (calcul du moteur).
declare(strict_types=1);

if (!defined('RADIV_ENTRY')) { http_response_code(404); exit; } // jamais exécutable hors de l'API

return [
    [
        'slug' => 'iode-131',
        'status' => 'draft',
        'title' => "Iode 131 (I-131) : consignes pour le patient et l'entourage après un traitement",
        'h1' => "Traitement par iode 131 (I-131) : ce qu'il faut savoir pour le patient et l'entourage",
        'description' => "Traitement par iode 131 d'une hyperthyroïdie ou d'un cancer de la thyroïde : déroulement, séjour éventuel, consignes de radioprotection pour l'entourage et risque de contamination.",
        'lead' => "L'iode 131 (I-131) est un iode radioactif. La thyroïde est la seule glande à capter l'iode : le produit se fixe donc presque uniquement sur elle, et c'est ce qui permet de la traiter précisément. Il est utilisé dans deux situations différentes, décrites séparément ci-dessous : <strong>l'hyperthyroïdie</strong> et <strong>le cancer de la thyroïde</strong>.",
        'blocks' => [
            ['h' => "Si vous êtes traité pour une hyperthyroïdie", 'level' => 2, 'p' => [
                "L'hyperthyroïdie est un fonctionnement trop actif de la thyroïde (maladie de Basedow, nodule « toxique », goitre multinodulaire toxique). L'iode 131 réduit l'activité de la glande : le rayonnement agit sur une très courte distance autour du produit, ce qui épargne en grande partie les organes voisins.",
            ]],
            ['h' => "Comment se passe le traitement ?", 'level' => 3, 'p' => [
                "Le traitement consiste en <strong>une prise unique par la bouche</strong> (gélule ou liquide). Selon l'activité administrée et l'organisation du service, il se fait le plus souvent <strong>sans hospitalisation</strong> : vous rentrez chez vous le jour même avec des consignes précises.",
                "L'effet est <strong>progressif</strong> : il se constate en quelques semaines à quelques mois, avec un suivi par prises de sang. Après le traitement, la thyroïde peut devenir insuffisante ; un traitement par hormone thyroïdienne est alors prescrit, ce qui est fréquent et bien maîtrisé.",
            ]],
            ['h' => "Après la prise, à la maison", 'level' => 3, 'p' => [
                "Pendant les jours qui suivent, votre corps émet un faible rayonnement et élimine l'iode, surtout par les urines, mais aussi par la salive et la sueur. L'équipe de médecine nucléaire vous indique <strong>une durée de restriction de contact</strong>, adaptée à votre situation et à votre entourage (conjoint, enfants, femme enceinte, travail).",
            ], 'ul' => [
                "garder de la distance avec les autres personnes, surtout les <strong>enfants</strong> et les <strong>femmes enceintes</strong> ;",
                "dormir séparément pendant la durée indiquée ;",
                "limiter les contacts rapprochés prolongés (les contacts brefs restent possibles) ;",
                "boire régulièrement et uriner souvent, comme l'équipe vous le conseille.",
            ]],

            ['h' => "Si vous êtes traité pour un cancer de la thyroïde", 'level' => 2, 'p' => [
                "Après l'ablation de la thyroïde pour un cancer dit « différencié » (papillaire ou folliculaire), l'iode 131 détruit les petits fragments de tissu thyroïdien qui peuvent rester. Il permet aussi de rechercher, par une image réalisée quelques jours plus tard, d'éventuelles cellules thyroïdiennes ailleurs dans le corps.",
            ]],
            ['h' => "Comment se passe le traitement ?", 'level' => 3, 'p' => [
                "Le traitement est <strong>préparé</strong> : l'équipe vous explique comment stimuler la thyroïde (par un arrêt temporaire de l'hormone thyroïdienne ou par des injections) et vous demande de suivre un <strong>régime pauvre en iode</strong> pendant une période définie.",
                "L'activité d'iode administrée est <strong>plus élevée</strong> que pour une hyperthyroïdie. Vous séjournez donc en général <strong>quelques jours dans une chambre spécialement protégée</strong> du service de médecine nucléaire. Les visites sont limitées, et vous sortez lorsque le rayonnement mesuré est redescendu à un niveau permettant le retour à domicile.",
            ]],
            ['h' => "Après le séjour, à la maison", 'level' => 3, 'p' => [
                "Des consignes de radioprotection s'appliquent encore à la sortie. Leur <strong>durée dépend de la mesure effectuée avant votre départ</strong> et de votre situation familiale et professionnelle ; elle est généralement plus longue que pour une hyperthyroïdie. Les mêmes principes s'appliquent : distance, temps de contact réduit, prudence particulière avec les enfants et les femmes enceintes.",
            ]],

            ['h' => "Dans les deux cas", 'level' => 2, 'p' => [], 'ul' => [
                "<strong>Contamination</strong> : l'iode éliminé par l'organisme (urine, salive, sueur) peut se retrouver sur la peau, les objets et les sanitaires. Les gestes d'hygiène indiqués par l'équipe (se laver soigneusement les mains, ne pas partager les couverts au cours du repas…) s'ajoutent à la durée de restriction.",
                "<strong>Grossesse et allaitement</strong> : le traitement n'est pas réalisé en cas de grossesse, et l'allaitement doit être interrompu. L'équipe précise aussi pendant combien de temps éviter une grossesse après le traitement.",
                "<strong>Déplacements</strong> : pendant un certain temps, les portiques de détection (aéroports, bâtiments sécurisés) peuvent signaler une radioactivité résiduelle. Gardez sur vous le document remis par le service.",
                "<strong>Suivi</strong> : des consultations et des examens sont programmés pour vérifier l'efficacité du traitement.",
            ]],
            ['h' => "Pourquoi ces précautions ?", 'level' => 2, 'p' => [
                "Les consignes sont des mesures de précaution, pas le signe d'un danger : le rayonnement diminue de jour en jour, car l'iode 131 perd naturellement la moitié de sa radioactivité en <strong>environ huit jours</strong> et le corps l'élimine. Pour comprendre la justification de ces consignes et la notion de durée de restriction, consultez la page <a href=\"/informations\">Informations pour le patient et l'entourage</a>.",
            ]],
        ],
        'faq' => [
            ['q' => "Puis-je prendre mes enfants dans mes bras après le traitement ?", 'a' => "Les contacts brefs sont en général possibles ; ce sont les contacts rapprochés et prolongés qui sont limités, surtout avec les jeunes enfants. Suivez la durée de restriction donnée par votre équipe."],
            ['q' => "Quand pourrai-je reprendre le travail ?", 'a' => "Cela dépend de la durée de restriction qui vous est indiquée et de la nature de votre travail (contact étroit avec des enfants ou des femmes enceintes, par exemple). Demandez à l'équipe, qui peut vous remettre un document pour votre employeur."],
            ['q' => "Pourquoi mon traitement est-il différent selon la maladie ?", 'a' => "Pour une hyperthyroïdie, on réduit l'activité d'une thyroïde en place avec une activité modérée ; pour un cancer, on détruit les tissus restants après la chirurgie avec une activité plus élevée, ce qui explique le séjour en chambre protégée."],
            ['q' => "La durée donnée est-elle la même pour tout le monde ?", 'a' => "Non : elle dépend du produit, de la mesure du rayonnement à votre sortie et de la situation des personnes qui vous entourent. C'est l'équipe de médecine nucléaire qui la détermine."],
        ],
        'review' => [
            "Hyperthyroïdie : « le plus souvent sans hospitalisation » et « retour le jour même » : confirmer pour votre pratique (selon l'activité et la réglementation locale).",
            "Cancer : préparation par arrêt de l'hormone ou injections (rhTSH), régime pauvre en iode, séjour « quelques jours » en chambre protégée : confirmer le vocabulaire et la durée habituelle.",
            "« Les contacts brefs restent possibles » : à valider (formulation à adapter si vos consignes sont plus strictes).",
            "« Environ huit jours » : demi-vie physique de l'iode 131 (≈ 8 jours) ; ne pas la confondre avec la durée de restriction.",
            "Grossesse/allaitement : formulation prudente sans durée chiffrée de contraception ; faut-il indiquer une durée ?",
            "Aucune durée de restriction n'est chiffrée dans la fiche : souhaitez-vous afficher des exemples tirés du calculateur ?",
        ],
    ],
    [
        'slug' => 'lutetium-177-psma',
        'status' => 'draft',
        'title' => "Lutétium 177 PSMA (Pluvicto) : consignes pour le patient et l'entourage après un traitement",
        'h1' => "Traitement par lutétium 177 PSMA (Pluvicto) : ce qu'il faut savoir pour le patient et l'entourage",
        'description' => "Traitement par lutétium 177 PSMA (Pluvicto) d'un cancer de la prostate métastatique : déroulement, effets à connaître, consignes de radioprotection pour l'entourage, contraception et lien vers le RCP.",
        'lead' => "Le lutétium 177 PSMA (nom commercial <strong>Pluvicto</strong>) est un médicament de radiothérapie interne utilisé dans certains cancers de la prostate. Il associe une molécule qui reconnaît une protéine présente à la surface des cellules du cancer de la prostate (le <strong>PSMA</strong>) à un atome radioactif, le <strong>lutétium 177</strong> : le rayonnement est ainsi délivré directement au contact des cellules visées.",
        'blocks' => [
            ['h' => "Pour quelle situation ce traitement est-il proposé ?", 'level' => 2, 'p' => [
                "Selon le résumé des caractéristiques du produit (RCP), Pluvicto est indiqué, en association avec une suppression androgénique, chez l'adulte atteint d'un <strong>cancer de la prostate métastatique, résistant à la castration, progressif, « PSMA positif »</strong>, déjà traité par une hormonothérapie inhibitrice de la voie des androgènes et par une chimiothérapie à base de taxane. Avant le traitement, une <strong>imagerie spécifique du PSMA</strong> (TEP-PSMA) vérifie que les lésions fixent bien le produit.",
            ]],
            ['h' => "Comment se passe le traitement ?", 'level' => 2, 'p' => [
                "Le médicament est administré par <strong>injection dans une veine</strong>, dans un service de médecine nucléaire. Le schéma recommandé est <strong>une injection environ toutes les 6 semaines, jusqu'à 6 injections au plus</strong>, sauf aggravation de la maladie ou mauvaise tolérance ; votre équipe adapte ce schéma à votre situation.",
                "Des <strong>prises de sang</strong> (cellules du sang, reins, foie) sont faites avant et pendant le traitement. Il est conseillé de <strong>boire davantage</strong> et d'<strong>uriner aussi souvent que possible</strong> : cela réduit l'exposition de la vessie au rayonnement.",
            ]],
            ['h' => "Effets à connaître", 'level' => 3, 'p' => [
                "Les effets les plus fréquents sont la <strong>fatigue</strong>, la <strong>sécheresse de la bouche</strong>, les <strong>nausées</strong>, la <strong>baisse des globules rouges (anémie)</strong>, la baisse de l'appétit et la constipation. Le traitement peut aussi faire baisser les autres cellules du sang et peser sur le fonctionnement des reins, d'où la surveillance régulière. Signalez à votre équipe toute fatigue inhabituelle, pâleur, essoufflement ou signe d'infection.",
            ]],
            ['h' => "Après l'injection, à la maison", 'level' => 2, 'p' => [
                "Avant votre sortie, l'équipe de médecine nucléaire vous explique les précautions à suivre. À titre indicatif, le RCP mentionne, après chaque administration, des recommandations générales que les équipes peuvent adapter selon la réglementation et leurs procédures :",
            ], 'ul' => [
                "limiter les contacts proches (à moins d'un mètre) avec les autres personnes pendant <strong>2 jours</strong>, et avec les <strong>enfants</strong> et les <strong>femmes enceintes</strong> pendant <strong>7 jours</strong> ;",
                "ne pas avoir d'activité sexuelle pendant <strong>7 jours</strong> ;",
                "dormir dans une pièce séparée de celle des autres personnes pendant <strong>3 jours</strong>, des enfants pendant <strong>7 jours</strong>, des femmes enceintes pendant <strong>15 jours</strong>.",
            ]],
            ['h' => "Pourquoi ces précautions ?", 'level' => 2, 'p' => [
                "Les consignes sont des mesures de précaution, pas le signe d'un danger : le rayonnement diminue de jour en jour, car le lutétium 177 perd naturellement la moitié de sa radioactivité en <strong>environ 6,6 jours</strong> et le corps l'élimine, surtout par les urines. Pour comprendre la justification de ces consignes et la notion de durée de restriction, consultez la page <a href=\"/informations\">Informations pour le patient et l'entourage</a>.",
            ]],
            ['h' => "Contraception, fertilité et déplacements", 'level' => 2, 'p' => [], 'ul' => [
                "<strong>Contraception</strong> : le RCP conseille de <strong>ne pas concevoir d'enfant et d'utiliser un préservatif</strong> pendant le traitement et pendant <strong>14 semaines après la dernière injection</strong>. Prévenez immédiatement votre équipe si une grossesse survient pendant cette période.",
                "<strong>Fertilité</strong> : le traitement peut provoquer une infertilité. Si vous souhaitez avoir des enfants plus tard, parlez-en à l'équipe <strong>avant de commencer</strong> : une conservation du sperme peut être envisagée.",
                "<strong>Déplacements</strong> : pendant un certain temps, les portiques de détection (aéroports, bâtiments sécurisés) peuvent signaler une radioactivité résiduelle. Gardez sur vous le document remis par le service.",
                "<strong>Gestes d'hygiène</strong> : l'équipe vous remet des consignes pratiques pour le quotidien (hydratation, hygiène, linge, déchets), à suivre en plus des durées ci-dessus.",
            ]],
        ],
        'faq' => [
            ['q' => "Combien de séances de lutétium 177 PSMA faut-il ?", 'a' => "Le schéma recommandé est une injection environ toutes les 6 semaines, jusqu'à 6 injections au plus, selon l'évolution de la maladie et la tolérance. Votre équipe décide au cas par cas."],
            ['q' => "Puis-je prendre mes petits-enfants dans mes bras après l'injection ?", 'a' => "Les recommandations du RCP prévoient de limiter les contacts proches avec les enfants pendant 7 jours après chaque injection. Suivez les consignes de votre équipe, qui peut les adapter."],
            ['q' => "Les consignes sont-elles les mêmes à chaque injection ?", 'a' => "Elles s'appliquent après chaque administration. L'équipe de médecine nucléaire peut les adapter, notamment en fonction de la mesure du rayonnement à votre sortie."],
            ['q' => "Puis-je reprendre le travail ?", 'a' => "Cela dépend des consignes qui vous sont indiquées et de la nature de votre travail (contact étroit avec des enfants ou des femmes enceintes, par exemple). Demandez à l'équipe, qui peut vous remettre un document pour votre employeur."],
        ],
        'links' => [
            ['label' => "Résumé des caractéristiques du produit (RCP) de Pluvicto en français (PDF, Agence européenne des médicaments)", 'url' => 'https://www.ema.europa.eu/fr/documents/product-information/pluvicto-epar-product-information_fr.pdf'],
            ['label' => "Fiche européenne de Pluvicto (EMA), avec notice et RCP dans toutes les langues", 'url' => 'https://www.ema.europa.eu/fr/medicines/human/EPAR/pluvicto'],
        ],
        'review' => [
            "Les durées du paragraphe « Après l'injection » (2, 7, 3, 7 et 15 jours) et la contraception (14 semaines) sont reprises du RCP français (rubriques 4.2 et 4.6, version consultée sur le site de l'EMA). Le RCP les présente comme des recommandations « pouvant être envisagées » selon les procédures nationales : confirmer qu'elles correspondent aux consignes de votre service, ou adapter.",
            "Indication (rubrique 4.1) : reprise du libellé du RCP actuel ; elle peut évoluer (le texte renvoie au RCP pour la version à jour).",
            "Les deux liens (PDF du RCP et fiche EMA) ont été ouverts avec succès. Ajouter, si vous le souhaitez, la fiche de la base de données publique des médicaments (ANSM), dont l'adresse exacte n'a pas été vérifiée.",
            "Effets indésirables : seuls les plus fréquents du RCP sont cités (fatigue, sécheresse buccale, nausées, anémie, baisse d'appétit, constipation) ; faut-il en citer d'autres ?",
            "« Environ 6,6 jours » : demi-vie physique du lutétium 177 (RCP : 6,647 jours) ; ne pas la confondre avec les durées de restriction.",
            "Le RCP mentionne dans le guide patient l'utilisation des toilettes, la douche, la lessive et l'élimination des déchets ; la fiche les évoque sans les détailler (conformément à votre souhait de ne pas détailler certaines consignes).",
            "Les durées de restriction personnalisées (calculateur) ne figurent pas ici : souhaitez-vous des exemples chiffrés ?",
        ],
    ],
];
