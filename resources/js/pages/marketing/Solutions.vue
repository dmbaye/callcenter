<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { toneClasses } from '@/data/marketing';
import { solutions } from '@/data/solutions';
import { vReveal } from '@/lib/reveal';
</script>

<template>
    <Head title="Solutions par secteur">
        <meta
            head-key="description"
            name="description"
            content="Banques, microfinance, cliniques, hôtels, assurances, commerce : comment Callcenter répond aux besoins téléphoniques de chaque secteur en Afrique de l'Ouest."
        />
    </Head>

    <section class="px-4 pt-14 pb-10 sm:px-6 md:pt-20">
        <div class="mx-auto max-w-7xl">
            <p
                class="mk-pop text-sm font-bold tracking-wide text-primary uppercase"
            >
                Solutions
            </p>
            <h1
                class="mk-pop mt-3 max-w-4xl text-[2.5rem] leading-[1.05] font-extrabold sm:text-6xl"
            >
                Une plateforme,
                <span class="text-primary">adaptée à votre métier.</span>
            </h1>
            <p
                class="mk-pop mt-6 max-w-2xl text-lg text-muted-foreground [animation-delay:80ms] sm:text-xl"
            >
                Chaque secteur a ses appels urgents, ses relances et ses pics
                d'activité. Voici comment nos clients organisent les leurs.
            </p>
        </div>
    </section>

    <section class="px-4 pb-20 sm:px-6">
        <div class="mx-auto grid max-w-7xl gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="(solution, index) in solutions"
                :key="solution.slug"
                v-reveal="(index % 3) * 80"
                :href="`/solutions/${solution.slug}`"
                class="group flex flex-col rounded-2xl border bg-card p-7 transition-colors hover:border-[var(--btn-outline-border-hover)]"
                :data-test="`solution-card-${solution.slug}`"
            >
                <span
                    class="flex size-12 items-center justify-center rounded-xl"
                    :class="toneClasses[solution.tone]"
                >
                    <component :is="solution.icon" class="size-6" />
                </span>
                <h2 class="mt-5 text-xl font-extrabold">
                    {{ solution.name }}
                </h2>
                <p class="mt-2 flex-1 text-muted-foreground">
                    {{ solution.summary }}
                </p>
                <span
                    class="mt-6 inline-flex items-center gap-1.5 font-bold text-primary"
                >
                    Voir la solution
                    <ArrowRight
                        class="size-4 transition-transform group-hover:translate-x-0.5"
                    />
                </span>
            </Link>

            <div
                v-reveal
                class="flex flex-col justify-between rounded-2xl border border-dashed p-7 sm:col-span-2 lg:col-span-3 lg:flex-row lg:items-center"
            >
                <div>
                    <h2 class="text-xl font-extrabold">
                        Votre secteur n'est pas dans la liste ?
                    </h2>
                    <p class="mt-2 max-w-2xl text-muted-foreground">
                        Télécoms, éducation, transport, ONG, administration :
                        parlez-nous de vos appels, nous vous montrons comment
                        les organiser.
                    </p>
                </div>
                <Button as-child variant="outline" class="mt-5 lg:mt-0">
                    <Link href="/contact">Parler à l'équipe</Link>
                </Button>
            </div>
        </div>
    </section>
</template>
