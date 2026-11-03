<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, Sparkles } from '@lucide/vue';
import CampaignIllustration from '@/components/marketing/CampaignIllustration.vue';
import FeatureGrid from '@/components/marketing/FeatureGrid.vue';
import FeatureSpotlight from '@/components/marketing/FeatureSpotlight.vue';
import HeroIllustration from '@/components/marketing/HeroIllustration.vue';
import MeetingIllustration from '@/components/marketing/MeetingIllustration.vue';
import RoutingIllustration from '@/components/marketing/RoutingIllustration.vue';
import SupervisionIllustration from '@/components/marketing/SupervisionIllustration.vue';
import { Button } from '@/components/ui/button';
import { steps } from '@/data/marketing';
import { vReveal } from '@/lib/reveal';
import { register } from '@/routes';

const promises = [
    'Essai gratuit, sans moyen de paiement',
    'Rien à installer pour les agents',
    'Paiement en Mobile Money',
];
</script>

<template>
    <Head title="Callcenter — Le centre d'appels complet, dans le navigateur" />

    <!-- Hero -->
    <section class="overflow-hidden px-4 pt-14 pb-16 sm:px-6 md:pt-20 lg:pb-24">
        <div class="mx-auto max-w-7xl">
            <div class="mx-auto max-w-4xl text-center">
                <Link
                    href="/features#campagnes"
                    class="mk-pop inline-flex items-center gap-2 rounded-full border bg-card py-1 pr-3 pl-1 text-sm font-semibold transition-colors hover:border-[var(--btn-outline-border-hover)]"
                >
                    <span
                        class="inline-flex items-center gap-1 rounded-full bg-primary px-2.5 py-0.5 text-xs font-bold text-white"
                    >
                        <Sparkles class="size-3" /> Nouveau
                    </span>
                    <span class="sm:hidden">Campagnes et vidéo</span>
                    <span class="hidden sm:inline"
                        >Campagnes sortantes et réunions vidéo</span
                    >
                    <ArrowRight class="size-3.5 text-muted-foreground" />
                </Link>

                <h1
                    class="mk-pop mt-7 text-[2.6rem] leading-[1.04] font-extrabold sm:text-6xl lg:text-7xl"
                >
                    Votre centre d'appels,
                    <span class="text-primary"
                        >entièrement dans le navigateur.</span
                    >
                </h1>
                <p
                    class="mk-pop mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground [animation-delay:80ms] sm:text-xl"
                >
                    Softphone, files d'attente et menu vocal, campagnes
                    d'appels, supervision en direct et réunions vidéo. Une seule
                    plateforme pour vos agents, pensée pour les entreprises
                    d'Afrique de l'Ouest.
                </p>
                <div
                    class="mk-pop mt-9 flex flex-col justify-center gap-3 [animation-delay:160ms] sm:flex-row"
                >
                    <Button as-child size="lg">
                        <Link :href="register().url">
                            Démarrer l'essai gratuit
                            <ArrowRight />
                        </Link>
                    </Button>
                    <Button as-child size="lg" variant="outline">
                        <Link href="/features">Découvrir la plateforme</Link>
                    </Button>
                </div>
                <ul
                    class="mt-7 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm text-muted-foreground"
                >
                    <li
                        v-for="promise in promises"
                        :key="promise"
                        class="flex items-center gap-1.5"
                    >
                        <Check class="size-4 text-positive" /> {{ promise }}
                    </li>
                </ul>
            </div>

            <div v-reveal="200" class="mt-14 lg:mt-16">
                <HeroIllustration />
            </div>
        </div>
    </section>

    <div class="border-t" />

    <FeatureSpotlight
        id="entrant"
        eyebrow="Appels entrants"
        title="Chaque appel trouve la bonne personne."
        description="Horaires, menu vocal, files d'attente et débordement se règlent en quelques clics. Vos clients n'attendent plus dans le vide, et vos agents voient qui appelle avant de décrocher."
        :points="[
            'Menu vocal « tapez 1, tapez 2 » sans écrire de code',
            'Fermetures, jours fériés et fermeture d\'urgence',
            'Rappel proposé quand l\'attente devient longue',
            'Fiche de l\'appelant à l\'écran dès la sonnerie',
        ]"
        href="/features#entrant"
    >
        <RoutingIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="campagnes"
        eyebrow="Campagnes sortantes"
        title="Appelez plus de clients, sans temps mort."
        description="Importez votre liste, choisissez un mode de numérotation et laissez la plateforme composer. Vos agents ne reçoivent que des personnes en ligne, avec leur fiche à l'écran."
        :points="[
            'Modes aperçu, progressif et « power »',
            'Détection de répondeur et liste « Ne pas appeler »',
            'Résultats d\'appel, rappels planifiés et nouvelles tentatives',
        ]"
        href="/features#campagnes"
        reverse
    >
        <CampaignIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="supervision"
        eyebrow="Supervision en direct"
        title="Accompagnez vos agents pendant l'appel."
        description="Un seul écran pour voir la file, les agents et les appels en cours. Écoutez en silence ou soufflez un conseil à l'agent : le client n'entend rien."
        :points="[
            'Statuts des agents et file d\'attente en temps réel',
            'Écoute silencieuse, chuchotement et enregistrement des appels',
            'Niveau de service et satisfaction client',
        ]"
        href="/features#supervision"
    >
        <SupervisionIllustration />
    </FeatureSpotlight>

    <FeatureSpotlight
        id="video"
        eyebrow="Réunions vidéo"
        title="Réunissez l'équipe sans changer d'outil."
        description="Points d'équipe, formations ou rendez-vous client : lancez une réunion vidéo depuis la plateforme et invitez vos collègues en un clic."
        :points="[
            'Vidéo et partage d\'écran, enregistrement en offre Corporate',
            'Invitations en direct aux membres de l\'équipe',
        ]"
        href="/features#video"
        reverse
    >
        <MeetingIllustration />
    </FeatureSpotlight>

    <!-- Everything else -->
    <section class="bg-[var(--mk-layer)] px-4 py-20 sm:px-6 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <div v-reveal class="max-w-2xl">
                <h2 class="text-3xl font-extrabold sm:text-[2.5rem]">
                    Et tout ce qu'il faut au quotidien.
                </h2>
                <p class="mt-4 text-lg text-muted-foreground">
                    Les fonctions d'un grand centre de contacts, réglables par
                    une personne qui n'est pas technicienne.
                </p>
            </div>
            <FeatureGrid class="mt-12" />
        </div>
    </section>

    <!-- Getting started -->
    <section class="px-4 py-20 sm:px-6 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <h2
                v-reveal
                class="max-w-2xl text-3xl font-extrabold sm:text-[2.5rem]"
            >
                Opérationnel en trois étapes.
            </h2>
            <ol class="mt-12 grid gap-4 md:grid-cols-3">
                <li
                    v-for="(item, index) in steps"
                    :key="item.title"
                    v-reveal="index * 100"
                    class="relative rounded-2xl border bg-card p-7"
                >
                    <span
                        class="text-5xl leading-none font-extrabold text-primary"
                        >{{ index + 1 }}</span
                    >
                    <h3 class="mt-5 text-xl font-extrabold">
                        {{ item.title }}
                    </h3>
                    <p class="mt-2 text-muted-foreground">
                        {{ item.description }}
                    </p>
                </li>
            </ol>
        </div>
    </section>
</template>
