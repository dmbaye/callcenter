<script setup lang="ts">
import { features, toneClasses } from '@/data/marketing';
import { vReveal } from '@/lib/reveal';
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <article
            v-for="(feature, index) in features"
            :key="feature.title"
            v-reveal="(index % 4) * 60"
            class="rounded-2xl border bg-card p-6 transition-colors hover:border-[var(--btn-outline-border-hover)]"
        >
            <span
                class="flex size-11 items-center justify-center rounded-xl"
                :class="toneClasses[feature.tone]"
            >
                <component :is="feature.icon" class="size-5" />
            </span>
            <h3 class="mt-5 text-base font-extrabold">{{ feature.title }}</h3>
            <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                {{ feature.description }}
            </p>
        </article>
    </div>
</template>
