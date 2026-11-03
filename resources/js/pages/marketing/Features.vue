<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CampaignIllustration from '@/components/marketing/CampaignIllustration.vue';
import FeatureGrid from '@/components/marketing/FeatureGrid.vue';
import FeatureSpotlight from '@/components/marketing/FeatureSpotlight.vue';
import MeetingIllustration from '@/components/marketing/MeetingIllustration.vue';
import RoutingIllustration from '@/components/marketing/RoutingIllustration.vue';
import SoftphoneIllustration from '@/components/marketing/SoftphoneIllustration.vue';
import StatsIllustration from '@/components/marketing/StatsIllustration.vue';
import SupervisionIllustration from '@/components/marketing/SupervisionIllustration.vue';

const sections = [
    { id: 'softphone', label: 'Softphone' },
    { id: 'entrant', label: 'Appels entrants' },
    { id: 'campagnes', label: 'Campagnes' },
    { id: 'supervision', label: 'Supervision' },
    { id: 'statistiques', label: 'Statistiques' },
    { id: 'video', label: 'Réunions vidéo' },
    { id: 'tout', label: 'Tout le reste' },
];
</script>

<template>
    <Head title="Fonctionnalités — Callcenter" />

    <section class="px-4 pt-14 pb-10 sm:px-6 md:pt-20">
        <div class="mx-auto max-w-7xl">
            <p
                class="mk-pop text-sm font-bold tracking-wide text-primary uppercase"
            >
                Fonctionnalités
            </p>
            <h1
                class="mk-pop mt-3 max-w-4xl text-[2.5rem] leading-[1.05] font-extrabold sm:text-6xl"
            >
                Tout ce qu'il faut pour
                <span class="text-primary">répondre, appeler et piloter.</span>
            </h1>
            <p
                class="mk-pop mt-6 max-w-2xl text-lg text-muted-foreground [animation-delay:80ms] sm:text-xl"
            >
                Chaque fonction poursuit le même but : plus d'appels pris, des
                agents plus sereins, des superviseurs mieux informés.
            </p>
        </div>
    </section>

    <!-- Section shortcuts -->
    <nav
        class="sticky top-16 z-40 border-y bg-background/85 backdrop-blur-md"
        aria-label="Sections de la page"
    >
        <div
            class="mx-auto flex max-w-7xl [scrollbar-width:none] gap-1 overflow-x-auto px-4 py-2 sm:px-6"
        >
            <a
                v-for="section in sections"
                :key="section.id"
                :href="`#${section.id}`"
                class="shrink-0 rounded-full px-3.5 py-1.5 text-sm font-semibold text-muted-foreground transition-colors hover:bg-accent hover:text-heading"
                >{{ section.label }}</a
            >
        </div>
    </nav>

    <FeatureSpotlight
        id="softphone"
        eyebrow="Softphone navigateur"
        title="Un téléphone complet dans chaque onglet."
        description="Vos agents se connectent et prennent les appels depuis Chrome ou Firefox. Le poste est créé automatiquement : aucun réglage SIP, aucun appareil à acheter."
        :points="[
            'Sonnerie et fiche de l\'appelant partout dans l\'application',
            'Sourdine, attente, clavier et transfert',
            'Résultat de l\'appel saisi en fin de conversation',
            'Statut automatique : disponible, en appel, en pause',
        ]"
    >
        <SoftphoneIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="entrant"
        eyebrow="Appels entrants"
        title="Un parcours d'appel clair, réglé sans technicien."
        description="Le plan d'appel s'affiche en langage simple : numéro, horaires, menu vocal, file, débordement. Chaque étape se règle depuis un formulaire."
        :points="[
            'Numéros reliés à une file, un menu vocal ou un poste',
            'Horaires par file, jours fériés et fermeture d\'urgence',
            'Temps d\'attente maximum puis file, poste ou message de secours',
            'Rappel proposé et enquête de satisfaction après l\'appel',
        ]"
        reverse
    >
        <RoutingIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="campagnes"
        eyebrow="Campagnes sortantes"
        title="Des campagnes d'appels qui avancent seules."
        description="Inspiré des grands centres de contacts : la plateforme compose les numéros, écarte les répondeurs et ne passe à l'agent que les personnes en ligne."
        :points="[
            'Aperçu (l\'agent lance l\'appel), progressif ou « power »',
            'Import CSV, liste « Ne pas appeler » et plages horaires',
            'Résultats personnalisés, rappels et nouvelles tentatives',
            'Taux d\'abandon surveillé et ajusté automatiquement',
        ]"
    >
        <CampaignIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="supervision"
        eyebrow="Supervision"
        title="Voir, écouter, conseiller. En direct."
        description="Les superviseurs suivent la file et chaque agent sur un seul écran. Un clic suffit pour écouter un appel ou chuchoter un conseil."
        :points="[
            'File, attente la plus longue et niveau de service',
            'Écoute silencieuse et chuchotement',
            'Pauses avec motif visibles immédiatement',
        ]"
        reverse
    >
        <SupervisionIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="statistiques"
        eyebrow="Statistiques"
        title="Les bons chiffres, au bon moment."
        description="Appels reçus, répondus et manqués, attente et durée moyennes, activité par agent : le tableau de bord se met à jour tout au long de la journée."
        :points="[
            'Historique filtrable par agent, numéro et période',
            'Enregistrements à réécouter (offres Croissance, Pro et Corporate)',
            'Appels manqués à rappeler',
            'Satisfaction client et résultats de campagne',
        ]"
    >
        <StatsIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="video"
        eyebrow="Réunions vidéo"
        title="La visioconférence intégrée, pour toute l'équipe."
        description="Réunion instantanée en un clic, ou planifiée à l'avance : vos collègues reçoivent l'invitation dans leur agenda, un rappel avant le début, et retrouvent les notes de la réunion après."
        :points="[
            'Réunions instantanées ou planifiées, invitations Gmail / Outlook',
            'Salle d\'attente, partage d\'écran et notes partagées',
            'De 20 min (Starter) à 24 h (Corporate) par réunion',
            'Enregistrement des réunions (offre Corporate)',
        ]"
        reverse
    >
        <MeetingIllustration />
    </FeatureSpotlight>

    <section
        id="tout"
        class="scroll-mt-28 bg-[var(--mk-layer)] px-4 py-20 sm:px-6 lg:py-24"
    >
        <div class="mx-auto max-w-7xl">
            <h2 class="max-w-2xl text-3xl font-extrabold sm:text-[2.5rem]">
                Tout le reste, déjà inclus.
            </h2>
            <FeatureGrid class="mt-12" />
        </div>
    </section>

    <div class="h-20" />
</template>
