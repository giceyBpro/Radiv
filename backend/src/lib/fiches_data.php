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
        'slug' => 'iode-131-hyperthyroidie',
        'status' => 'draft',
        'title' => "Iode 131 et hyperthyroïdie : consignes pour le patient et l'entourage après un traitement",
        'h1' => "Traitement d'une hyperthyroïdie par iode 131 : ce qu'il faut savoir pour le patient et l'entourage",
        'description' => "Traitement d'une hyperthyroïdie (maladie de Basedow, nodule toxique, goitre) par iode 131 : déroulement, effet attendu, consignes de radioprotection pour l'entourage et risque de contamination.",
        'lead' => "L'iode 131 (I-131) est un iode radioactif. La thyroïde est la seule glande à capter l'iode : le produit se fixe donc presque uniquement sur elle, ce qui permet d'en réduire l'activité de façon ciblée. Cette page concerne le traitement de <strong>l'hyperthyroïdie</strong>.",
        'blocks' => [
            ['h' => "Pourquoi un traitement par iode 131 ?", 'level' => 2, 'p' => [
                "L'hyperthyroïdie est un fonctionnement trop actif de la thyroïde (maladie de Basedow, nodule « toxique », goitre multinodulaire toxique). L'iode 131 réduit l'activité de la glande : le rayonnement agit sur une très courte distance autour du produit, ce qui épargne en grande partie les organes voisins. Il est proposé notamment lorsque les médicaments ne suffisent pas ou ne peuvent pas être poursuivis.",
            ]],
            ['h' => "Comment se passe le traitement ?", 'level' => 2, 'p' => [
                "Le traitement consiste le plus souvent en <strong>une prise unique par la bouche</strong> (gélule). Selon l'activité administrée et l'organisation du service, il se fait le plus souvent <strong>sans hospitalisation</strong> : vous rentrez chez vous le jour même avec des consignes précises.",
                "L'effet est <strong>progressif</strong> : il n'apparaît qu'au bout de plusieurs semaines, avec un suivi par prises de sang. Si l'hyperthyroïdie persiste, le traitement peut être renouvelé plusieurs mois plus tard. Après le traitement, la thyroïde peut devenir insuffisante ; un traitement par hormone thyroïdienne est alors prescrit, ce qui est fréquent et bien maîtrisé.",
            ]],
            ['h' => "Avant le traitement", 'level' => 3, 'p' => [
                "L'équipe vous indique si certains médicaments (notamment les antithyroïdiens) doivent être arrêtés quelque temps avant la prise, puis repris. Elle peut aussi, selon votre situation, vous demander de suivre un <strong>régime pauvre en iode</strong>.",
                "<strong>Examens avec produit de contraste iodé</strong> : ne réalisez pas d'examen avec injection de produit de contraste iodé (certains scanners, par exemple) avant le traitement, sans en avoir parlé à l'équipe : l'iode du produit de contraste empêche la thyroïde de fixer l'iode radioactif et réduit l'efficacité du traitement. Signalez aussi tout médicament ou produit contenant de l'iode.",
            ]],
            ['h' => "Après la prise, à la maison", 'level' => 2, 'p' => [
                "Pendant les jours qui suivent, votre corps émet un faible rayonnement et élimine l'iode, surtout par les urines, mais aussi par la salive et la sueur. L'équipe de médecine nucléaire vous indique <strong>une durée de restriction de contact</strong>, adaptée à votre situation et à votre entourage (conjoint, enfants, femme enceinte, travail).",
            ], 'ul' => [
                "garder de la distance avec les autres personnes, surtout les <strong>enfants</strong> et les <strong>femmes enceintes</strong> ;",
                "dormir séparément pendant la durée indiquée ;",
                "limiter les contacts rapprochés prolongés (les contacts brefs restent possibles) ;",
                "boire régulièrement et uriner souvent, comme l'équipe vous le conseille.",
            ]],
            ['h' => "Autres points à connaître", 'level' => 2, 'p' => [], 'ul' => [
                "<strong>Contamination</strong> : l'iode éliminé par l'organisme (urine, salive, sueur) peut se retrouver sur la peau, les objets et les sanitaires. Les gestes d'hygiène indiqués par l'équipe (se laver soigneusement les mains, ne pas partager les couverts au cours du repas…) s'ajoutent à la durée de restriction.",
                "<strong>Hydratation et glandes salivaires</strong> : il est conseillé de boire beaucoup et d'uriner souvent. L'équipe peut aussi vous conseiller de sucer des bonbons acides ou de boire des boissons au citron, pour protéger les glandes salivaires.",
                "<strong>Grossesse, contraception et allaitement</strong> : le traitement est contre-indiqué en cas de grossesse (la thyroïde du fœtus capte l'iode). Une contraception efficace est à maintenir après le traitement, pour les femmes comme pour les hommes ; l'équipe vous indique la durée et le délai conseillé avant de concevoir. L'allaitement ne doit pas être repris. Les contacts avec les jeunes enfants, y compris entre une mère et son enfant, suivent les consignes de restriction (« éviction ») données par l'équipe.",
                "<strong>Détections de radioactivité</strong> : pendant un certain temps, les portiques de détection (aéroports, déchetteries, bâtiments sécurisés) peuvent signaler une radioactivité résiduelle. N'hésitez pas à demander à l'équipe une attestation si besoin.",
                "<strong>Suivi</strong> : des consultations et des prises de sang sont programmées pour vérifier l'efficacité du traitement.",
            ]],
            ['h' => "Pourquoi ces précautions ?", 'level' => 2, 'p' => [
                "Les consignes sont des mesures de précaution, pas le signe d'un danger : le rayonnement diminue de jour en jour, car l'iode 131 perd naturellement la moitié de sa radioactivité en <strong>environ huit jours</strong> et le corps l'élimine. Pour comprendre la justification de ces consignes et la notion de durée de restriction, consultez la page <a href=\"/informations\">Informations pour le patient et l'entourage</a>.",
            ]],
        ],
        'faq' => [
            ['q' => "Puis-je prendre mes enfants dans mes bras après le traitement ?", 'a' => "Les contacts brefs sont en général possibles ; ce sont les contacts rapprochés et prolongés qui sont limités, surtout avec les jeunes enfants. Suivez la durée de restriction donnée par votre équipe."],
            ['q' => "Pourquoi l'effet du traitement n'est-il pas immédiat ?", 'a' => "Le rayonnement réduit progressivement l'activité de la thyroïde : l'effet se constate au bout de plusieurs semaines, avec un suivi par prises de sang. Votre équipe vous indique quand le contrôler."],
            ['q' => "Quand pourrai-je reprendre le travail ?", 'a' => "Cela dépend de la durée de restriction qui vous est indiquée et de la nature de votre travail (contact étroit avec des enfants ou des femmes enceintes, par exemple). Demandez à l'équipe, qui peut vous remettre un document pour votre employeur."],
            ['q' => "La durée donnée est-elle la même pour tout le monde ?", 'a' => "Non : elle dépend du produit, de l'activité administrée et de la situation des personnes qui vous entourent. C'est l'équipe de médecine nucléaire qui la détermine."],
        ],
        'calc' => [
            ['code' => 'iode131_benin', 'label' => "iode 131, hyperthyroïdie (affection bénigne)"],
        ],
        'links' => [
            ['label' => "Résumé des caractéristiques du produit (RCP) de l'iodure (131I) de sodium pour thérapie (gélule), base de données publique des médicaments", 'url' => 'https://base-donnees-publique.medicaments.gouv.fr/medicament/67041506/extrait'],
        ],
        'review' => [
            "Fiche issue de la séparation de la fiche « iode 131 » (hyperthyroïdie / cancer). Vérifié avec le RCP de l'iodure (131I) de sodium pour thérapie, gélule (Curiumpharma, base de données publique des médicaments) : indications (maladie de Basedow, goitre multinodulaire toxique, nodules autonomes), effet après plusieurs semaines, traitement renouvelable, arrêt des antithyroïdiens avant la prise, régime pauvre en iode recommandé (ici « selon votre situation »), produits de contraste iodés (le RCP indique 6 à 8 semaines pour les produits hydrosolubles), grossesse, contraception, allaitement.",
            "Non repris du RCP, donc à confirmer selon votre pratique : retour à domicile le jour même, sans hospitalisation ; « les contacts brefs restent possibles ».",
            "Les durées (restriction de contact, contraception, contacts mère-enfant) du RCP ne sont volontairement pas reprises : elles sont données par l'équipe (calcul RadIV).",
            "Calculateur : renvoi vers l'accueil avec l'isotope « Iode-131-Bénin » présélectionné. Délai après produit de contraste iodé : sans chiffre dans la fiche (RCP : 6 à 8 semaines pour les produits hydrosolubles) : à laisser tel quel ou à préciser ?",
            "Le RCP vérifié concerne la gélule Curiumpharma ; d'autres présentations d'iode 131 peuvent avoir des textes légèrement différents.",
        ],
    ],
    [
        'slug' => 'iode-131-cancer-thyroide',
        'status' => 'draft',
        'title' => "Iode 131 et cancer de la thyroïde : consignes pour le patient et l'entourage après un traitement",
        'h1' => "Traitement d'un cancer de la thyroïde par iode 131 : ce qu'il faut savoir pour le patient et l'entourage",
        'description' => "Traitement d'un cancer différencié de la thyroïde par iode 131 après chirurgie : préparation, séjour en chambre protégée, consignes de radioprotection pour l'entourage et risque de contamination.",
        'lead' => "L'iode 131 (I-131) est un iode radioactif. La thyroïde est la seule glande à capter l'iode : le produit se fixe donc presque uniquement sur le tissu thyroïdien, ce qui permet de le détruire de façon ciblée. Cette page concerne le traitement du <strong>cancer de la thyroïde</strong>.",
        'blocks' => [
            ['h' => "Pourquoi un traitement par iode 131 ?", 'level' => 2, 'p' => [
                "Après l'ablation de la thyroïde pour un cancer dit « différencié » (papillaire ou folliculaire), l'iode 131 détruit les petits fragments de tissu thyroïdien qui peuvent rester. Il permet aussi de rechercher, par une image réalisée quelques jours plus tard, d'éventuelles cellules thyroïdiennes ailleurs dans le corps.",
            ]],
            ['h' => "Préparation du traitement", 'level' => 2, 'p' => [
                "Le traitement est <strong>préparé</strong> par l'équipe : pour que la thyroïde (ou les cellules restantes) capte bien l'iode, l'hormone thyroïdienne est arrêtée temporairement, ou remplacée par des injections qui stimulent la glande ; elle est reprise peu après le traitement. Selon votre situation, l'équipe peut aussi vous demander de suivre un <strong>régime pauvre en iode</strong> pendant une période définie.",
                "<strong>Examens avec produit de contraste iodé</strong> : ne réalisez pas d'examen avec injection de produit de contraste iodé (certains scanners, par exemple) avant le traitement, sans en avoir parlé à l'équipe : l'iode du produit de contraste empêche la thyroïde de fixer l'iode radioactif et réduit l'efficacité du traitement. Signalez aussi tout médicament ou produit contenant de l'iode.",
            ]],
            ['h' => "Le séjour dans le service", 'level' => 2, 'p' => [
                "Le traitement consiste le plus souvent en <strong>une prise unique par la bouche</strong> (gélule). L'activité d'iode administrée est <strong>élevée</strong> : vous séjournez donc en général <strong>quelques jours dans une chambre spécialement protégée</strong> du service de médecine nucléaire. Les visites sont limitées, et vous sortez lorsque le rayonnement mesuré est redescendu à un niveau permettant le retour à domicile.",
            ]],
            ['h' => "Après le séjour, à la maison", 'level' => 2, 'p' => [
                "Des consignes de radioprotection s'appliquent encore à la sortie. L'équipe de médecine nucléaire vous indique <strong>une durée de restriction de contact</strong>, qui dépend de la mesure effectuée avant votre départ et de votre situation familiale et professionnelle (conjoint, enfants, femme enceinte, travail).",
            ], 'ul' => [
                "garder de la distance avec les autres personnes, surtout les <strong>enfants</strong> et les <strong>femmes enceintes</strong> ;",
                "dormir séparément pendant la durée indiquée ;",
                "limiter les contacts rapprochés prolongés (les contacts brefs restent possibles) ;",
                "boire régulièrement et uriner souvent, comme l'équipe vous le conseille.",
            ]],
            ['h' => "Autres points à connaître", 'level' => 2, 'p' => [], 'ul' => [
                "<strong>Contamination</strong> : l'iode éliminé par l'organisme (urine, salive, sueur) peut se retrouver sur la peau, les objets et les sanitaires. Les gestes d'hygiène indiqués par l'équipe (se laver soigneusement les mains, ne pas partager les couverts au cours du repas…) s'ajoutent à la durée de restriction.",
                "<strong>Hydratation et glandes salivaires</strong> : il est conseillé de boire beaucoup et d'uriner souvent. L'équipe peut aussi vous conseiller de sucer des bonbons acides ou de boire des boissons au citron, pour protéger les glandes salivaires.",
                "<strong>Grossesse, contraception et allaitement</strong> : le traitement est contre-indiqué en cas de grossesse (la thyroïde du fœtus capte l'iode). Une contraception efficace est à maintenir après le traitement, pour les femmes comme pour les hommes ; l'équipe vous indique la durée et le délai conseillé avant de concevoir. L'allaitement ne doit pas être repris. Les contacts avec les jeunes enfants, y compris entre une mère et son enfant, suivent les consignes de restriction (« éviction ») données par l'équipe.",
                "<strong>Détections de radioactivité</strong> : pendant un certain temps, les portiques de détection (aéroports, déchetteries, bâtiments sécurisés) peuvent signaler une radioactivité résiduelle. N'hésitez pas à demander à l'équipe une attestation si besoin.",
                "<strong>Suivi</strong> : une image est réalisée quelques jours après la prise, puis des consultations et des examens sont programmés pour vérifier l'efficacité du traitement.",
            ]],
            ['h' => "Pourquoi ces précautions ?", 'level' => 2, 'p' => [
                "Les consignes sont des mesures de précaution, pas le signe d'un danger : le rayonnement diminue de jour en jour, car l'iode 131 perd naturellement la moitié de sa radioactivité en <strong>environ huit jours</strong> et le corps l'élimine. Pour comprendre la justification de ces consignes et la notion de durée de restriction, consultez la page <a href=\"/informations\">Informations pour le patient et l'entourage</a>.",
            ]],
        ],
        'faq' => [
            ['q' => "Pourquoi faut-il rester quelques jours dans une chambre protégée ?", 'a' => "L'activité d'iode administrée pour un cancer est plus élevée que pour une hyperthyroïdie : le séjour permet de protéger l'entourage pendant la période où le rayonnement est le plus important. Vous sortez lorsque la mesure du rayonnement le permet."],
            ['q' => "Puis-je prendre mes enfants dans mes bras après le traitement ?", 'a' => "Les contacts brefs sont en général possibles ; ce sont les contacts rapprochés et prolongés qui sont limités, surtout avec les jeunes enfants. Suivez la durée de restriction donnée par votre équipe."],
            ['q' => "Quand pourrai-je reprendre le travail ?", 'a' => "Cela dépend de la durée de restriction qui vous est indiquée et de la nature de votre travail (contact étroit avec des enfants ou des femmes enceintes, par exemple). Demandez à l'équipe, qui peut vous remettre un document pour votre employeur."],
            ['q' => "La durée donnée est-elle la même pour tout le monde ?", 'a' => "Non : elle dépend du produit, de la mesure du rayonnement à votre sortie et de la situation des personnes qui vous entourent. C'est l'équipe de médecine nucléaire qui la détermine."],
        ],
        'calc' => [
            ['code' => 'iode131_0_fixation', 'label' => "iode 131, cancer de la thyroïde"],
        ],
        'links' => [
            ['label' => "Résumé des caractéristiques du produit (RCP) de l'iodure (131I) de sodium pour thérapie (gélule), base de données publique des médicaments", 'url' => 'https://base-donnees-publique.medicaments.gouv.fr/medicament/67041506/extrait'],
        ],
        'review' => [
            "Fiche issue de la séparation de la fiche « iode 131 » (hyperthyroïdie / cancer). Vérifié avec le RCP de l'iodure (131I) de sodium pour thérapie, gélule (Curiumpharma, base de données publique des médicaments) : indication (carcinome thyroïdien vésiculaire et/ou papillaire, y compris métastases), arrêt puis reprise de l'hormone thyroïdienne, régime pauvre en iode recommandé (ici « selon votre situation »), produits de contraste iodés, hydratation, glandes salivaires, grossesse, contraception, allaitement.",
            "Non repris du RCP, donc à confirmer selon votre pratique : séjour de quelques jours en chambre protégée ; stimulation par injections (rhTSH) ; image quelques jours après la prise ; « les contacts brefs restent possibles ».",
            "Les durées (restriction de contact, contraception, contacts mère-enfant) du RCP ne sont volontairement pas reprises : elles sont données par l'équipe (calcul RadIV).",
            "Calculateur : renvoi vers l'accueil avec l'isotope « Iode-131-0 % fixation » présélectionné (néoplasie). Délai après produit de contraste iodé : sans chiffre dans la fiche (RCP : 6 à 8 semaines pour les produits hydrosolubles).",
            "Le RCP vérifié concerne la gélule Curiumpharma ; d'autres présentations d'iode 131 peuvent avoir des textes légèrement différents.",
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
                "Après l'injection, votre corps émet pendant quelques jours un faible rayonnement et élimine le produit, surtout par les urines. Avant votre sortie, l'équipe de médecine nucléaire vous explique les précautions à suivre et vous indique <strong>une durée de restriction de contact</strong>. Cette durée est <strong>calculée pour vous</strong> à partir de la mesure du rayonnement à votre sortie et de la situation de votre entourage ; elle n'est donc pas la même pour tous, ni forcément d'une injection à l'autre.",
            ], 'ul' => [
                "garder de la distance avec les autres personnes, surtout les <strong>enfants</strong> et les <strong>femmes enceintes</strong>, pour qui les durées sont plus longues ;",
                "limiter les contacts rapprochés prolongés pendant la durée indiquée, et dormir séparément si l'équipe vous le demande ;",
                "suivre les consignes de l'équipe concernant la vie intime et les gestes d'hygiène du quotidien.",
            ]],
            ['h' => "Pourquoi ces précautions ?", 'level' => 2, 'p' => [
                "Les consignes sont des mesures de précaution, pas le signe d'un danger : le rayonnement diminue de jour en jour, car le lutétium 177 perd naturellement la moitié de sa radioactivité en <strong>environ 6,6 jours</strong> et le corps l'élimine, surtout par les urines. Pour comprendre la justification de ces consignes et la notion de durée de restriction, consultez la page <a href=\"/informations\">Informations pour le patient et l'entourage</a>.",
            ]],
            ['h' => "Contraception, vie intime et déplacements", 'level' => 2, 'p' => [], 'ul' => [
                "<strong>Contraception</strong> : ce traitement peut avoir un effet sur un éventuel enfant à naître. L'équipe vous précise, avant le début du traitement, les mesures à prendre pour la contraception pendant le traitement et après.",
                "<strong>Vie intime</strong> : l'équipe vous donne les consignes qui vous concernent. N'hésitez pas à lui poser vos questions, elles sont courantes.",
                "<strong>Détections de radioactivité</strong> : pendant un certain temps, les portiques de détection (aéroports, déchetteries, bâtiments sécurisés) peuvent signaler une radioactivité résiduelle. N'hésitez pas à demander à l'équipe une attestation si besoin.",
            ]],
        ],
        'faq' => [
            ['q' => "Combien de séances de lutétium 177 PSMA faut-il ?", 'a' => "Le schéma recommandé est une injection environ toutes les 6 semaines, jusqu'à 6 injections au plus, selon l'évolution de la maladie et la tolérance. Votre équipe décide au cas par cas."],
            ['q' => "Puis-je prendre mes petits-enfants dans mes bras après l'injection ?", 'a' => "Les contacts rapprochés avec les enfants sont limités plus longtemps qu'avec les adultes : la durée vous est indiquée par l'équipe après chaque injection. Suivez ses consignes."],
            ['q' => "Les consignes sont-elles les mêmes à chaque injection ?", 'a' => "Pas forcément : elles s'appliquent après chaque injection, et la durée est recalculée par l'équipe de médecine nucléaire à partir de la mesure du rayonnement à votre sortie."],
            ['q' => "Puis-je reprendre le travail ?", 'a' => "Cela dépend des consignes qui vous sont indiquées et de la nature de votre travail (contact étroit avec des enfants ou des femmes enceintes, par exemple). Demandez à l'équipe, qui peut vous remettre un document pour votre employeur."],
        ],
        'calc' => [['code' => 'psma_177lu', 'label' => "lutétium 177 PSMA (Pluvicto)"]],
        'links' => [
            ['label' => "Résumé des caractéristiques du produit (RCP) de Pluvicto en français (PDF, Agence européenne des médicaments)", 'url' => 'https://www.ema.europa.eu/fr/documents/product-information/pluvicto-epar-product-information_fr.pdf'],
            ['label' => "Fiche européenne de Pluvicto (EMA), avec notice et RCP dans toutes les langues", 'url' => 'https://www.ema.europa.eu/fr/medicines/human/EPAR/pluvicto'],
        ],
        'review' => [
            "Les durées de restriction (contacts, sommeil, vie intime) et les durées de contraception du RCP ne sont volontairement pas reprises : elles sont calculées par RadIV / données par l'équipe. La fiche renvoie aux consignes de l'équipe.",
            "Contenu vérifié avec le RCP français de l'EMA : indication (rubrique 4.1), schéma (6 injections au plus, environ toutes les 6 semaines), hydratation et mictions fréquentes, effets les plus fréquents, demi-vie du lutétium 177 (6,647 jours).",
            "Les deux liens (PDF du RCP et fiche EMA) ont été ouverts avec succès. La fiche de la base de données publique des médicaments n'a pas été ajoutée : voulez-vous que je la cherche ?",
            "Contraception, vie intime : formulation volontairement vague, avec renvoi à l'équipe.",
            "Effets indésirables : seuls les plus fréquents du RCP sont cités (fatigue, sécheresse buccale, nausées, anémie, baisse d'appétit, constipation) ; faut-il en citer d'autres ?",
        ],
    ],
    [
        'slug' => 'lutetium-177-dotatate',
        'status' => 'draft',
        'title' => "Lutétium 177 DOTATATE (Lutathera) : consignes pour le patient et l'entourage après un traitement",
        'h1' => "Traitement par lutétium 177 DOTATATE (Lutathera) : ce qu'il faut savoir pour le patient et l'entourage",
        'description' => "Traitement par lutétium 177 DOTATATE (Lutathera) d'une tumeur neuroendocrine : déroulement, effets à connaître, consignes de radioprotection pour l'entourage, contraception et lien vers le RCP.",
        'lead' => "Le lutétium 177 DOTATATE (nom commercial <strong>Lutathera</strong>) est un médicament de radiothérapie interne utilisé dans certaines tumeurs neuroendocrines. Il associe une molécule qui se fixe sur des <strong>récepteurs de la somatostatine</strong>, présents à la surface des cellules de ces tumeurs, à un atome radioactif, le <strong>lutétium 177</strong> : le rayonnement est ainsi délivré directement au contact des cellules visées.",
        'blocks' => [
            ['h' => "Pour quelle situation ce traitement est-il proposé ?", 'level' => 2, 'p' => [
                "Selon le résumé des caractéristiques du produit (RCP), Lutathera est indiqué chez l'adulte atteint d'une <strong>tumeur neuroendocrine gastro-entéro-pancréatique</strong> (qui naît dans le tube digestif ou le pancréas), <strong>inopérable ou métastatique, progressive, bien différenciée</strong> et exprimant des récepteurs de la somatostatine. Avant le traitement, une <strong>imagerie des récepteurs de la somatostatine</strong> vérifie que la tumeur fixe bien le produit.",
            ]],
            ['h' => "Comment se passe le traitement ?", 'level' => 2, 'p' => [
                "Le médicament est administré par <strong>perfusion dans une veine</strong>, dans un service de médecine nucléaire. Le schéma recommandé est de <strong>4 perfusions, espacées d'environ 8 semaines</strong> ; votre équipe l'adapte à votre situation et à votre tolérance.",
                "Pour <strong>protéger les reins</strong>, une solution d'acides aminés est perfusée en même temps. Un médicament contre les nausées est donné avant la perfusion. Des <strong>prises de sang</strong> (cellules du sang, reins, foie) sont faites avant chaque perfusion, et la fonction des reins reste surveillée après la fin du traitement.",
                "Pendant la perfusion, vous êtes <strong>tenu à l'écart de votre entourage</strong>. Vous ne quittez le service que lorsque le rayonnement mesuré est redescendu à un niveau réglementaire. Dans certains cas, l'équipe peut proposer de rester une nuit en observation.",
            ]],
            ['h' => "Avant et après la perfusion : ce que l'équipe vous demande", 'level' => 3, 'ul' => [
                "<strong>Boire régulièrement et uriner souvent</strong> avant la perfusion, le jour même et le lendemain, pour faciliter l'élimination du produit ;",
                "aller à la selle chaque jour, avec un laxatif si l'équipe vous le conseille ;",
                "<strong>signaler vos médicaments</strong> : certains traitements (notamment des analogues de la somatostatine ou des corticoïdes) peuvent gêner l'effet de Lutathera ; l'équipe vous dit lesquels adapter, et à quel moment.",
            ], 'p' => []],
            ['h' => "Effets à connaître", 'level' => 3, 'p' => [
                "Les effets les plus fréquents sont les <strong>nausées</strong> et <strong>vomissements</strong> au début de la perfusion (en partie dus à la solution d'acides aminés), la <strong>fatigue</strong>, la <strong>perte d'appétit</strong> et une <strong>baisse des cellules du sang</strong> (plaquettes, globules blancs, globules rouges), d'où les prises de sang régulières. Signalez à l'équipe toute fatigue inhabituelle, pâleur, saignement ou signe d'infection.",
                "Rarement, des complications tardives du sang ont été observées plusieurs années après le traitement ; c'est une des raisons du suivi prolongé.",
            ]],
            ['h' => "Après la perfusion, à la maison", 'level' => 2, 'p' => [
                "Après le traitement, votre corps émet pendant quelques jours un faible rayonnement et élimine le produit, surtout par les urines. Avant votre sortie, l'équipe de médecine nucléaire vous explique les précautions à suivre et vous indique <strong>une durée de restriction de contact</strong>. Cette durée est <strong>calculée pour vous</strong> à partir de la mesure du rayonnement à votre sortie et de la situation de votre entourage ; elle n'est donc pas la même pour tous, ni forcément d'une perfusion à l'autre.",
            ], 'ul' => [
                "garder de la distance avec les autres personnes, surtout les <strong>enfants</strong> et les <strong>femmes enceintes</strong>, pour qui les durées sont plus longues ;",
                "limiter les contacts rapprochés prolongés pendant la durée indiquée, et dormir séparément si l'équipe vous le demande ;",
                "suivre les consignes de l'équipe concernant les gestes d'hygiène du quotidien.",
            ]],
            ['h' => "Pourquoi ces précautions ?", 'level' => 2, 'p' => [
                "Les consignes sont des mesures de précaution, pas le signe d'un danger : le rayonnement diminue de jour en jour, car le lutétium 177 perd naturellement la moitié de sa radioactivité en <strong>environ 6,6 jours</strong> et le corps l'élimine, surtout par les urines. Pour comprendre la justification de ces consignes et la notion de durée de restriction, consultez la page <a href=\"/informations\">Informations pour le patient et l'entourage</a>.",
            ]],
            ['h' => "Grossesse, contraception et déplacements", 'level' => 2, 'p' => [], 'ul' => [
                "<strong>Grossesse et allaitement</strong> : le traitement est contre-indiqué en cas de grossesse, et l'allaitement est à éviter ; si le traitement est nécessaire pendant l'allaitement, l'enfant doit être sevré. Parlez-en à l'équipe avant de commencer.",
                "<strong>Contraception</strong> : le rayonnement peut avoir un effet sur un éventuel enfant à naître. L'équipe vous précise, avant le début du traitement, les mesures à prendre pour la contraception pendant le traitement et après.",
                "<strong>Détections de radioactivité</strong> : pendant un certain temps, les portiques de détection (aéroports, déchetteries, bâtiments sécurisés) peuvent signaler une radioactivité résiduelle. N'hésitez pas à demander à l'équipe une attestation si besoin.",
            ]],
        ],
        'faq' => [
            ['q' => "Combien de perfusions de Lutathera faut-il ?", 'a' => "Le schéma recommandé est de 4 perfusions espacées d'environ 8 semaines, selon l'évolution de la maladie et la tolérance. Votre équipe décide au cas par cas."],
            ['q' => "Pourquoi reçoit-on une perfusion d'acides aminés en même temps ?", 'a' => "Elle protège les reins : le lutétium 177 est éliminé presque uniquement par les reins, et les acides aminés réduisent la quantité de produit qui y reste."],
            ['q' => "Puis-je prendre mes petits-enfants dans mes bras après la perfusion ?", 'a' => "Les contacts rapprochés avec les enfants sont limités plus longtemps qu'avec les adultes : la durée vous est indiquée par l'équipe après chaque perfusion. Suivez ses consignes."],
            ['q' => "Les consignes sont-elles les mêmes à chaque perfusion ?", 'a' => "Pas forcément : elles s'appliquent après chaque perfusion, et la durée est recalculée par l'équipe de médecine nucléaire à partir de la mesure du rayonnement à votre sortie."],
            ['q' => "Puis-je reprendre le travail ?", 'a' => "Cela dépend des consignes qui vous sont indiquées et de la nature de votre travail (contact étroit avec des enfants ou des femmes enceintes, par exemple). Demandez à l'équipe, qui peut vous remettre un document pour votre employeur."],
        ],
        'calc' => [['code' => 'lutetium177_net', 'label' => "lutétium 177 DOTATATE (Lutathera)"]],
        'links' => [
            ['label' => "Résumé des caractéristiques du produit (RCP) de Lutathera en français (PDF, Agence européenne des médicaments)", 'url' => 'https://www.ema.europa.eu/fr/documents/product-information/lutathera-epar-product-information_fr.pdf'],
            ['label' => "Fiche européenne de Lutathera (EMA), avec notice et RCP dans toutes les langues", 'url' => 'https://www.ema.europa.eu/fr/medicines/human/EPAR/lutathera'],
        ],
        'review' => [
            "Contenu vérifié avec le RCP français de l'EMA : indication (4.1, tumeurs neuroendocrines gastro-entéro-pancréatiques), schéma (4 perfusions, environ 8 semaines d'intervalle), acides aminés pour protéger les reins, antiémétique, hydratation et mictions fréquentes, effets les plus fréquents, complications tardives rares du sang, grossesse (contre-indication), allaitement, demi-vie du lutétium 177 (6,647 jours).",
            "Les durées du RCP ne sont volontairement pas reprises : restriction de contact, contraception (le RCP en indique une), durée de la séparation en sortie de service (le RCP parle de 4 à 5 heures) ; la fiche renvoie aux consignes de l'équipe.",
            "« Tenu à l'écart de votre entourage pendant la perfusion » et « une nuit en observation dans certains cas » : reformulation du RCP (règles de radioprotection, crises hormonales) ; à confirmer selon votre pratique.",
            "Médicaments à adapter : le RCP mentionne les analogues de la somatostatine à longue durée d'action et les corticoïdes ; la fiche reste générale, sans délai.",
            "« Complications tardives du sang » (syndrome myélodysplasique, leucémie aiguë) : sujet sensible ; formulation volontairement sobre et rassurante (« rarement… »). À valider ou à retirer.",
            "Les deux liens (PDF du RCP et fiche EMA) ont été ouverts avec succès.",
        ],
    ],
];
