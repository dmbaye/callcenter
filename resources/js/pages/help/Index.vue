<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    Compass,
    Headset,
    Rocket,
    Settings2,
    Activity,
} from '@lucide/vue';
import { computed } from 'vue';
import type { HelpSearchEntry, HelpSection } from '@/components/help/types';
import HelpSearch from '@/components/help/HelpSearch.vue';
import { Button } from '@/components/ui/button';
import { useAppTour } from '@/composables/useAppTour';
import { index, show } from '@/routes/help';

const props = defineProps<{
    sections: HelpSection[];
    searchIndex: HelpSearchEntry[];
    audience: string[];
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Aide', href: index().url }],
    }),
});

const { start } = useAppTour();

const icons: Record<string, typeof Rocket> = {
    start: Rocket,
    agent: Headset,
    supervision: Activity,
    admin: Settings2,
};

/** The articles written for the reader's role (beyond "everyone"). */
const recommended = computed(() => {
    const roles = props.audience.filter((role) => role !== 'all');
    const top = roles[roles.length - 1];

    return props.sections
        .flatMap((section) => section.articles)
        .filter((article) => article.audience === top)
        .slice(0, 4);
});
</script>

<template>
    <Head :title="t('Centre d’aide')" />

    <div class="mx-auto w-full max-w-5xl space-y-10 p-4 md:p-8">
        <header class="space-y-4">
            <div class="space-y-1">
                <h1
                    class="text-2xl font-bold tracking-tight text-heading sm:text-3xl"
                >
                    {{ t('Centre d’aide') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Tout pour prendre en main l’application : appels, statuts, supervision, campagnes et réglages.',
                        )
                    }}
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex-1">
                    <HelpSearch
                        :sections="sections"
                        :search-index="searchIndex"
                        autofocus
                    />
                </div>
                <Button variant="outline" @click="start">
                    <Compass /> {{ t('Lancer la visite guidée') }}
                </Button>
            </div>
        </header>

        <section v-if="recommended.length" aria-labelledby="help-recommended">
            <h2
                id="help-recommended"
                class="mb-3 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('Pour bien démarrer') }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    v-for="article in recommended"
                    :key="article.slug"
                    :href="show(article.slug).url"
                    class="group flex items-center gap-3 rounded-[var(--card-radius)] border bg-background p-4 transition-colors hover:border-primary/50"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-heading">
                            {{ article.title }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ article.summary }}
                        </p>
                    </div>
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
        </section>

        <div class="grid gap-6 md:grid-cols-2">
            <section
                v-for="section in sections"
                :key="section.key"
                class="rounded-[var(--card-radius)] border bg-background"
                :aria-labelledby="`help-section-${section.key}`"
            >
                <h2
                    :id="`help-section-${section.key}`"
                    class="flex items-center gap-2 border-b px-5 py-4 font-bold text-heading"
                >
                    <component
                        :is="icons[section.key] ?? Rocket"
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />
                    {{ section.title }}
                </h2>
                <ul class="divide-y">
                    <li v-for="article in section.articles" :key="article.slug">
                        <Link
                            :href="show(article.slug).url"
                            class="block px-5 py-3 hover:bg-accent/60"
                        >
                            <span
                                class="block text-sm font-semibold text-foreground"
                                >{{ article.title }}</span
                            >
                            <span class="block text-xs text-muted-foreground">{{
                                article.summary
                            }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
