<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';

import StatCard from '@/components/StatCard.vue';
import StatusLight from '@/components/StatusLight.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDuration } from '@/lib/format';
import type { Paginated } from '@/types';

type StateValue = 'ready' | 'not_ready' | 'wrap_up' | 'dnd' | 'logged_out';

type LogRow = {
    id: number;
    user_id: number;
    user_name: string | null;
    state: StateValue;
    state_label: string;
    reason: string | null;
    started_at: string;
    ended_at: string | null;
    duration: number;
    ongoing: boolean;
};

type AgentTotals = {
    user_id: number;
    name: string;
    seconds: Record<StateValue, number>;
    pauses: number;
    longest_pause: number;
};

type ReasonTotals = { reason: string; count: number; seconds: number };

const props = defineProps<{
    logs: Paginated<LogRow>;
    summary: {
        pause_seconds: number;
        pauses: number;
        longest_pause: number;
        agents: number;
    };
    perAgent: AgentTotals[];
    reasons: ReasonTotals[];
    filters: {
        from: string;
        to: string;
        agent: number | null;
        state: StateValue | null;
    };
    agents: { id: number; name: string }[];
    states: { value: StateValue; label: string }[];
    maxRangeDays: number;
}>();

const stateVariant: Record<
    StateValue,
    'positive' | 'notice' | 'informative' | 'negative' | 'neutral'
> = {
    ready: 'positive',
    not_ready: 'notice',
    wrap_up: 'informative',
    dnd: 'negative',
    logged_out: 'neutral',
};

const stateColumns: { value: StateValue; label: string }[] = [
    { value: 'ready', label: t('Prêt') },
    { value: 'not_ready', label: t('Non prêt') },
    { value: 'wrap_up', label: 'Post-appel' },
    { value: 'dnd', label: t('Ne pas déranger') },
    { value: 'logged_out', label: t('Déconnecté') },
];

const today = new Date().toISOString().slice(0, 10);

// "all" because a Select item cannot have an empty value.
const form = reactive({
    from: props.filters.from,
    to: props.filters.to,
    agent: props.filters.agent ? String(props.filters.agent) : 'all',
    state: props.filters.state ?? 'all',
});

function buildQuery(page?: number) {
    return {
        ...(form.from && { from: form.from }),
        ...(form.to && { to: form.to }),
        ...(form.agent !== 'all' && { agent: form.agent }),
        ...(form.state !== 'all' && { state: form.state }),
        ...(page && page > 1 && { page }),
    };
}

function applyFilters(page?: number): void {
    router.get(props.logs.path, buildQuery(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(
    () => [form.from, form.to, form.agent, form.state],
    () => applyFilters(),
);

function setRange(days: number): void {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));
    form.from = start.toISOString().slice(0, 10);
    form.to = end.toISOString().slice(0, 10);
}

function focusAgent(userId: number): void {
    form.agent = String(userId);
}

const hasFilters = computed(
    () =>
        form.from !== today ||
        form.to !== today ||
        form.agent !== 'all' ||
        form.state !== 'all',
);

function resetFilters(): void {
    form.from = today;
    form.to = today;
    form.agent = 'all';
    form.state = 'all';
}

const averagePause = computed(() =>
    props.summary.pauses > 0
        ? props.summary.pause_seconds / props.summary.pauses
        : null,
);

const maxReasonSeconds = computed(() =>
    Math.max(1, ...props.reasons.map((reason) => reason.seconds)),
);

const dateTime = new Intl.DateTimeFormat(intlLocale(), {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
});

function formatMoment(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '—';
}
</script>

<template>
    <Head :title="t('Journal des agents')" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <Card>
            <CardHeader>
                <CardTitle>{{ t('Journal des agents') }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            "Chaque changement d'état des agents : pauses et motifs, post-appel, ne pas déranger, déconnexions.",
                        )
                    }}
                </CardDescription>
            </CardHeader>

            <CardContent class="flex flex-col gap-4">
                <div
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.5fr_1.5fr_auto] lg:items-end"
                >
                    <div class="grid gap-2">
                        <Label for="logs-from">{{ t('Du') }}</Label>
                        <Input
                            id="logs-from"
                            v-model="form.from"
                            type="date"
                            :max="form.to || today"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="logs-to">{{ t('Au') }}</Label>
                        <Input
                            id="logs-to"
                            v-model="form.to"
                            type="date"
                            :min="form.from"
                            :max="today"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ t('Agent') }}</Label>
                        <Select v-model="form.agent">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    :placeholder="t('Tous les agents')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{{
                                    t('Tous les agents')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="agent in agents"
                                    :key="agent.id"
                                    :value="String(agent.id)"
                                >
                                    {{ agent.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ t('État') }}</Label>
                        <Select v-model="form.state">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    :placeholder="t('Tous les états')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{{
                                    t('Tous les états')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="state in states"
                                    :key="state.value"
                                    :value="state.value"
                                >
                                    {{ state.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        variant="outline"
                        :disabled="!hasFilters"
                        @click="resetFilters"
                    >
                        {{ t('Réinitialiser') }}
                    </Button>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-muted-foreground">{{
                        t('Période :')
                    }}</span>
                    <Button size="sm" variant="ghost" @click="setRange(1)">{{
                        t("Aujourd'hui")
                    }}</Button>
                    <Button size="sm" variant="ghost" @click="setRange(7)">{{
                        t('7 jours')
                    }}</Button>
                    <Button size="sm" variant="ghost" @click="setRange(30)">{{
                        t('30 jours')
                    }}</Button>
                    <span class="text-xs text-muted-foreground">
                        {{ t('(:p0 jours maximum)', { p0: maxRangeDays }) }}
                    </span>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                :label="t('Temps en pause')"
                :value="formatDuration(summary.pause_seconds)"
                hint="Non prêt, sur la période"
                tone="warn"
            />
            <StatCard
                label="Pauses"
                :value="summary.pauses"
                :hint="`${summary.agents} agent(s) concerné(s)`"
            />
            <StatCard
                :label="t('Pause moyenne')"
                :value="formatDuration(averagePause)"
            />
            <StatCard
                :label="t('Plus longue pause')"
                :value="formatDuration(summary.longest_pause || null)"
                tone="bad"
            />
        </div>

        <div class="grid gap-4 xl:grid-cols-[2fr_1fr]">
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Temps par état') }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'Limité à la période choisie. Cliquez sur un agent pour voir son détail.',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{{ t('Agent') }}</TableHead>
                                    <TableHead
                                        v-for="column in stateColumns"
                                        :key="column.value"
                                        class="text-right"
                                        >{{ column.label }}</TableHead
                                    >
                                    <TableHead class="text-right">{{
                                        t('Pauses')
                                    }}</TableHead>
                                    <TableHead class="text-right">{{
                                        t('Plus longue')
                                    }}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="agent in perAgent"
                                    :key="agent.user_id"
                                    class="cursor-pointer"
                                    :class="{
                                        'bg-accent':
                                            form.agent ===
                                            String(agent.user_id),
                                    }"
                                    @click="focusAgent(agent.user_id)"
                                >
                                    <TableCell class="font-medium">{{
                                        agent.name
                                    }}</TableCell>
                                    <TableCell
                                        v-for="column in stateColumns"
                                        :key="column.value"
                                        class="text-right font-mono text-xs tabular-nums"
                                    >
                                        {{
                                            agent.seconds[column.value]
                                                ? formatDuration(
                                                      agent.seconds[
                                                          column.value
                                                      ],
                                                  )
                                                : '—'
                                        }}
                                    </TableCell>
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{ agent.pauses }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right font-mono text-xs tabular-nums"
                                    >
                                        {{
                                            agent.longest_pause
                                                ? formatDuration(
                                                      agent.longest_pause,
                                                  )
                                                : '—'
                                        }}
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="perAgent.length === 0">
                                    <TableCell
                                        :colspan="stateColumns.length + 3"
                                        class="h-20 text-center text-muted-foreground"
                                    >
                                        {{
                                            t(
                                                'Aucune activité sur cette période.',
                                            )
                                        }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Motifs de pause') }}</CardTitle>
                    <CardDescription>{{
                        t('Temps passé en « Non prêt » par motif.')
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="reasons.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('Aucune pause sur cette période.') }}
                    </p>
                    <ul v-else class="flex flex-col gap-3">
                        <li
                            v-for="reason in reasons"
                            :key="reason.reason"
                            class="grid gap-1"
                        >
                            <div
                                class="flex items-baseline justify-between gap-2 text-sm"
                            >
                                <span class="truncate font-medium">{{
                                    reason.reason
                                }}</span>
                                <span
                                    class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ reason.count }} × ·
                                    {{ formatDuration(reason.seconds) }}
                                </span>
                            </div>
                            <div class="h-1.5 rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-notice"
                                    :style="{
                                        width: `${Math.max(2, (reason.seconds / maxReasonSeconds) * 100)}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t("Détail des changements d'état") }}</CardTitle>
                <CardDescription>{{
                    t('Du plus récent au plus ancien.')
                }}</CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="overflow-x-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Agent') }}</TableHead>
                                <TableHead>{{ t('État') }}</TableHead>
                                <TableHead>{{ t('Motif') }}</TableHead>
                                <TableHead>{{ t('Début') }}</TableHead>
                                <TableHead>{{ t('Fin') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Durée')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="log in logs.data" :key="log.id">
                                <TableCell class="font-medium">{{
                                    log.user_name ?? '—'
                                }}</TableCell>
                                <TableCell>
                                    <StatusLight
                                        :variant="stateVariant[log.state]"
                                        >{{ log.state_label }}</StatusLight
                                    >
                                </TableCell>
                                <TableCell class="text-sm">{{
                                    log.reason ?? '—'
                                }}</TableCell>
                                <TableCell class="text-xs tabular-nums">{{
                                    formatMoment(log.started_at)
                                }}</TableCell>
                                <TableCell class="text-xs tabular-nums">
                                    <span
                                        v-if="log.ongoing"
                                        class="rounded-full border border-informative-border bg-informative-subtle px-2 py-0.5 text-informative-strong"
                                        >{{ t('En cours') }}</span
                                    >
                                    <template v-else>{{
                                        formatMoment(log.ended_at)
                                    }}</template>
                                </TableCell>
                                <TableCell
                                    class="text-right font-mono text-xs tabular-nums"
                                    >{{
                                        formatDuration(log.duration)
                                    }}</TableCell
                                >
                            </TableRow>
                            <TableRow v-if="logs.data.length === 0">
                                <TableCell
                                    colspan="6"
                                    class="h-24 text-center text-muted-foreground"
                                >
                                    {{
                                        t(
                                            "Aucun changement d'état ne correspond à ces critères.",
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <div
                    v-if="logs.total > 0"
                    class="flex flex-col items-center justify-between gap-3 sm:flex-row"
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(':p0–:p1 sur :p2 changements', {
                                p0: logs.from,
                                p1: logs.to,
                                p2: logs.total,
                            })
                        }}
                    </p>

                    <Pagination
                        v-if="logs.total > logs.per_page"
                        v-slot="{ page }"
                        :total="logs.total"
                        :items-per-page="logs.per_page"
                        :page="logs.current_page"
                        :sibling-count="1"
                        show-edges
                        class="mx-0 w-auto"
                        @update:page="applyFilters"
                    >
                        <PaginationContent v-slot="{ items }">
                            <PaginationPrevious />
                            <template
                                v-for="(item, index) in items"
                                :key="index"
                            >
                                <PaginationItem
                                    v-if="item.type === 'page'"
                                    :value="item.value"
                                    :is-active="item.value === page"
                                >
                                    {{ item.value }}
                                </PaginationItem>
                                <PaginationEllipsis v-else :index="index" />
                            </template>
                            <PaginationNext />
                        </PaginationContent>
                    </Pagination>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
