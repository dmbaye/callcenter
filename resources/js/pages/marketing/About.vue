<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    Globe,
    Handshake,
    Languages,
    Laptop,
    MapPin,
    Network,
    PhoneMissed,
    Printer,
    ServerCog,
    Smartphone,
    UserX,
    Wifi,
} from '@lucide/vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import { toneClasses } from '@/data/marketing';
import type { Tone } from '@/data/marketing';
import { solutions } from '@/data/solutions';
import { vReveal } from '@/lib/reveal';

type Card = {
    icon: Component;
    title: string;
    description: string;
    tone: Tone;
};

const problems: Card[] = [
    {
        icon: PhoneMissed,
        title: 'Des appels perdus sans le savoir',
        description:
            "Le téléphone reste le premier moyen de joindre une entreprise. Quand la ligne est occupée ou que personne ne décroche, l'appel est perdu, et personne ne sait combien de clients ont renoncé.",
        tone: 'orange',
    },
    {
        icon: UserX,
        title: 'Des clients sur les téléphones personnels',
        description:
            "Beaucoup d'équipes répondent depuis leur propre portable ou leur WhatsApp. Aucun historique partagé, aucun suivi, et le client part avec l'employé.",
        tone: 'fuchsia',
    },
    {
        icon: ServerCog,
        title: 'Des solutions pensées pour ailleurs',
        description:
            "Les standards téléphoniques coûtent cher à installer et à entretenir. Les logiciels étrangers sont facturés en devises, payés par carte internationale, souvent en anglais, avec un support à l'autre bout du monde.",
        tone: 'purple',
    },
];

const answers: Card[] = [
    {
        icon: Laptop,
        title: 'Rien à installer',
        description:
            'Un ordinateur, un navigateur et un casque suffisent. Le poste de chaque agent est créé automatiquement, sans téléphone de bureau ni serveur dans vos locaux.',
        tone: 'blue',
    },
    {
        icon: Banknote,
        title: 'Un prix en francs CFA',
        description:
            "Des tarifs publiés, en francs CFA, par agent et par mois. L'abonnement se règle par Orange Money, Moov Money ou carte bancaire.",
        tone: 'seafoam',
    },
    {
        icon: Languages,
        title: 'En français, pour tout le monde',
        description:
            "Files d'attente, menu vocal et horaires se règlent depuis des formulaires simples. Pas besoin d'être technicien pour piloter son centre d'appels.",
        tone: 'orange',
    },
    {
        icon: Wifi,
        title: 'Fait pour nos connexions',
        description:
            "L'audio s'adapte au débit disponible, et vos agents peuvent travailler depuis le bureau, une agence ou à distance.",
        tone: 'purple',
    },
    {
        icon: Handshake,
        title: 'Un accompagnement de proximité',
        description:
            'Mise en place, formation des agents et support assurés par notre équipe à Bamako, dans votre fuseau horaire et dans votre langue.',
        tone: 'fuchsia',
    },
    {
        icon: Smartphone,
        title: 'Les outils des grands centres',
        description:
            'Campagnes sortantes, supervision en direct, enregistrements, statistiques et réunions vidéo, dans un seul outil.',
        tone: 'blue',
    },
];

const poles: Card[] = [
    {
        icon: Globe,
        title: 'Développement web et mobile',
        description:
            'Applications métier, plateformes en ligne et applications mobiles, dont Callcenter.',
        tone: 'blue',
    },
    {
        icon: Network,
        title: 'Réseaux et infrastructure',
        description:
            'Câblage, réseaux, téléphonie IP et serveurs pour les entreprises.',
        tone: 'seafoam',
    },
    {
        icon: Laptop,
        title: 'Matériel informatique',
        description:
            'Ordinateurs, casques et équipements pour équiper vos postes de travail.',
        tone: 'orange',
    },
    {
        icon: Printer,
        title: 'Impression professionnelle',
        description:
            'Supports imprimés pour la communication des entreprises et des institutions.',
        tone: 'purple',
    },
];
</script>

<template>
    <Head title="À propos de MbayeLab">
        <meta
            head-key="description"
            name="description"
            content="MbayeLab, entreprise technologique basée à Bamako, conçoit Callcenter : un centre d'appels complet dans le navigateur, pensé pour les entreprises d'Afrique de l'Ouest."
        />
    </Head>

    <!-- Hero -->
    <section class="px-4 pt-14 pb-16 sm:px-6 md:pt-20">
        <div class="mx-auto max-w-7xl">
            <p
                class="mk-pop text-sm font-bold tracking-wide text-primary uppercase"
            >
                À propos
            </p>
            <h1
                class="mk-pop mt-3 max-w-4xl text-[2.5rem] leading-[1.05] font-extrabold sm:text-6xl"
            >
                Conçu à Bamako,
                <span class="text-primary"
                    >pour les entreprises d'Afrique de l'Ouest.</span
                >
            </h1>
            <p
                class="mk-pop mt-6 max-w-3xl text-lg leading-relaxed text-muted-foreground [animation-delay:80ms] sm:text-xl"
            >
                Callcenter est développé par MbayeLab, une entreprise
                technologique malienne. Notre conviction : chaque entreprise, de
                la PME à la banque, mérite un accueil téléphonique
                professionnel, à un prix et avec des outils adaptés à notre
                réalité.
            </p>
            <p
                class="mk-pop mt-6 inline-flex items-center gap-2 text-sm font-semibold text-muted-foreground [animation-delay:120ms]"
            >
                <MapPin class="size-4 text-primary" /> Moribabougou, Bamako,
                Mali
            </p>
        </div>
    </section>

    <!-- The problem -->
    <section class="bg-[var(--mk-layer)] px-4 py-16 sm:px-6 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <div v-reveal class="max-w-2xl">
                <p
                    class="text-sm font-bold tracking-wide text-primary uppercase"
                >
                    Le constat
                </p>
                <h2
                    class="mt-3 text-3xl leading-tight font-extrabold sm:text-[2.5rem]"
                >
                    Le téléphone fait vivre les entreprises. Il est rarement
                    bien organisé.
                </h2>
            </div>
            <div class="mt-10 grid gap-4 md:grid-cols-3">
                <article
                    v-for="(card, index) in problems"
                    :key="card.title"
                    v-reveal="index * 80"
                    class="rounded-2xl border bg-card p-7"
                >
                    <span
                        class="flex size-11 items-center justify-center rounded-xl"
                        :class="toneClasses[card.tone]"
                    >
                        <component :is="card.icon" class="size-5" />
                    </span>
                    <h3 class="mt-5 text-lg font-extrabold">
                        {{ card.title }}
                    </h3>
                    <p class="mt-2 leading-relaxed text-muted-foreground">
                        {{ card.description }}
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- Our answer -->
    <section class="px-4 py-16 sm:px-6 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <div v-reveal class="max-w-2xl">
                <p
                    class="text-sm font-bold tracking-wide text-primary uppercase"
                >
                    Notre réponse
                </p>
                <h2
                    class="mt-3 text-3xl leading-tight font-extrabold sm:text-[2.5rem]"
                >
                    Un centre d'appels complet, accessible à toutes les
                    entreprises.
                </h2>
                <p class="mt-4 text-lg text-muted-foreground">
                    Nous avons réuni les fonctions des grands centres de
                    contacts dans un outil simple, hébergé et payable
                    localement.
                </p>
            </div>
            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="(card, index) in answers"
                    :key="card.title"
                    v-reveal="(index % 3) * 80"
                    class="rounded-2xl border bg-card p-7"
                >
                    <span
                        class="flex size-11 items-center justify-center rounded-xl"
                        :class="toneClasses[card.tone]"
                    >
                        <component :is="card.icon" class="size-5" />
                    </span>
                    <h3 class="mt-5 text-lg font-extrabold">
                        {{ card.title }}
                    </h3>
                    <p class="mt-2 leading-relaxed text-muted-foreground">
                        {{ card.description }}
                    </p>
                </article>
            </div>
            <div class="mt-8 flex flex-wrap gap-3">
                <Button as-child>
                    <Link href="/features">
                        Voir les fonctionnalités <ArrowRight />
                    </Link>
                </Button>
                <Button as-child variant="outline">
                    <Link href="/pricing">Consulter les tarifs</Link>
                </Button>
            </div>
        </div>
    </section>

    <!-- Industries -->
    <section class="bg-[var(--mk-layer)] px-4 py-16 sm:px-6 lg:py-20">
        <div
            class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[1fr_1.4fr] lg:items-center"
        >
            <div v-reveal>
                <h2 class="text-3xl leading-tight font-extrabold">
                    Pensé avec les métiers de la région.
                </h2>
                <p class="mt-3 text-lg text-muted-foreground">
                    Banques, institutions de microfinance, cliniques, hôtels :
                    chaque secteur a ses urgences et ses relances.
                </p>
                <Link
                    href="/solutions"
                    class="group mt-6 inline-flex items-center gap-1.5 font-bold text-primary"
                >
                    Toutes les solutions
                    <ArrowRight
                        class="size-4 transition-transform group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    v-for="(solution, index) in solutions"
                    :key="solution.slug"
                    v-reveal="(index % 2) * 60"
                    :href="`/solutions/${solution.slug}`"
                    class="flex items-center gap-3 rounded-xl border bg-card p-4 font-semibold transition-colors hover:border-[var(--btn-outline-border-hover)]"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                        :class="toneClasses[solution.tone]"
                    >
                        <component :is="solution.icon" class="size-5" />
                    </span>
                    {{ solution.name }}
                </Link>
            </div>
        </div>
    </section>

    <!-- MbayeLab -->
    <section class="px-4 py-16 sm:px-6 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <div v-reveal class="max-w-2xl">
                <p
                    class="text-sm font-bold tracking-wide text-primary uppercase"
                >
                    MbayeLab
                </p>
                <h2
                    class="mt-3 text-3xl leading-tight font-extrabold sm:text-[2.5rem]"
                >
                    Une entreprise technologique, quatre métiers.
                </h2>
                <p class="mt-4 text-lg text-muted-foreground">
                    Callcenter s'appuie sur l'expérience de MbayeLab auprès des
                    entreprises maliennes : du logiciel au réseau, jusqu'au
                    matériel sur le bureau de vos agents.
                </p>
            </div>
            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <article
                    v-for="(card, index) in poles"
                    :key="card.title"
                    v-reveal="index * 60"
                    class="rounded-2xl border bg-card p-6"
                >
                    <span
                        class="flex size-11 items-center justify-center rounded-xl"
                        :class="toneClasses[card.tone]"
                    >
                        <component :is="card.icon" class="size-5" />
                    </span>
                    <h3 class="mt-5 text-base font-extrabold">
                        {{ card.title }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ card.description }}
                    </p>
                </article>
            </div>
        </div>
    </section>
</template>
