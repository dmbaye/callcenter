<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Download, Pencil } from '@lucide/vue';
import { computed } from 'vue';
import type { SurveyQuestion } from '@/components/campaigns/survey';
import StatCard from '@/components/StatCard.vue';
import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/format';
import { intlLocale, t } from '@/lib/i18n';
import { index as campaignsIndex } from '@/routes/campaigns';
import type { Team } from '@/types';

type Breakdown = { label: string; count: number; percent: number };

type QuestionResult = SurveyQuestion & {
    answered: number;
    breakdown?: Breakdown[];
    average?: number | null;
    min?: number | null;
    max?: number | null;
    nps?: number | null;
    promoters?: number;
    passives?: number;
    detractors?: number;
    texts?: { value: string; contact: string | null; at: string | null }[];
};

const props = defineProps<{
    campaign: { id: number; name: string };
    contacts: number;
    responses: number;
    completed: number;
    questions: QuestionResult[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Campagnes',
                href: props.currentTeam
                    ? campaignsIndex(props.currentTeam.slug)
                    : '/',
            },
            { title: 'Résultats du questionnaire', href: '#' },
        ],
    }),
});

const page = usePage();
const base = computed(
    () =>
        `/${page.props.currentTeam?.slug ?? ''}/campaigns/${props.campaign.id}`,
);

const number = (value: number | null | undefined, digits = 1) =>
    value === null || value === undefined
        ? '—'
        : new Intl.NumberFormat(intlLocale(), {
              maximumFractionDigits: digits,
          }).format(value);

const completionRate = computed(() =>
    props.responses ? Math.round((props.completed * 100) / props.responses) : 0,
);
const coverage = computed(() =>
    props.contacts ? Math.round((props.responses * 100) / props.contacts) : 0,
);

const npsTone = (score: number | null | undefined) =>
    score === null || score === undefined
        ? ''
        : score >= 30
          ? 'text-positive-strong'
          : score >= 0
            ? 'text-notice-strong'
            : 'text-negative-strong';

const showsBars = (q: QuestionResult) =>
    !!q.breakdown &&
    ['single', 'multiple', 'yes_no', 'rating', 'nps'].includes(q.type);
</script>

<template>
    <Head :title="t('Résultats du questionnaire')" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <Link
                    :href="base"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    ><ArrowLeft class="size-4" /> {{ campaign.name }}</Link
                >
                <h1 class="mt-1 text-2xl font-semibold text-[var(--heading)]">
                    {{ t('Résultats du questionnaire') }}
                </h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <Link :href="`${base}/survey`"
                        ><Pencil /> {{ t('Modifier les questions') }}</Link
                    >
                </Button>
                <Button as-child variant="outline">
                    <a :href="`${base}/survey/export`"
                        ><Download /> {{ t('Export CSV') }}</a
                    >
                </Button>
            </div>
        </header>

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard
                :label="t('Réponses')"
                :value="String(responses)"
                :hint="t(':p0 % des contacts', { p0: coverage })"
            />
            <StatCard
                :label="t('Questionnaires terminés')"
                :value="String(completed)"
                :hint="t(':p0 % des réponses', { p0: completionRate })"
            />
            <StatCard
                :label="t('Questions')"
                :value="String(questions.length)"
            />
        </div>

        <p
            v-if="responses === 0"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            {{
                t(
                    'Aucune réponse pour le moment : elles apparaîtront ici dès que les agents rempliront le questionnaire.',
                )
            }}
        </p>

        <template v-else>
            <section
                v-for="(q, index) in questions"
                :key="q.key"
                class="space-y-4 rounded-lg border p-5"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="font-semibold">
                        <span class="text-muted-foreground"
                            >{{ index + 1 }}.</span
                        >
                        {{ q.label }}
                    </h2>
                    <span class="text-sm text-muted-foreground">{{
                        t(':count réponse(s)', { count: q.answered })
                    }}</span>
                </div>

                <!-- Headline figures -->
                <div
                    v-if="q.type === 'nps'"
                    class="flex flex-wrap items-end gap-6"
                >
                    <div>
                        <div
                            class="text-4xl font-bold tabular-nums"
                            :class="npsTone(q.nps)"
                        >
                            {{ q.nps ?? '—' }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ t('Score NPS (−100 à +100)') }}
                        </div>
                    </div>
                    <div class="text-sm">
                        <span class="text-positive-strong">{{
                            t(':count promoteurs (9–10)', {
                                count: q.promoters ?? 0,
                            })
                        }}</span>
                        ·
                        {{
                            t(':count passifs (7–8)', {
                                count: q.passives ?? 0,
                            })
                        }}
                        ·
                        <span class="text-negative-strong">{{
                            t(':count détracteurs (0–6)', {
                                count: q.detractors ?? 0,
                            })
                        }}</span>
                    </div>
                </div>
                <div
                    v-else-if="q.type === 'rating'"
                    class="text-3xl font-bold tabular-nums"
                >
                    {{ number(q.average, 2) }}
                    <span class="text-base font-normal text-muted-foreground"
                        >/ {{ q.settings.max ?? 5 }} {{ t('en moyenne') }}</span
                    >
                </div>
                <dl
                    v-else-if="q.type === 'number'"
                    class="grid max-w-md grid-cols-3 gap-4"
                >
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('Moyenne') }}
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ number(q.average, 2) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('Minimum') }}
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ number(q.min) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('Maximum') }}
                        </dt>
                        <dd class="text-xl font-semibold tabular-nums">
                            {{ number(q.max) }}
                        </dd>
                    </div>
                </dl>

                <!-- Bars -->
                <ul v-if="showsBars(q)" class="space-y-2">
                    <li
                        v-for="row in q.breakdown"
                        :key="row.label"
                        class="grid grid-cols-[minmax(6rem,14rem)_1fr_5.5rem] items-center gap-3 text-sm"
                    >
                        <span class="truncate" :title="row.label">{{
                            q.type === 'yes_no' ? t(row.label) : row.label
                        }}</span>
                        <span
                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <span
                                class="block h-full rounded-full bg-primary"
                                :style="{ width: `${row.percent}%` }"
                            />
                        </span>
                        <span class="text-right tabular-nums"
                            >{{ number(row.percent) }} %
                            <span class="text-muted-foreground"
                                >({{ row.count }})</span
                            ></span
                        >
                    </li>
                </ul>
                <p
                    v-if="q.type === 'multiple'"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t(
                            'Plusieurs réponses possibles : le total peut dépasser 100 %.',
                        )
                    }}
                </p>

                <!-- Free answers -->
                <ul
                    v-if="q.texts && q.texts.length"
                    class="divide-y rounded-md border text-sm"
                >
                    <li
                        v-for="(answer, i) in q.texts"
                        :key="i"
                        class="flex flex-wrap justify-between gap-2 px-3 py-2"
                    >
                        <span class="whitespace-pre-line">{{
                            answer.value
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >{{ answer.contact }} ·
                            {{ formatRelative(answer.at) }}</span
                        >
                    </li>
                </ul>
                <p
                    v-if="q.texts && q.texts.length >= 30"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t(
                            "Les 30 réponses les plus récentes. Toutes les réponses sont dans l'export CSV.",
                        )
                    }}
                </p>
            </section>
        </template>
    </div>
</template>
