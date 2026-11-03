<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Layout of the legal pages: title, last update, table of contents and
 * the three documents linked together. Values still missing in
 * config/legal.php show as « [à compléter] » (see `.legal-todo`).
 */
const props = defineProps<{
    title: string;
    intro: string;
    updatedAt: string;
    sections: { id: string; title: string }[];
}>();

const updated = computed(() =>
    new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(
        new Date(`${props.updatedAt}T00:00:00`),
    ),
);

const documents = [
    { href: '/confidentialite', title: 'Politique de confidentialité' },
    { href: '/mentions-legales', title: 'Mentions légales' },
    { href: '/confiance', title: 'Centre de confiance' },
];
</script>

<template>
    <Head :title="`${title} — Callcenter`" />

    <section class="px-4 pt-14 pb-10 sm:px-6 md:pt-20">
        <div class="mx-auto max-w-5xl">
            <nav class="flex flex-wrap gap-2 text-sm" aria-label="Documents">
                <Link
                    v-for="doc in documents"
                    :key="doc.href"
                    :href="doc.href"
                    class="rounded-full border px-3 py-1 transition-colors hover:bg-accent"
                    :class="{
                        'border-primary bg-primary/5 font-semibold text-primary':
                            doc.title === title,
                    }"
                    >{{ doc.title }}</Link
                >
            </nav>
            <h1
                class="mt-6 text-3xl font-extrabold text-heading sm:text-[2.6rem]"
            >
                {{ title }}
            </h1>
            <p class="mt-3 max-w-3xl text-lg text-muted-foreground">
                {{ intro }}
            </p>
            <p class="mt-3 text-sm text-muted-foreground">
                Dernière mise à jour : {{ updated }}
            </p>
        </div>
    </section>

    <section class="px-4 pb-20 sm:px-6">
        <div class="mx-auto grid max-w-5xl gap-10 lg:grid-cols-[14rem_1fr]">
            <aside class="hidden lg:block">
                <nav
                    class="sticky top-24 space-y-1 text-sm"
                    aria-label="Sommaire"
                >
                    <p
                        class="mb-2 text-xs font-bold tracking-wide text-primary uppercase"
                    >
                        Sommaire
                    </p>
                    <a
                        v-for="section in sections"
                        :key="section.id"
                        :href="`#${section.id}`"
                        class="block rounded px-2 py-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                        >{{ section.title }}</a
                    >
                </nav>
            </aside>
            <article class="legal-prose min-w-0">
                <slot />
            </article>
        </div>
    </section>
</template>

<style>
.legal-prose h2 {
    scroll-margin-top: 6rem;
    margin-top: 2.5rem;
    margin-bottom: 0.75rem;
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--heading);
}
.legal-prose h2:first-child {
    margin-top: 0;
}
.legal-prose h3 {
    margin-top: 1.5rem;
    margin-bottom: 0.5rem;
    font-weight: 700;
}
.legal-prose p,
.legal-prose li {
    line-height: 1.7;
}
.legal-prose p + p {
    margin-top: 0.75rem;
}
.legal-prose ul {
    margin: 0.75rem 0;
    padding-left: 1.25rem;
    list-style: disc;
}
.legal-prose li + li {
    margin-top: 0.35rem;
}
.legal-prose a {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.legal-prose table {
    width: 100%;
    margin: 1rem 0;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.legal-prose th,
.legal-prose td {
    border: 1px solid var(--border);
    padding: 0.5rem 0.75rem;
    text-align: left;
    vertical-align: top;
}
.legal-prose th {
    background: var(--muted);
    font-weight: 600;
}
.legal-prose .table-wrap {
    overflow-x: auto;
}
.legal-todo {
    border-radius: 0.25rem;
    background: color-mix(in oklab, var(--notice, #f5a524) 22%, transparent);
    padding: 0 0.25rem;
    font-weight: 600;
}
</style>
