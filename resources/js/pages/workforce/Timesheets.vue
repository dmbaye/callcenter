<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    ChevronLeft,
    ChevronRight,
    Download,
    FileSpreadsheet,
    Pencil,
    Printer,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import StatusPill from '@/components/workforce/StatusPill.vue';
import WorkforceNav from '@/components/workforce/WorkforceNav.vue';
import { t } from '@/lib/i18n';
import { formatDay, formatTime, hours, planningUrl } from '@/lib/workforce';
import type {
    AdjustmentCard,
    SheetDay,
    SheetTotals,
    Team,
    Timesheet,
    WorkforceShell,
} from '@/types';

const props = defineProps<{
    workforce: WorkforceShell;
    weekStart: string;
    previous: string;
    next: string;
    agent: { id: number; name: string; self: boolean };
    team:
        | {
              user: { id: number; name: string };
              totals: SheetTotals;
              approval: Timesheet['approval'];
          }[]
        | null;
    sheet: Timesheet | null;
    canValidate: boolean;
    adjustments: AdjustmentCard[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Planning',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning`
                    : '/',
            },
            {
                title: 'Feuilles de temps',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning/timesheets`
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');

/** Supervisor opened an agent's sheet (not their own, not the team list). */
const viewingAgent = computed(
    () =>
        props.workforce.isSupervisor &&
        props.sheet !== null &&
        !props.agent.self,
);
const userQuery = computed(() =>
    viewingAgent.value ? props.agent.id : undefined,
);

function go(week: string) {
    router.get(
        planningUrl(slug.value, 'timesheets', { week, user: userQuery.value }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function exportUrl(format: 'csv' | 'xlsx' | 'print') {
    // Team view exports everyone; a sheet exports that agent.
    return planningUrl(slug.value, 'timesheets/export', {
        week: props.weekStart,
        format,
        user: props.team ? undefined : props.agent.id,
    });
}

function varianceClass(minutes: number) {
    if (minutes < -5) {
        return 'text-negative-strong';
    }

    return minutes > 5 ? 'text-positive-strong' : 'text-muted-foreground';
}

function signed(minutes: number) {
    return `${minutes > 0 ? '+' : ''}${hours(minutes)}`;
}

const flagTone: Record<string, string> = {
    late: 'bg-notice-subtle text-notice-strong',
    early: 'bg-notice-subtle text-notice-strong',
    absent: 'bg-negative-subtle text-negative-strong',
    open: 'bg-negative-subtle text-negative-strong',
    unscheduled: 'bg-muted text-muted-foreground',
};

function approve() {
    router.post(
        planningUrl(slug.value, 'timesheets/approve'),
        { user_id: props.agent.id, week: props.weekStart },
        { preserveScroll: true },
    );
}

function reopen() {
    router.post(
        planningUrl(slug.value, 'timesheets/reopen'),
        { user_id: props.agent.id, week: props.weekStart },
        { preserveScroll: true },
    );
}

// Correction request (agent, own sheet).
const fixOpen = ref(false);
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const fix = ref({
    time_entry_id: null as number | null,
    date: '',
    clock_in: '',
    clock_out: '',
    break_minutes: 0,
    reason: '',
});

function openFix(day: SheetDay, entry?: SheetDay['entries'][number]) {
    const shift = day.shifts[0];
    errors.value = {};
    fix.value = {
        time_entry_id: entry?.id ?? null,
        date: day.date,
        clock_in: entry
            ? formatTime(entry.clockIn)
            : shift
              ? formatTime(shift.startsAt)
              : '08:00',
        clock_out: entry?.clockOut
            ? formatTime(entry.clockOut)
            : shift
              ? formatTime(shift.endsAt)
              : '16:00',
        break_minutes: entry?.breakMinutes ?? 0,
        reason: '',
    };
    fixOpen.value = true;
}

function submitFix() {
    processing.value = true;
    router.post(
        planningUrl(slug.value, 'timesheets/adjustments'),
        { ...fix.value },
        {
            preserveScroll: true,
            onSuccess: () => (fixOpen.value = false),
            onError: (e) => {
                errors.value = e;
                toast.error(Object.values(e)[0] ?? t('Action impossible.'));
            },
            onFinish: () => (processing.value = false),
        },
    );
}

const adjustmentStatus: Record<string, string> = {
    pending: 'En attente',
    approved: 'Approuvée',
    rejected: 'Refusée',
};

const canRequestFix = computed(
    () => props.agent.self && !props.sheet?.approval,
);
</script>

<template>
    <Head :title="t('Feuilles de temps')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <WorkforceNav :workforce="workforce" current="timesheets" />

        <header class="flex flex-wrap items-center gap-2">
            <Link
                v-if="viewingAgent"
                :href="planningUrl(slug, 'timesheets', { week: weekStart })"
                class="mr-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" />
                {{ t("L'équipe") }}
            </Link>
            <Button
                variant="outline"
                size="icon"
                :aria-label="t('Semaine précédente')"
                @click="go(previous)"
            >
                <ChevronLeft class="size-4" />
            </Button>
            <Button variant="outline" size="sm" @click="go(workforce.today)">{{
                t('Cette semaine')
            }}</Button>
            <Button
                variant="outline"
                size="icon"
                :aria-label="t('Semaine suivante')"
                @click="go(next)"
            >
                <ChevronRight class="size-4" />
            </Button>
            <h1 class="text-lg font-semibold">
                <template v-if="sheet && !agent.self"
                    >{{ agent.name }} ·
                </template>
                {{
                    t('Semaine du :date', {
                        date: formatDay(weekStart, {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                        }),
                    })
                }}
            </h1>
            <div class="ml-auto flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    as="a"
                    :href="exportUrl('csv')"
                >
                    <Download class="size-4" />
                    CSV
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    as="a"
                    :href="exportUrl('xlsx')"
                >
                    <FileSpreadsheet class="size-4" />
                    Excel
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    as="a"
                    :href="exportUrl('print')"
                    target="_blank"
                    rel="noopener"
                >
                    <Printer class="size-4" />
                    PDF
                </Button>
            </div>
        </header>

        <!-- Team overview (supervisors) -->
        <div v-if="team" class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">
                            {{ t('Agent') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ t('Planifié') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ t('Travaillé') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ t('Congés') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ t('Écart') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ t('Retards') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ t('Absences') }}
                        </th>
                        <th scope="col" class="px-4 py-2 font-medium">
                            {{ t('Validation') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="row in team"
                        :key="row.user.id"
                        class="hover:bg-muted/30"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="
                                    planningUrl(slug, 'timesheets', {
                                        week: weekStart,
                                        user: row.user.id,
                                    })
                                "
                                class="font-medium hover:underline"
                            >
                                {{ row.user.name }}
                            </Link>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ hours(row.totals.scheduled) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ hours(row.totals.worked) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{
                                row.totals.leave ? hours(row.totals.leave) : '—'
                            }}
                        </td>
                        <td
                            class="px-3 py-2 text-right tabular-nums"
                            :class="varianceClass(row.totals.variance)"
                        >
                            {{ signed(row.totals.variance) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ row.totals.late || '—' }}
                        </td>
                        <td
                            class="px-3 py-2 text-right tabular-nums"
                            :class="
                                row.totals.absences
                                    ? 'text-negative-strong'
                                    : ''
                            "
                        >
                            {{ row.totals.absences || '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <span
                                v-if="row.approval"
                                class="inline-flex items-center gap-1 text-positive-strong"
                            >
                                <BadgeCheck class="size-4" />
                                {{ t('Validée') }}
                            </span>
                            <span v-else class="text-muted-foreground">{{
                                t('À valider')
                            }}</span>
                        </td>
                    </tr>
                    <tr v-if="team.length === 0">
                        <td
                            colspan="8"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            {{
                                t(
                                    'Personne de planifié ni de pointé cette semaine.',
                                )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- One sheet -->
        <template v-if="sheet">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">
                        {{ t('Planifié') }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ hours(sheet.totals.scheduled) }}
                    </p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">
                        {{ t('Travaillé') }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ hours(sheet.totals.worked) }}
                    </p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">
                        {{ t('Écart') }}
                    </p>
                    <p
                        class="text-lg font-semibold tabular-nums"
                        :class="varianceClass(sheet.totals.variance)"
                    >
                        {{ signed(sheet.totals.variance) }}
                    </p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">
                        {{ t('Pauses') }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ hours(sheet.totals.breaks) }}
                    </p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">
                        {{ t('Congés') }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ hours(sheet.totals.leave) }}
                    </p>
                </div>
                <div
                    class="flex flex-col justify-center gap-2 rounded-lg border p-3"
                >
                    <template v-if="sheet.approval">
                        <p
                            class="inline-flex items-center gap-1 text-sm font-medium text-positive-strong"
                        >
                            <BadgeCheck class="size-4" />
                            {{ t('Validée') }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ sheet.approval.by }}
                        </p>
                        <Button
                            v-if="canValidate"
                            variant="ghost"
                            size="sm"
                            class="justify-start px-0"
                            @click="reopen"
                        >
                            <Undo2 class="size-4" />
                            {{ t('Rouvrir') }}
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm text-muted-foreground">
                            {{ t('Non validée') }}
                        </p>
                        <Button v-if="canValidate" size="sm" @click="approve">
                            <BadgeCheck class="size-4" />
                            {{ t('Valider la semaine') }}
                        </Button>
                    </template>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[820px] text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                {{ t('Jour') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Planifié') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Pointages') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                {{ t('Pause') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                {{ t('Travaillé') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                {{ t('Écart') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Remarques') }}
                            </th>
                            <th
                                v-if="canRequestFix"
                                scope="col"
                                class="w-10 px-2 py-2"
                            >
                                <span class="sr-only">{{ t('Actions') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="day in sheet.days"
                            :key="day.date"
                            :class="
                                day.date === workforce.today
                                    ? 'bg-primary/5'
                                    : ''
                            "
                        >
                            <td
                                class="px-4 py-2 font-medium whitespace-nowrap capitalize"
                            >
                                {{
                                    formatDay(day.date, {
                                        weekday: 'short',
                                        day: 'numeric',
                                        month: 'short',
                                    })
                                }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                <template v-if="day.shifts.length">
                                    <div
                                        v-for="shift in day.shifts"
                                        :key="shift.id"
                                    >
                                        <span class="font-medium">{{
                                            shift.code
                                        }}</span>
                                        {{ shift.window }}
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                                <div
                                    v-if="day.leaveMinutes"
                                    class="text-xs text-notice-strong"
                                >
                                    {{
                                        t('Congé :time', {
                                            time: hours(day.leaveMinutes),
                                        })
                                    }}
                                </div>
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                <div
                                    v-for="entry in day.entries"
                                    :key="entry.id"
                                    class="flex items-center gap-1"
                                >
                                    {{ formatTime(entry.clockIn) }} –
                                    {{
                                        entry.clockOut
                                            ? formatTime(entry.clockOut)
                                            : t('en cours')
                                    }}
                                    <span
                                        v-if="entry.source === 'manual'"
                                        class="text-xs text-muted-foreground"
                                        :title="t('Corrigé')"
                                        >✎</span
                                    >
                                    <button
                                        v-if="canRequestFix && entry.clockOut"
                                        type="button"
                                        class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                        :aria-label="t('Corriger ce pointage')"
                                        @click="openFix(day, entry)"
                                    >
                                        <Pencil class="size-3" />
                                    </button>
                                </div>
                                <span
                                    v-if="day.entries.length === 0"
                                    class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{
                                    day.breakMinutes
                                        ? hours(day.breakMinutes)
                                        : '—'
                                }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{
                                    day.workedMinutes
                                        ? hours(day.workedMinutes)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-3 py-2 text-right tabular-nums"
                                :class="varianceClass(day.varianceMinutes)"
                            >
                                {{
                                    day.varianceMinutes
                                        ? signed(day.varianceMinutes)
                                        : '—'
                                }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="flag in day.flags"
                                        :key="flag.key"
                                        class="rounded px-1.5 py-0.5 text-xs"
                                        :class="
                                            flagTone[flag.key] ?? 'bg-muted'
                                        "
                                    >
                                        {{ flag.label }}
                                    </span>
                                    <span
                                        v-if="day.pendingAdjustments"
                                        class="rounded bg-notice-subtle px-1.5 py-0.5 text-xs text-notice-strong"
                                    >
                                        {{ t('Correction en attente') }}
                                    </span>
                                </div>
                            </td>
                            <td v-if="canRequestFix" class="px-2 py-2">
                                <Button
                                    v-if="day.date <= workforce.today"
                                    variant="ghost"
                                    size="icon"
                                    class="size-7"
                                    :aria-label="t('Demander une correction')"
                                    :title="t('Demander une correction')"
                                    @click="
                                        openFix(
                                            day,
                                            day.entries.find((e) => e.clockOut),
                                        )
                                    "
                                >
                                    <Pencil class="size-3.5" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <section v-if="adjustments.length" class="grid gap-3">
                <h2 class="text-base font-semibold">
                    {{ t('Corrections demandées') }}
                </h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="item in adjustments"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-2.5"
                    >
                        <StatusPill
                            :status="item.status"
                            :label="
                                t(adjustmentStatus[item.status] ?? item.status)
                            "
                        />
                        <span class="capitalize">{{
                            formatDay(item.date)
                        }}</span>
                        <span class="tabular-nums">
                            {{ formatTime(item.clockIn) }} –
                            {{ formatTime(item.clockOut) }}
                            <template v-if="item.previous?.clock_in_at">
                                <span class="text-muted-foreground">
                                    ({{ t('avant :') }}
                                    {{ formatTime(item.previous.clock_in_at) }}
                                    –
                                    {{
                                        formatTime(item.previous.clock_out_at)
                                    }})
                                </span>
                            </template>
                        </span>
                        <span
                            class="min-w-0 flex-1 truncate text-muted-foreground"
                            :title="item.reason"
                            >{{ item.reason }}</span
                        >
                        <span
                            v-if="item.reviewNote"
                            class="text-xs text-muted-foreground"
                            >{{ item.reviewer }} : {{ item.reviewNote }}</span
                        >
                    </li>
                </ul>
            </section>
        </template>
    </div>

    <Dialog v-model:open="fixOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('Demander une correction') }}</DialogTitle>
                <DialogDescription class="capitalize">
                    {{
                        fix.date
                            ? formatDay(fix.date, {
                                  weekday: 'long',
                                  day: 'numeric',
                                  month: 'long',
                              })
                            : ''
                    }}
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="fix-in">{{ t('Arrivée') }}</Label>
                        <Input
                            id="fix-in"
                            v-model="fix.clock_in"
                            type="time"
                            required
                        />
                        <InputError :message="errors.clock_in" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="fix-out">{{ t('Départ') }}</Label>
                        <Input
                            id="fix-out"
                            v-model="fix.clock_out"
                            type="time"
                            required
                        />
                        <InputError :message="errors.clock_out" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="fix-break">{{ t('Pause (min)') }}</Label>
                        <Input
                            id="fix-break"
                            v-model.number="fix.break_minutes"
                            type="number"
                            min="0"
                            max="600"
                        />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Un départ avant l’arrivée compte le lendemain (poste de nuit).',
                        )
                    }}
                </p>
                <div class="grid gap-2">
                    <Label for="fix-reason">{{ t('Motif') }}</Label>
                    <Textarea
                        id="fix-reason"
                        v-model="fix.reason"
                        rows="3"
                        maxlength="500"
                        :placeholder="t('Ex. : oubli de pointage au départ')"
                    />
                    <InputError :message="errors.reason ?? errors.date" />
                </div>
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="fixOpen = false">{{
                    t('Annuler')
                }}</Button>
                <Button
                    :disabled="processing || fix.reason.trim() === ''"
                    @click="submitFix"
                    >{{ t('Envoyer') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
