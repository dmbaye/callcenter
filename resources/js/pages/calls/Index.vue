<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

import type { Agent, Call, Filters, Paginated } from '@/types';
import {
    destroy as destroyOutcome,
    update as updateOutcome,
} from '@/routes/call-outcomes';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import StatusLight from '@/components/StatusLight.vue';
import RecordingPlayer from '@/components/calls/RecordingPlayer.vue';
import { formatDuration } from '@/lib/format';
import SelectField from '@/components/SelectField.vue';
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

const props = defineProps<{
    calls: Paginated<Call>;
    outcomes: Paginated<Outcome>;
    filters: Filters;
    agents: Agent[];
    canViewTeam: boolean;
}>();

/** Only one recording plays at a time. */
const playing = ref<string | null>(null);

const hasRecordings = computed(() =>
    props.calls.data.some((call) => call.recording),
);

type Outcome = {
    id: number;
    caller: string;
    direction: string;
    started_at: string;
    ended_at: string | null;
    duration: number | null;
    outcome: string;
    notes: string | null;
};

const outcomeLabels: Record<string, string> = {
    resolved: 'Résolu',
    callback: 'Rappel planifié',
    voicemail: 'Messagerie',
    sale: 'Vente',
    no_sale: 'Pas de vente',
    wrong_number: 'Mauvais numéro',
    other: 'Autre',
};

const outcomeOptions = Object.entries(outcomeLabels).map(([value, label]) => ({
    value,
    label,
}));

const outcomeStyles: Record<string, string> = {
    resolved: 'bg-positive-subtle text-positive-strong border-positive-border',
    callback:
        'bg-informative-subtle text-informative-strong border-informative-border',
    voicemail: 'bg-notice-subtle text-notice-strong border-notice-border',
    sale: 'bg-positive-subtle text-positive-strong border-positive-border',
    no_sale: 'bg-negative-subtle text-negative-strong border-negative-border',
    wrong_number: 'bg-muted text-muted-foreground border-border',
    other: 'bg-muted text-muted-foreground border-border',
};

const page = usePage();
const teamSlug = computed(
    () => (page.props.currentTeam as { slug?: string } | null)?.slug ?? '',
);

const editingId = ref<number | null>(null);
const editForm = reactive({ outcome: '', notes: '' });

function startEdit(outcome: Outcome): void {
    editingId.value = outcome.id;
    editForm.outcome = outcome.outcome;
    editForm.notes = outcome.notes ?? '';
}

function saveEdit(id: number): void {
    router.put(
        updateOutcome.url([teamSlug.value, id]),
        { outcome: editForm.outcome, notes: editForm.notes || null },
        {
            preserveScroll: true,
            onSuccess: () => (editingId.value = null),
        },
    );
}

function deleteOutcome(id: number): void {
    if (!confirm(t('Supprimer ce résultat ?'))) return;
    router.delete(destroyOutcome.url([teamSlug.value, id]), {
        preserveScroll: true,
    });
}

// "all" is used because a Select item cannot have an empty value
const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'all',
    agent: props.filters.agent ?? 'all',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const statuses = [
    { value: 'ANSWERED', label: t('Répondu') },
    { value: 'NO ANSWER', label: t('Sans réponse') },
    { value: 'BUSY', label: t('Occupé') },
    { value: 'FAILED', label: t('Échoué') },
];

const dispositionLabel = (disposition: string): string =>
    statuses.find((status) => status.value === disposition)?.label ??
    (disposition || '–');
const dispositionVariant = (disposition: string) =>
    disposition === 'ANSWERED'
        ? 'positive'
        : disposition === 'BUSY'
          ? 'notice'
          : disposition === 'FAILED'
            ? 'negative'
            : 'neutral';

const hasFilters = computed(
    () =>
        form.search !== '' ||
        form.status !== 'all' ||
        form.agent !== 'all' ||
        form.from !== '' ||
        form.to !== '',
);

// Only send non-empty filters; page is left out so results restart at page 1
function buildQuery(page?: number) {
    return {
        ...(form.search && { search: form.search }),
        ...(form.status !== 'all' && { status: form.status }),
        ...(form.agent !== 'all' && { agent: form.agent }),
        ...(form.from && { from: form.from }),
        ...(form.to && { to: form.to }),
        ...(page && page > 1 && { page }),
    };
}

// The outcomes list has its own paginator (outcomes_page); keep the calls page and filters.
function goToOutcomesPage(outcomesPage: number) {
    router.get(
        props.calls.path,
        {
            ...buildQuery(props.calls.current_page),
            ...(outcomesPage > 1 && { outcomes_page: outcomesPage }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function applyFilters(page?: number) {
    router.get(props.calls.path, buildQuery(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

// Debounce typing in the search box; other filters apply immediately
let timer: ReturnType<typeof setTimeout>;
watch(
    () => form.search,
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => applyFilters(), 400);
    },
);
watch(
    () => [form.status, form.agent, form.from, form.to],
    () => applyFilters(),
);

function resetFilters() {
    clearTimeout(timer);
    form.search = '';
    form.status = 'all';
    form.agent = 'all';
    form.from = '';
    form.to = '';
    // watchers fire and send the cleared query
}
</script>

<template>
    <Head title="Appels" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <Card>
            <CardHeader>
                <CardTitle>{{ t('Appels') }}</CardTitle>
                <CardDescription>{{
                    t("L'historique des appels")
                }}</CardDescription>
            </CardHeader>

            <CardContent class="flex flex-col gap-6">
                <!-- Filters -->
                <div
                    class="grid gap-4 sm:grid-cols-2 lg:items-end"
                    :class="
                        canViewTeam
                            ? 'lg:grid-cols-[2fr_1fr_1fr_1fr_1fr_auto]'
                            : 'lg:grid-cols-[2fr_1fr_1fr_1fr_auto]'
                    "
                >
                    <div class="grid gap-2">
                        <Label for="search">{{ t('Numéro') }}</Label>
                        <Input
                            id="search"
                            v-model="form.search"
                            type="search"
                            :placeholder="t('Rechercher un numéro…')"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label>{{ t('Statut') }}</Label>
                        <Select v-model="form.status">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    :placeholder="t('Tous les statuts')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    {{ t('Tous les statuts') }}
                                </SelectItem>
                                <SelectItem
                                    v-for="s in statuses"
                                    :key="s.value"
                                    :value="s.value"
                                >
                                    {{ s.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-if="canViewTeam" class="grid gap-2">
                        <Label>{{ t('Agent') }}</Label>
                        <Select v-model="form.agent">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    :placeholder="t('Tous les agents')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    {{ t('Tous les agents') }}
                                </SelectItem>
                                <SelectItem
                                    v-for="a in agents"
                                    :key="a.extension"
                                    :value="a.extension"
                                >
                                    {{ a.name }} ({{ a.extension }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="from">{{ t('Du') }}</Label>
                        <Input id="from" v-model="form.from" type="date" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="to">{{ t('Au') }}</Label>
                        <Input id="to" v-model="form.to" type="date" />
                    </div>

                    <Button
                        variant="outline"
                        :disabled="!hasFilters"
                        @click="resetFilters"
                    >
                        {{ t('Réinitialiser') }}
                    </Button>
                </div>

                <!-- Table -->
                <div class="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Date') }}</TableHead>
                                <TableHead v-if="canViewTeam">{{
                                    t('Agent')
                                }}</TableHead>
                                <TableHead>{{ t('Numéro') }}</TableHead>
                                <TableHead>{{ t('DMC') }}</TableHead>
                                <TableHead>{{ t('DMT') }}</TableHead>
                                <TableHead>{{ t('Statut') }}</TableHead>
                                <TableHead v-if="hasRecordings">{{
                                    t('Enregistrement')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="call in calls.data"
                                :key="call.uniqueid"
                            >
                                <TableCell class="font-medium">
                                    {{ call.start }}
                                </TableCell>
                                <TableCell v-if="canViewTeam">
                                    {{ call.agent_name ?? call.agent ?? '—' }}
                                </TableCell>
                                <TableCell>{{ call.src }}</TableCell>
                                <TableCell>
                                    {{ formatDuration(call.billsec) }}
                                </TableCell>
                                <TableCell>
                                    {{ formatDuration(call.duration) }}
                                </TableCell>
                                <TableCell>
                                    <StatusLight
                                        :variant="
                                            dispositionVariant(call.disposition)
                                        "
                                        >{{
                                            dispositionLabel(call.disposition)
                                        }}</StatusLight
                                    >
                                </TableCell>
                                <TableCell v-if="hasRecordings">
                                    <RecordingPlayer
                                        v-if="call.recording"
                                        :url="call.recording.url"
                                        :duration="call.recording.duration"
                                        :open="playing === call.uniqueid"
                                        @play="playing = call.uniqueid"
                                    />
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </TableCell>
                            </TableRow>

                            <TableRow v-if="calls.data.length === 0">
                                <TableCell
                                    :colspan="
                                        (canViewTeam ? 6 : 5) +
                                        (hasRecordings ? 1 : 0)
                                    "
                                    class="h-24 text-center text-muted-foreground"
                                >
                                    {{
                                        t(
                                            'Aucun appel ne correspond à ces critères.',
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <!-- Footer: counter + pagination -->
                <div
                    v-if="calls.total > 0"
                    class="flex flex-col items-center justify-between gap-3 sm:flex-row"
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(':p0–:p1 sur :p2 appels', {
                                p0: calls.from,
                                p1: calls.to,
                                p2: calls.total,
                            })
                        }}
                    </p>

                    <Pagination
                        v-if="calls.total > calls.per_page"
                        v-slot="{ page }"
                        :total="calls.total"
                        :items-per-page="calls.per_page"
                        :page="calls.current_page"
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

        <Card>
            <CardHeader>
                <CardTitle>{{ t("Mes résultats d'appels") }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'Les résultats saisis après chaque appel (50 derniers).',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="outcomes.data.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Aucun résultat pour le moment — il apparaîtra ici après votre prochain appel.',
                        )
                    }}
                </p>
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Numéro') }}</TableHead>
                            <TableHead>{{ t('Sens') }}</TableHead>
                            <TableHead>{{ t('Date') }}</TableHead>
                            <TableHead>{{ t('Durée') }}</TableHead>
                            <TableHead>{{ t('Résultat') }}</TableHead>
                            <TableHead>{{ t('Notes') }}</TableHead>
                            <TableHead class="w-28 text-right">{{
                                t('Actions')
                            }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template
                            v-for="outcome in outcomes.data"
                            :key="outcome.id"
                        >
                            <TableRow>
                                <TableCell class="font-mono text-xs">{{
                                    outcome.caller
                                }}</TableCell>
                                <TableCell class="text-xs">
                                    {{
                                        outcome.direction === 'inbound'
                                            ? 'Entrant'
                                            : 'Sortant'
                                    }}
                                </TableCell>
                                <TableCell class="text-xs">
                                    {{
                                        new Date(
                                            outcome.started_at,
                                        ).toLocaleString(intlLocale())
                                    }}
                                </TableCell>
                                <TableCell class="font-mono text-xs">
                                    {{ formatDuration(outcome.duration) }}
                                </TableCell>
                                <TableCell>
                                    <span
                                        class="rounded-full border px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            outcomeStyles[outcome.outcome] ??
                                            outcomeStyles.other
                                        "
                                    >
                                        {{
                                            outcomeLabels[outcome.outcome] ??
                                            outcome.outcome
                                        }}
                                    </span>
                                </TableCell>
                                <TableCell
                                    class="max-w-56 truncate text-xs text-muted-foreground"
                                >
                                    {{ outcome.notes ?? '–' }}
                                </TableCell>
                                <TableCell class="space-x-2 text-right">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="startEdit(outcome)"
                                    >
                                        {{ t('Modifier') }}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        @click="deleteOutcome(outcome.id)"
                                    >
                                        {{ t('Supprimer') }}
                                    </Button>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="editingId === outcome.id">
                                <TableCell colspan="7">
                                    <div
                                        class="flex flex-wrap items-end gap-3 py-2"
                                    >
                                        <div class="grid gap-1.5">
                                            <Label
                                                :for="`outcome-${outcome.id}`"
                                                >{{ t('Résultat') }}</Label
                                            >
                                            <SelectField
                                                :id="`outcome-${outcome.id}`"
                                                v-model="editForm.outcome"
                                                :options="outcomeOptions"
                                                class="w-56"
                                            />
                                        </div>
                                        <div
                                            class="grid min-w-64 flex-1 gap-1.5"
                                        >
                                            <Label
                                                :for="`notes-${outcome.id}`"
                                                >{{ t('Notes') }}</Label
                                            >
                                            <Input
                                                :id="`notes-${outcome.id}`"
                                                v-model="editForm.notes"
                                            />
                                        </div>
                                        <Button
                                            size="sm"
                                            @click="saveEdit(outcome.id)"
                                        >
                                            {{ t('Enregistrer') }}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="editingId = null"
                                        >
                                            {{ t('Annuler') }}
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableBody>
                </Table>

                <div
                    v-if="outcomes.total > 0"
                    class="flex flex-col items-center justify-between gap-3 sm:flex-row"
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(':p0–:p1 sur :p2 codifications', {
                                p0: outcomes.from,
                                p1: outcomes.to,
                                p2: outcomes.total,
                            })
                        }}
                    </p>

                    <Pagination
                        v-if="outcomes.total > outcomes.per_page"
                        v-slot="{ page }"
                        :total="outcomes.total"
                        :items-per-page="outcomes.per_page"
                        :page="outcomes.current_page"
                        :sibling-count="1"
                        show-edges
                        class="mx-0 w-auto"
                        @update:page="goToOutcomesPage"
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
