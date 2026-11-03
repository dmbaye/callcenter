import {
    BarChart3,
    CalendarClock,
    FileSpreadsheet,
    Hash,
    Headphones,
    History,
    PhoneMissed,
    ShieldCheck,
    Smartphone,
    Users,
    Voicemail,
    Workflow,
} from '@lucide/vue';
import type { Component } from 'vue';

export type Tone = 'blue' | 'seafoam' | 'orange' | 'purple' | 'fuchsia';

export type Feature = {
    icon: Component;
    title: string;
    description: string;
    tone: Tone;
};

/** Icon tiles in Spectrum categorical colors (light / dark). */
export const toneClasses: Record<Tone, string> = {
    blue: 'bg-[rgb(229_240_254)] text-[rgb(39_77_234)] dark:bg-[rgb(12_33_117)] dark:text-[rgb(172_207_253)]',
    seafoam:
        'bg-[rgb(206_247_235)] text-[rgb(6_113_93)] dark:bg-[rgb(0_60_50)] dark:text-[rgb(111_226_195)]',
    orange: 'bg-[rgb(255_236_207)] text-[rgb(167_62_0)] dark:bg-[rgb(80_27_0)] dark:text-[rgb(255_193_94)]',
    purple: 'bg-[rgb(244_234_255)] text-[rgb(125_58_198)] dark:bg-[rgb(60_20_100)] dark:text-[rgb(214_183_250)]',
    fuchsia:
        'bg-[rgb(252_230_252)] text-[rgb(166_37_172)] dark:bg-[rgb(80_15_85)] dark:text-[rgb(243_175_245)]',
};

/** "Everything else" grid on the landing and features pages. */
export const features: Feature[] = [
    {
        icon: Headphones,
        title: 'Softphone navigateur',
        description:
            'Appels entrants et sortants depuis Chrome ou Firefox en WebRTC. Aucun téléphone de bureau, rien à installer.',
        tone: 'blue',
    },
    {
        icon: Hash,
        title: 'Menus vocaux (SVI)',
        description:
            '« Tapez 1, tapez 2 » : orientez chaque appelant vers la bonne file, un poste ou un message, sans écrire de dialplan.',
        tone: 'purple',
    },
    {
        icon: CalendarClock,
        title: 'Horaires et jours fériés',
        description:
            "Horaires par file, jours de fermeture et fermeture d'urgence en un clic, avec votre propre message.",
        tone: 'seafoam',
    },
    {
        icon: PhoneMissed,
        title: 'Rappel automatique',
        description:
            "Après trop d'attente, l'appelant peut demander à être rappelé : sa demande arrive dans une liste de rappel.",
        tone: 'orange',
    },
    {
        icon: FileSpreadsheet,
        title: 'Import de contacts',
        description:
            'Importez vos listes en CSV, avec liste « Ne pas appeler » et nouvelles tentatives selon le résultat.',
        tone: 'fuchsia',
    },
    {
        icon: Voicemail,
        title: 'Détection de répondeur',
        description:
            "Les campagnes écartent les répondeurs pour que vos agents ne parlent qu'à des personnes.",
        tone: 'blue',
    },
    {
        icon: BarChart3,
        title: 'Statistiques et satisfaction',
        description:
            "Taux de réponse, attente, durée moyenne, enquête de satisfaction en fin d'appel.",
        tone: 'seafoam',
    },
    {
        icon: History,
        title: 'Historique et enregistrements',
        description:
            "Chaque appel retrouvé en une recherche, et réécouté en un clic avec l'enregistrement (offres Croissance, Pro et Corporate).",
        tone: 'purple',
    },
    {
        icon: Users,
        title: 'Équipes et rôles',
        description:
            'Propriétaire, administrateur, superviseur et agent : chacun voit ce qui le concerne.',
        tone: 'orange',
    },
    {
        icon: Workflow,
        title: "Plan d'appel lisible",
        description:
            'Le parcours de vos appels en langage clair. Le mode expert reste disponible pour les techniciens.',
        tone: 'fuchsia',
    },
    {
        icon: Smartphone,
        title: 'Paiement Mobile Money',
        description:
            'Abonnement en francs CFA par Orange Money, Moov Money ou carte bancaire.',
        tone: 'blue',
    },
    {
        icon: ShieldCheck,
        title: 'Sécurité',
        description:
            "Double authentification, clés d'accès (passkeys) et données séparées par entreprise.",
        tone: 'seafoam',
    },
];

/** Getting started, in three steps. */
export const steps = [
    {
        title: 'Créez votre équipe',
        description:
            "Inscrivez-vous et profitez de l'essai gratuit, sans moyen de paiement.",
    },
    {
        title: 'Invitez vos agents',
        description:
            'Chaque agent reçoit son poste et son softphone automatiquement.',
    },
    {
        title: 'Recevez vos appels',
        description:
            'Reliez votre numéro, réglez files, horaires et menu vocal depuis les paramètres.',
    },
];
