import {
    BedDouble,
    HandCoins,
    Landmark,
    ShieldCheck,
    ShoppingBag,
    Stethoscope,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { Tone } from '@/data/marketing';

/** Which animated app illustration a spotlight shows. */
export type Illustration =
    | 'routing'
    | 'campaign'
    | 'supervision'
    | 'stats'
    | 'softphone'
    | 'meeting';

export type Solution = {
    /** URL segment: /solutions/{slug}. Keep in sync with config/marketing.php. */
    slug: string;
    name: string;
    icon: Component;
    tone: Tone;
    /** One line for the cards of /solutions and the footer. */
    summary: string;
    title: string;
    intro: string;
    pains: { title: string; description: string }[];
    spotlights: {
        eyebrow: string;
        title: string;
        description: string;
        points: string[];
        illustration: Illustration;
    }[];
    scenarios: string[];
};

export const solutions: Solution[] = [
    {
        slug: 'banks',
        name: 'Banques',
        icon: Landmark,
        tone: 'blue',
        summary:
            'Désengorgez les agences, traitez les oppositions de carte sans délai et contrôlez la qualité de chaque échange.',
        title: 'Des clients bien reçus, même quand les agences débordent.',
        intro: "Questions sur un dossier de crédit, opposition de carte, horaires d'agence : vos clients appellent avant de se déplacer. Callcenter oriente chaque appel vers le bon service et donne à vos conseillers les outils d'un vrai centre de relation client.",
        pains: [
            {
                title: 'Des lignes saturées aux heures de pointe',
                description:
                    'Fin de mois, jours de paie : le téléphone sonne dans le vide et le client finit par se déplacer en agence, ou chez un concurrent.',
            },
            {
                title: 'Des demandes urgentes mélangées au reste',
                description:
                    "Une carte perdue ne peut pas attendre derrière une question d'horaires. Sans tri à l'entrée, tout arrive au même endroit.",
            },
            {
                title: 'Peu de visibilité sur la qualité',
                description:
                    "Impossible de savoir combien d'appels ont été perdus, ni comment les conseillers répondent aux clients.",
            },
        ],
        spotlights: [
            {
                eyebrow: 'Accueil téléphonique',
                title: 'Chaque client vers le bon conseiller.',
                description:
                    'Le menu vocal trie les appels dès la première seconde. Les oppositions de carte passent devant, les entreprises ont leur propre file.',
                points: [
                    '« Tapez 1 Particuliers, 2 Entreprises, 3 Opposition carte »',
                    'Horaires par file et message de fermeture adapté',
                    "Débordement vers un poste d'astreinte quand l'attente dure",
                    'Rappel proposé au client plutôt que de raccrocher',
                ],
                illustration: 'routing',
            },
            {
                eyebrow: 'Qualité et conformité',
                title: 'Écoutez, enregistrez, améliorez.',
                description:
                    'Les superviseurs suivent les files en direct, écoutent un appel ou soufflent un conseil. Les enregistrements servent à la formation et aux réclamations.',
                points: [
                    'Écoute silencieuse et chuchotement',
                    'Enregistrement des appels (offres Croissance, Pro et Corporate)',
                    'Accès limité par rôle : agent, superviseur, administrateur',
                    'Niveau de service et satisfaction suivis chaque jour',
                ],
                illustration: 'supervision',
            },
            {
                eyebrow: 'Appels sortants',
                title: 'Relancez vos dossiers sans perdre de temps.',
                description:
                    'Confirmation de rendez-vous, pièces manquantes, enquête après ouverture de compte : la plateforme compose, le conseiller parle.',
                points: [
                    'Import de listes CSV et liste « Ne pas appeler »',
                    'Résultat saisi après chaque appel, rappels planifiés',
                    'Suivi de la campagne en temps réel',
                ],
                illustration: 'campaign',
            },
        ],
        scenarios: [
            'Centre de relation client multi-agences',
            'Ligne dédiée aux oppositions de carte',
            'Suivi des demandes de crédit',
            'Enquête de satisfaction après passage en agence',
        ],
    },
    {
        slug: 'microfinance',
        name: 'Microfinance',
        icon: HandCoins,
        tone: 'seafoam',
        summary:
            "Organisez les rappels d'échéance, gardez une trace de chaque promesse de paiement et suivez toutes vos agences.",
        title: 'Le recouvrement et le suivi des membres, enfin organisés.',
        intro: "Rappels d'échéance, information des membres, suivi des agents de crédit : dans une institution de microfinance, beaucoup se joue au téléphone. Callcenter remplace les téléphones personnels par un outil commun, suivi et mesurable.",
        pains: [
            {
                title: 'Des relances faites depuis des téléphones personnels',
                description:
                    "Les agents appellent depuis leur propre numéro. Aucune trace de l'appel, et l'historique part avec l'agent quand il quitte l'institution.",
            },
            {
                title: 'Des membres difficiles à joindre',
                description:
                    'Numéros éteints, réseau faible, horaires de travail aux champs : sans nouvelle tentative organisée, beaucoup de relances tombent dans le vide.',
            },
            {
                title: 'Des agences qui travaillent chacune de leur côté',
                description:
                    "La direction n'a pas de vue d'ensemble sur l'activité téléphonique des agences ni sur le résultat des relances.",
            },
        ],
        spotlights: [
            {
                eyebrow: "Rappels d'échéance",
                title: 'Des campagnes de relance qui avancent seules.',
                description:
                    "Importez la liste des échéances, choisissez le mode de numérotation : la plateforme compose et ne passe à l'agent que les membres qui décrochent.",
                points: [
                    'Modes aperçu, progressif ou « power »',
                    'Nouvelles tentatives automatiques selon le résultat',
                    'Résultats personnalisés : promesse de paiement, rappel, injoignable',
                    'Plages horaires respectées pour chaque campagne',
                ],
                illustration: 'campaign',
            },
            {
                eyebrow: 'Toutes les agences',
                title: 'Un seul outil, de Bamako aux agences régionales.',
                description:
                    "Chaque agent se connecte depuis le navigateur de son agence. Le siège suit l'activité de tout le réseau sur un seul écran.",
                points: [
                    'Softphone dans le navigateur, rien à installer',
                    'Historique des appels par agent et par période',
                    'Journal des pauses et des états des agents',
                ],
                illustration: 'stats',
            },
        ],
        scenarios: [
            "Rappels d'échéance avant et après la date",
            "Ligne d'information pour les membres",
            'Confirmation des rendez-vous de décaissement',
            'Enquête de satisfaction des membres',
        ],
    },
    {
        slug: 'healthcare',
        name: 'Cliniques et hôpitaux',
        icon: Stethoscope,
        tone: 'fuchsia',
        summary:
            "Libérez l'accueil, orientez les urgences sans attente et réduisez les rendez-vous manqués.",
        title: 'Un accueil téléphonique qui soulage vos équipes soignantes.',
        intro: "Prises de rendez-vous, résultats d'analyses, questions des familles : le standard d'une clinique ne s'arrête jamais. Callcenter trie les appels, garde la ligne d'urgence libre et rappelle les patients à votre place.",
        pains: [
            {
                title: 'Un standard débordé toute la journée',
                description:
                    "Le personnel d'accueil jongle entre les patients présents et le téléphone. Les appels sans réponse se multiplient.",
            },
            {
                title: 'Les urgences noyées dans les appels administratifs',
                description:
                    "Un appel urgent attend derrière une demande d'horaires, faute de tri dès l'entrée.",
            },
            {
                title: 'Des rendez-vous oubliés',
                description:
                    'Les créneaux non honorés font perdre du temps aux médecins et retardent les autres patients.',
            },
        ],
        spotlights: [
            {
                eyebrow: 'Accueil et orientation',
                title: 'Le bon service, sans passer par le standard.',
                description:
                    'Le menu vocal oriente vers les rendez-vous, les résultats ou le service de garde. La nuit et le week-end, les appels basculent automatiquement vers le poste de garde.',
                points: [
                    '« Tapez 1 Rendez-vous, 2 Résultats, 3 Service de garde »',
                    'Horaires par service, jours fériés et garde de nuit',
                    "Débordement vers un poste de secours quand l'attente dure",
                    "Message d'accueil qui rappelle les numéros d'urgence",
                ],
                illustration: 'routing',
            },
            {
                eyebrow: 'Rappels patients',
                title: 'Moins de rendez-vous manqués.',
                description:
                    'La veille, une campagne appelle les patients pour confirmer ou déplacer leur rendez-vous. Après la consultation, un appel de suivi rassure et fidélise.',
                points: [
                    'Liste des rendez-vous importée en CSV',
                    'Résultat noté : confirmé, déplacé, injoignable',
                    'Appels de suivi après hospitalisation',
                ],
                illustration: 'campaign',
            },
        ],
        scenarios: [
            'Standard central de la clinique',
            'Confirmation des rendez-vous la veille',
            "Annonce de la disponibilité des résultats d'analyses",
            'Suivi des patients après une intervention',
        ],
    },
    {
        slug: 'hospitality',
        name: 'Hôtels et restaurants',
        icon: BedDouble,
        tone: 'orange',
        summary:
            'Ne perdez plus une réservation ni une commande, même aux heures de rush, et suivez la satisfaction de vos clients.',
        title: 'Plus aucune réservation perdue au téléphone.',
        intro: 'Quand la réception est occupée ou que la cuisine est en plein service, le téléphone sonne dans le vide. Callcenter fait patienter, oriente et rappelle vos clients, pour un ou plusieurs établissements.',
        pains: [
            {
                title: 'Des appels perdus pendant le service',
                description:
                    "À l'heure du déjeuner ou à l'arrivée d'un groupe, personne ne peut décrocher. Le client réserve ailleurs.",
            },
            {
                title: 'Une clientèle qui parle plusieurs langues',
                description:
                    "Touristes, missions et voyageurs d'affaires : il faut joindre rapidement quelqu'un qui parle leur langue.",
            },
            {
                title: "Peu de retours sur l'expérience client",
                description:
                    'Sans enquête après le séjour ou la commande, les problèmes ne remontent que dans les avis en ligne.',
            },
        ],
        spotlights: [
            {
                eyebrow: 'Réservations et commandes',
                title: 'Chaque appel arrive au bon endroit.',
                description:
                    "Réservation de chambre, table, commande à emporter ou livraison : le menu vocal oriente le client, et l'agent voit sa demande avant de décrocher.",
                points: [
                    '« Tapez 1 Réservation, 2 Commande, 3 Réception »',
                    'Une file pour les clients anglophones',
                    "Rappel proposé quand l'attente devient longue",
                    'Plusieurs établissements sur la même plateforme',
                ],
                illustration: 'routing',
            },
            {
                eyebrow: 'Satisfaction et fidélité',
                title: 'Écoutez vos clients, puis faites-les revenir.',
                description:
                    "Une enquête de satisfaction en fin d'appel, des campagnes de confirmation de réservation et d'offres pour vos clients fidèles.",
                points: [
                    "Enquête de satisfaction après l'appel",
                    'Confirmation des réservations la veille',
                    'Statistiques par file et par période',
                ],
                illustration: 'stats',
            },
        ],
        scenarios: [
            'Centrale de réservation de plusieurs hôtels',
            'Prise de commandes pour la livraison',
            'Confirmation des réservations de groupes',
            'Enquête de satisfaction après le séjour',
        ],
    },
    {
        slug: 'insurance',
        name: 'Assurances',
        icon: ShieldCheck,
        tone: 'purple',
        summary:
            'Accueillez les déclarations de sinistre en priorité et relancez les renouvellements de contrats.',
        title: "Être là au moment où l'assuré en a besoin.",
        intro: 'Un assuré qui déclare un sinistre attend une réponse rapide et humaine. Callcenter donne la priorité à ces appels, garde une trace de chaque échange et automatise les relances de renouvellement.',
        pains: [
            {
                title: 'Des sinistres déclarés dans la précipitation',
                description:
                    "Un accident ou un vol : l'assuré veut parler à quelqu'un tout de suite, pas patienter derrière des demandes de devis.",
            },
            {
                title: 'Des contrats qui expirent sans relance',
                description:
                    'Faute de temps, les renouvellements ne sont pas tous rappelés et les clients partent sans prévenir.',
            },
            {
                title: "Des litiges sans trace de l'échange",
                description:
                    'Sans enregistrement ni historique, difficile de savoir ce qui a été dit au client.',
            },
        ],
        spotlights: [
            {
                eyebrow: 'Sinistres',
                title: 'Une file prioritaire pour les déclarations.',
                description:
                    "Les déclarations de sinistre ont leur propre file et leurs agents. Les superviseurs voient l'attente en direct et renforcent la file si besoin.",
                points: [
                    '« Tapez 1 Déclarer un sinistre, 2 Devis, 3 Mon contrat »',
                    'Enregistrement des appels (offres Croissance, Pro et Corporate)',
                    'Historique filtrable par numéro et par agent',
                ],
                illustration: 'supervision',
            },
            {
                eyebrow: 'Renouvellements',
                title: "Relancez avant l'échéance.",
                description:
                    'Chaque mois, importez les contrats à renouveler : la campagne appelle les assurés et note leur décision.',
                points: [
                    'Résultats personnalisés : renouvelé, à rappeler, résilié',
                    'Liste « Ne pas appeler » respectée',
                    'Suivi du taux de joignabilité',
                ],
                illustration: 'campaign',
            },
        ],
        scenarios: [
            'Ligne de déclaration de sinistres',
            'Relance des renouvellements de contrats',
            'Information sur les garanties',
            "Enquête après règlement d'un sinistre",
        ],
    },
    {
        slug: 'retail',
        name: 'Commerce et e-commerce',
        icon: ShoppingBag,
        tone: 'blue',
        summary:
            'Confirmez les commandes avant livraison, répondez au service après-vente et rappelez les clients.',
        title: 'Moins de livraisons refusées, plus de clients satisfaits.',
        intro: "Quand le paiement se fait à la livraison, une commande non confirmée coûte un trajet pour rien. Callcenter appelle vos clients avant l'envoi et organise votre service après-vente.",
        pains: [
            {
                title: 'Des commandes refusées à la livraison',
                description:
                    "Numéro erroné, client injoignable ou qui a changé d'avis : le livreur revient avec le colis.",
            },
            {
                title: 'Un service après-vente débordé',
                description:
                    'Suivi de commande, échange, réclamation : les appels arrivent tous au même numéro, souvent un téléphone portable.',
            },
            {
                title: 'Aucun chiffre sur les appels',
                description:
                    "Combien d'appels manqués hier ? Combien de commandes confirmées ? Personne ne le sait.",
            },
        ],
        spotlights: [
            {
                eyebrow: 'Confirmation de commandes',
                title: 'Chaque commande confirmée avant le départ.',
                description:
                    "Importez les commandes du jour : la plateforme compose les numéros et l'agent confirme l'adresse et le créneau de livraison.",
                points: [
                    'Mode « power » pour enchaîner les appels',
                    'Résultats : confirmée, reportée, annulée, injoignable',
                    'Nouvelles tentatives automatiques',
                ],
                illustration: 'campaign',
            },
            {
                eyebrow: 'Service après-vente',
                title: 'Un service client joignable et mesuré.',
                description:
                    'Suivi de commande, retours et réclamations ont chacun leur file. Les appels manqués sont rappelés et les statistiques suivies chaque jour.',
                points: [
                    'Menu vocal et files par type de demande',
                    'Rappel proposé au client',
                    'Taux de réponse, attente et durée moyennes',
                ],
                illustration: 'stats',
            },
        ],
        scenarios: [
            'Confirmation des commandes payées à la livraison',
            'Suivi de livraison et réclamations',
            'Relance des paniers abandonnés',
            'Enquête de satisfaction après achat',
        ],
    },
];

export function findSolution(slug: string): Solution | undefined {
    return solutions.find((solution) => solution.slug === slug);
}
