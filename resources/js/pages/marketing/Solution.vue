<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, CircleAlert } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import CampaignIllustration from '@/components/marketing/CampaignIllustration.vue';
import FeatureSpotlight from '@/components/marketing/FeatureSpotlight.vue';
import MeetingIllustration from '@/components/marketing/MeetingIllustration.vue';
import RoutingIllustration from '@/components/marketing/RoutingIllustration.vue';
import SoftphoneIllustration from '@/components/marketing/SoftphoneIllustration.vue';
import StatsIllustration from '@/components/marketing/StatsIllustration.vue';
import SupervisionIllustration from '@/components/marketing/SupervisionIllustration.vue';
import { Button } from '@/components/ui/button';
import { toneClasses } from '@/data/marketing';
import { findSolution, solutions } from '@/data/solutions';
import type { Illustration, Solution } from '@/data/solutions';
import { vReveal } from '@/lib/reveal';
import { register } from '@/routes';

const props = defineProps<{
    /** Slug from the URL; the route only accepts known slugs. */
    industry: string;
}>();

const illustrations: Record<Illustration, Component> = {
    routing: RoutingIllustration,
    campaign: CampaignIllustration,
    supervision: SupervisionIllustration,
    stats: StatsIllustration,
    softphone: SoftphoneIllustration,
    meeting: MeetingIllustration,
};

const solution = computed(
    () => (findSolution(props.industry) ?? solutions[0]) as Solution,
);

const others = computed(() =>
    solutions.filter((item) => item.slug !== solution.value.slug),
);
</script>

<template>
    <Head :title="`Solutions · ${solution.name}`">
        <meta
            head-key="description"
            name="description"
            :content="solution.summary"
        />
    </Head>

    <!-- Hero -->
    <section class="px-4 pt-14 pb-16 sm:px-6 md:pt-20">
        <div class="mx-auto max-w-7xl">
            <Link
                href="/solutions"
                class="mk-pop inline-flex items-center gap-2 text-sm font-bold tracking-wide text-primary uppercase"
            >
                <span
                    class="flex size-7 items-center justify-center rounded-lg"
                    :class="toneClasses[solution.tone]"
                >
                    <component :is="solution.icon" class="size-4" />
                </span>
                Solutions · {{ solution.name }}
            </Link>
            <h1
                class="mk-pop mt-4 max-w-4xl text-[2.5rem] leading-[1.05] font-extrabold sm:text-6xl"
            >
                {{ solution.title }}
            </h1>
            <p
                class="mk-pop mt-6 max-w-3xl text-lg leading-relaxed text-muted-foreground [animation-delay:80ms] sm:text-xl"
            >
                {{ solution.intro }}
            </p>
            <div
                class="mk-pop mt-9 flex flex-col gap-3 [animation-delay:160ms] sm:flex-row"
            >
                <Button as-child size="lg">
                    <Link :href="register().url">
                        Démarrer l'essai gratuit
                        <ArrowRight />
                    </Link>
                </Button>
                <Button as-child size="lg" variant="outline">
                    <Link href="/contact">Demander une démonstration</Link>
                </Button>
            </div>
        </div>
    </section>

    <!-- What goes wrong today -->
    <section class="bg-[var(--mk-layer)] px-4 py-16 sm:px-6 lg:py-20">
        <div class="mx-auto max-w-7xl">
            <h2
                v-reveal
                class="max-w-2xl text-3xl font-extrabold sm:text-[2.5rem]"
            >
                Ce qui coince aujourd'hui.
            </h2>
            <div class="mt-10 grid gap-4 md:grid-cols-3">
                <article
                    v-for="(pain, index) in solution.pains"
                    :key="pain.title"
                    v-reveal="index * 80"
                    class="rounded-2xl border bg-card p-7"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-notice-subtle text-notice-strong"
                    >
                        <CircleAlert class="size-5" />
                    </span>
                    <h3 class="mt-5 text-lg font-extrabold">
                        {{ pain.title }}
                    </h3>
                    <p class="mt-2 text-muted-foreground">
                        {{ pain.description }}
                    </p>
                </article>
            </div>
        </div>
    </section>

    <!-- How Callcenter answers -->
    <FeatureSpotlight
        v-for="(spotlight, index) in solution.spotlights"
        :key="spotlight.title"
        :eyebrow="spotlight.eyebrow"
        :title="spotlight.title"
        :description="spotlight.description"
        :points="spotlight.points"
        :reverse="index % 2 === 1"
    >
        <component :is="illustrations[spotlight.illustration]" />
    </FeatureSpotlight>

    <!-- Typical uses -->
    <section class="px-4 pb-20 sm:px-6">
        <div
            class="mx-auto grid max-w-7xl gap-10 rounded-[28px] border bg-card p-8 sm:p-12 lg:grid-cols-[1fr_1.3fr] lg:items-center"
        >
            <div v-reveal>
                <h2 class="text-3xl font-extrabold">Exemples d'usage</h2>
                <p class="mt-3 text-muted-foreground">
                    Ce que les équipes du secteur mettent en place dès la
                    première semaine.
                </p>
                <Link
                    href="/features"
                    class="group mt-6 inline-flex items-center gap-1.5 font-bold text-primary"
                >
                    Toutes les fonctionnalités
                    <ArrowRight
                        class="size-4 transition-transform group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
            <ul class="grid gap-3 sm:grid-cols-2">
                <li
                    v-for="(scenario, index) in solution.scenarios"
                    :key="scenario"
                    v-reveal="index * 60"
                    class="flex items-start gap-3 rounded-xl bg-[var(--mk-layer)] p-4"
                >
                    <span
                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-positive-subtle text-positive-strong"
                    >
                        <Check class="size-3" />
                    </span>
                    <span class="font-semibold">{{ scenario }}</span>
                </li>
            </ul>
        </div>
    </section>

    <!-- Other industries -->
    <section class="px-4 pb-20 sm:px-6">
        <div class="mx-auto max-w-7xl">
            <h2 class="text-xl font-extrabold">Autres secteurs</h2>
            <div class="mt-5 flex flex-wrap gap-2">
                <Link
                    v-for="item in others"
                    :key="item.slug"
                    :href="`/solutions/${item.slug}`"
                    class="inline-flex items-center gap-2 rounded-full border bg-card py-1.5 pr-4 pl-1.5 text-sm font-semibold transition-colors hover:border-[var(--btn-outline-border-hover)]"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-full"
                        :class="toneClasses[item.tone]"
                    >
                        <component :is="item.icon" class="size-4" />
                    </span>
                    {{ item.name }}
                </Link>
            </div>
        </div>
    </section>
</template>
