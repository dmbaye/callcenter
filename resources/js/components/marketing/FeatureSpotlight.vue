<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { vReveal } from '@/lib/reveal';

/** Text + illustration row; `reverse` puts the illustration on the left. */
withDefaults(
    defineProps<{
        id?: string;
        eyebrow: string;
        title: string;
        description: string;
        points?: string[];
        href?: string;
        linkLabel?: string;
        reverse?: boolean;
    }>(),
    { points: () => [], linkLabel: 'En savoir plus' },
);
</script>

<template>
    <section :id="id" class="scroll-mt-32 px-4 py-16 sm:px-6 lg:py-24">
        <div
            class="mx-auto grid max-w-7xl items-center gap-10 lg:grid-cols-2 lg:gap-16"
        >
            <div v-reveal :class="{ 'lg:order-2': reverse }">
                <p
                    class="text-sm font-bold tracking-wide text-primary uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h2
                    class="mt-3 text-3xl leading-tight font-extrabold sm:text-[2.5rem]"
                >
                    {{ title }}
                </h2>
                <p class="mt-4 text-lg leading-relaxed text-muted-foreground">
                    {{ description }}
                </p>
                <ul v-if="points.length" class="mt-6 space-y-3">
                    <li
                        v-for="point in points"
                        :key="point"
                        class="flex items-start gap-3"
                    >
                        <span
                            class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-informative-subtle text-informative-strong"
                        >
                            <Check class="size-3" />
                        </span>
                        <span class="text-[15px]">{{ point }}</span>
                    </li>
                </ul>
                <Link
                    v-if="href"
                    :href="href"
                    class="group mt-7 inline-flex items-center gap-1.5 font-bold text-primary"
                >
                    {{ linkLabel }}
                    <ArrowRight
                        class="size-4 transition-transform group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
            <div v-reveal="120" :class="{ 'lg:order-1': reverse }">
                <slot />
            </div>
        </div>
    </section>
</template>
