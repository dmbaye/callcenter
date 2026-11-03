<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { show } from '@/routes/help';
import type { HelpSearchEntry, HelpSection } from './types';
import { searchArticles } from './types';

const props = defineProps<{
    sections: HelpSection[];
    searchIndex: HelpSearchEntry[];
    autofocus?: boolean;
}>();

const query = ref('');
const results = computed(() =>
    searchArticles(props.sections, props.searchIndex, query.value).slice(0, 8),
);
</script>

<template>
    <div class="relative">
        <label for="help-search" class="sr-only">{{
            t('Rechercher dans l’aide')
        }}</label>
        <Search
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <Input
            id="help-search"
            v-model="query"
            type="search"
            class="pl-9"
            :placeholder="t('Rechercher : transférer, pause, file d’attente…')"
            autocomplete="off"
            v-focus="autofocus === true"
        />

        <div
            v-if="query.trim()"
            class="absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-[var(--card-radius)] border bg-popover shadow-[var(--popover-shadow)]"
            role="listbox"
            :aria-label="t('Résultats')"
        >
            <Link
                v-for="article in results"
                :key="article.slug"
                :href="show(article.slug).url"
                class="block px-4 py-3 hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                role="option"
            >
                <span class="block text-sm font-semibold text-heading">{{
                    article.title
                }}</span>
                <span class="block text-xs text-muted-foreground">{{
                    article.summary
                }}</span>
            </Link>
            <p
                v-if="results.length === 0"
                class="px-4 py-3 text-sm text-muted-foreground"
            >
                {{ t('Aucun article ne correspond. Essayez un autre mot.') }}
            </p>
        </div>
    </div>
</template>
