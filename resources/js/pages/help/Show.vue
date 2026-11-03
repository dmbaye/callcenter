<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Compass } from '@lucide/vue';
import type { HelpSearchEntry, HelpSection } from '@/components/help/types';
import HelpSearch from '@/components/help/HelpSearch.vue';
import { Button } from '@/components/ui/button';
import { useAppTour } from '@/composables/useAppTour';
import { index, show } from '@/routes/help';

type Article = {
    slug: string;
    title: string;
    summary: string;
    section: string;
    /** Rendered server side from our own Markdown (raw HTML escaped). */
    html: string;
    toc: { id: string; title: string }[];
};

defineProps<{
    sections: HelpSection[];
    searchIndex: HelpSearchEntry[];
    article: Article;
    previous: { slug: string; title: string } | null;
    next: { slug: string; title: string } | null;
}>();

defineOptions({
    layout: (props: { article: Article }) => ({
        breadcrumbs: [
            { title: 'Aide', href: index().url },
            { title: props.article.title, href: show(props.article.slug).url },
        ],
    }),
});

const { start } = useAppTour();

/** Links inside the article to other app pages go through Inertia (no full reload). */
function followLink(event: MouseEvent) {
    const link = (event.target as HTMLElement | null)?.closest('a');
    const href = link?.getAttribute('href');

    if (
        !link ||
        !href ||
        !href.startsWith('/') ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.button !== 0
    ) {
        return;
    }

    event.preventDefault();
    router.visit(href);
}
</script>

<template>
    <Head :title="`${article.title} – Aide`" />

    <div
        class="mx-auto grid w-full max-w-7xl gap-8 p-4 md:p-8 lg:grid-cols-[14rem_minmax(0,1fr)] 2xl:grid-cols-[14rem_minmax(0,1fr)_12rem]"
    >
        <!-- Articles -->
        <aside class="hidden lg:block" :aria-label="t('Articles d’aide')">
            <nav class="sticky top-6 space-y-6 text-sm">
                <div v-for="section in sections" :key="section.key">
                    <p
                        class="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ section.title }}
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.articles" :key="item.slug">
                            <Link
                                :href="show(item.slug).url"
                                class="block rounded-md px-2 py-1.5 hover:bg-accent"
                                :class="
                                    item.slug === article.slug
                                        ? 'bg-accent font-semibold text-heading'
                                        : 'text-foreground/80'
                                "
                                :aria-current="
                                    item.slug === article.slug
                                        ? 'page'
                                        : undefined
                                "
                                >{{ item.title }}</Link
                            >
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Article -->
        <article class="min-w-0">
            <div class="mb-6">
                <HelpSearch :sections="sections" :search-index="searchIndex" />
            </div>

            <p class="text-sm font-medium text-primary">
                {{ article.section }}
            </p>
            <h1
                class="mt-1 text-2xl font-bold tracking-tight text-heading sm:text-3xl"
            >
                {{ article.title }}
            </h1>
            <p class="mt-2 text-muted-foreground">{{ article.summary }}</p>

            <!-- eslint-disable-next-line vue/no-v-html -->
            <div
                class="help-content mt-8"
                @click="followLink"
                v-html="article.html"
            />

            <div
                v-if="article.slug === 'bienvenue'"
                class="mt-8 flex flex-col gap-3 rounded-[var(--card-radius)] bg-muted p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm">
                    {{
                        t(
                            'Envie de voir tout cela en vrai ? La visite guidée vous montre chaque zone de l’écran.',
                        )
                    }}
                </p>
                <Button size="sm" @click="start">
                    <Compass /> {{ t('Lancer la visite guidée') }}
                </Button>
            </div>

            <nav
                class="mt-12 grid gap-3 border-t pt-6 sm:grid-cols-2"
                :aria-label="t('Article précédent et suivant')"
            >
                <Link
                    v-if="previous"
                    :href="show(previous.slug).url"
                    class="group rounded-[var(--card-radius)] border p-4 hover:border-primary/50"
                >
                    <span
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                        ><ArrowLeft class="size-3" /> {{ t('Précédent') }}</span
                    >
                    <span class="mt-1 block font-semibold text-heading">{{
                        previous.title
                    }}</span>
                </Link>
                <span v-else />
                <Link
                    v-if="next"
                    :href="show(next.slug).url"
                    class="group rounded-[var(--card-radius)] border p-4 text-right hover:border-primary/50"
                >
                    <span
                        class="flex items-center justify-end gap-1 text-xs text-muted-foreground"
                        >{{ t('Suivant') }} <ArrowRight class="size-3"
                    /></span>
                    <span class="mt-1 block font-semibold text-heading">{{
                        next.title
                    }}</span>
                </Link>
            </nav>
        </article>

        <!-- On this page -->
        <aside
            v-if="article.toc.length > 1"
            class="hidden 2xl:block"
            :aria-label="t('Sur cette page')"
        >
            <nav class="sticky top-6 text-sm">
                <p
                    class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('Sur cette page') }}
                </p>
                <ul class="space-y-1.5 border-l">
                    <li v-for="entry in article.toc" :key="entry.id">
                        <a
                            :href="`#${entry.id}`"
                            class="-ml-px block border-l border-transparent pl-3 text-foreground/80 hover:border-primary hover:text-heading"
                            >{{ entry.title }}</a
                        >
                    </li>
                </ul>
            </nav>
        </aside>
    </div>
</template>

<style scoped>
.help-content {
    line-height: 1.7;
    color: var(--foreground);
}

.help-content :deep(h2) {
    margin: 2.25rem 0 0.75rem;
    scroll-margin-top: 1.5rem;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--heading);
}

.help-content :deep(h3) {
    margin: 1.75rem 0 0.5rem;
    font-weight: 700;
    color: var(--heading);
}

.help-content :deep(p),
.help-content :deep(ul),
.help-content :deep(ol),
.help-content :deep(table),
.help-content :deep(blockquote) {
    margin: 0 0 1rem;
}

.help-content :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}

.help-content :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}

.help-content :deep(li) {
    margin: 0.25rem 0;
}

.help-content :deep(strong) {
    font-weight: 600;
    color: var(--heading);
}

.help-content :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.help-content :deep(code) {
    border-radius: 4px;
    background: var(--muted);
    padding: 0.1rem 0.35rem;
    font-size: 0.875em;
}

.help-content :deep(.table-wrap) {
    margin: 0 0 1rem;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: var(--control-radius);
}

.help-content :deep(table) {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.help-content :deep(tr:last-child td) {
    border-bottom: 0;
}

.help-content :deep(th),
.help-content :deep(td) {
    border-bottom: 1px solid var(--border);
    padding: 0.5rem 0.75rem;
    text-align: left;
    vertical-align: top;
}

.help-content :deep(th) {
    background: var(--muted);
    font-weight: 600;
    color: var(--heading);
    white-space: nowrap;
}

.help-content :deep(blockquote) {
    border-left: 3px solid var(--informative);
    border-radius: 0 var(--control-radius) var(--control-radius) 0;
    background: var(--informative-subtle);
    padding: 0.75rem 1rem;
}

.help-content :deep(blockquote p:last-child) {
    margin-bottom: 0;
}
</style>
