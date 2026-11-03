<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    BellRing,
    CalendarPlus,
    ChevronLeft,
    ChevronRight,
    Copy,
    Info,
    Plus,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import ShiftChip from '@/components/workforce/ShiftChip.vue';
import WorkforceNav from '@/components/workforce/WorkforceNav.vue';
import { t } from '@/lib/i18n';
import { formatDay, planningUrl, shiftColor } from '@/lib/workforce';
import type {
    LeaveCard,
    ShiftCard,
    ShiftTemplateCard,
    Team,
    WorkforceDay,
    WorkforceShell,
} from '@/types';

const props = defineProps<{
    workforce: WorkforceShell;
    view: 'week' | 'month';
    scope: 'mine' | 'all';
    hasOwnAgents: boolean;
    anchor: string;
    from: string;
    to: string;
    previous: string;
    next: string;
    days: WorkforceDay[];
    agents: {
        id: number;
        name: string;
        jobTitle: string | null;
        avatar: string | null;
        supervisor: string | null;
        managed: boolean;
    }[];
    templates: ShiftTemplateCard[];
    assignments: Record<string, Record<string, ShiftCard[]>>;
    leaves: LeaveCard[];
    coverage: Record<
        string,
        Record<string, { assigned: number; min: number; short: boolean }>
    >;
    rules: {
        maxConsecutiveHours: number;
        minRestHours: number;
        minRestAfterLongHours: number;
        maxWeeklyHours: number;
        enforcement: 'block' | 'warn';
    };
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
                title: 'Roster',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning/roster`
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const busy = ref(false);

const activeTemplates = computed(() =>
    props.templates.filter((tpl) => tpl.active),
);
const isMonth = computed(() => props.view === 'month');

const title = computed(() => {
    if (isMonth.value) {
        return formatDay(props.from, { month: 'long', year: 'numeric' });
    }

    return `${formatDay(props.from, { day: 'numeric', month: 'short' })} – ${formatDay(props.to, { day: 'numeric', month: 'short', year: 'numeric' })}`;
});

function go(query: Record<string, string>) {
    router.get(
        planningUrl(slug.value, 'roster', {
            view: props.view,
            scope: props.scope,
            ...query,
        }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function shiftsOf(userId: number, date: string): ShiftCard[] {
    return props.assignments[String(userId)]?.[date] ?? [];
}

function leaveOf(userId: number, date: string): LeaveCard | undefined {
    return props.leaves.find(
        (leave) =>
            leave.user.id === userId &&
            leave.startDate <= date &&
            leave.endDate >= date,
    );
}

/** Hours scheduled for an agent over the visible range. */
function agentHours(userId: number): number {
    const byDate = props.assignments[String(userId)] ?? {};
    let minutes = 0;

    for (const shifts of Object.values(byDate)) {
        for (const shift of shifts) {
            const start = new Date(shift.startsAt.replace(' ', 'T'));
            const end = new Date(shift.endsAt.replace(' ', 'T'));
            minutes += (end.getTime() - start.getTime()) / 60000;
        }
    }

    return Math.round(minutes / 6) / 10;
}

const shortages = computed(() => {
    let count = 0;

    for (const day of Object.values(props.coverage)) {
        for (const cell of Object.values(day)) {
            if (cell.short) {
                count++;
            }
        }
    }

    return count;
});

function showErrors(errors: Record<string, string | string[]>) {
    const messages = Object.values(errors).flat();
    toast.error(messages[0] ?? t('Affectation refusée.'), {
        description: messages.slice(1, 4).join('\n') || undefined,
        duration: 8000,
    });
}

function assign(
    userId: number,
    templateIds: number[],
    dates: string[],
    onDone?: () => void,
) {
    if (templateIds.length === 0 || dates.length === 0) {
        return;
    }

    const [first, ...rest] = templateIds;
    busy.value = true;

    router.post(
        planningUrl(slug.value, 'assignments'),
        { user_id: userId, shift_template_id: first, dates },
        {
            preserveScroll: true,
            onError: showErrors,
            onSuccess: () => {
                if (rest.length) {
                    assign(userId, rest, dates, onDone);
                } else {
                    onDone?.();
                }
            },
            onFinish: () => {
                if (rest.length === 0) {
                    busy.value = false;
                }
            },
        },
    );
}

function remove(shift: ShiftCard) {
    router.delete(planningUrl(slug.value, `assignments/${shift.id}`), {
        preserveScroll: true,
        onError: showErrors,
    });
}

/** The two default blocks cover 16 h when chained. */
const combo = computed(() => {
    const list = activeTemplates.value;

    for (const first of list) {
        const second = list.find(
            (tpl) => tpl.id !== first.id && tpl.startTime === first.endTime,
        );

        if (second) {
            return [first, second] as const;
        }
    }

    return null;
});

function copyWeek() {
    router.post(
        planningUrl(slug.value, 'roster/copy-week'),
        { week: props.from },
        { preserveScroll: true, onError: showErrors },
    );
}

function notifyWeek() {
    router.post(
        planningUrl(slug.value, 'roster/notify-week'),
        { week: props.from },
        { preserveScroll: true, onError: showErrors },
    );
}

// Bulk assignment dialog.
const bulkOpen = ref(false);
const bulk = ref<{ userId: string; templateIds: number[]; dates: string[] }>({
    userId: '',
    templateIds: [],
    dates: [],
});

function openBulk(userId?: number) {
    bulk.value = {
        userId: userId ? String(userId) : '',
        templateIds: activeTemplates.value[0]
            ? [activeTemplates.value[0].id]
            : [],
        dates: props.days
            .filter((day) => !day.weekend && day.date >= props.workforce.today)
            .map((day) => day.date),
    };
    bulkOpen.value = true;
}

function toggle<T>(list: T[], value: T, on: boolean | 'indeterminate') {
    const index = list.indexOf(value);

    if (on === true && index === -1) {
        list.push(value);
    } else if (on !== true && index !== -1) {
        list.splice(index, 1);
    }
}

function submitBulk() {
    assign(
        Number(bulk.value.userId),
        [...bulk.value.templateIds],
        [...bulk.value.dates],
        () => {
            bulkOpen.value = false;
        },
    );
}

const bulkHours = computed(() =>
    activeTemplates.value
        .filter((tpl) => bulk.value.templateIds.includes(tpl.id))
        .reduce((sum, tpl) => sum + tpl.hours, 0),
);
</script>

<template>
    <Head :title="t('Roster')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <WorkforceNav :workforce="workforce" current="roster" />

        <header class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="icon"
                    :aria-label="t('Précédent')"
                    @click="go({ date: previous })"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="go({ date: workforce.today })"
                >
                    {{ t("Aujourd'hui") }}
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    :aria-label="t('Suivant')"
                    @click="go({ date: next })"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
            <h1 class="text-lg font-semibold capitalize">{{ title }}</h1>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div
                    v-if="hasOwnAgents"
                    class="inline-flex rounded-md border p-0.5"
                    role="group"
                    :aria-label="t('Agents affichés')"
                >
                    <Link
                        :href="
                            planningUrl(slug, 'roster', {
                                view,
                                date: anchor,
                                scope: 'mine',
                            })
                        "
                        preserve-scroll
                        class="rounded px-3 py-1 text-sm"
                        :class="
                            scope === 'mine'
                                ? 'bg-muted font-medium'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t('Mes agents') }}
                    </Link>
                    <Link
                        :href="
                            planningUrl(slug, 'roster', {
                                view,
                                date: anchor,
                                scope: 'all',
                            })
                        "
                        preserve-scroll
                        class="rounded px-3 py-1 text-sm"
                        :class="
                            scope === 'all'
                                ? 'bg-muted font-medium'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t("Toute l'équipe") }}
                    </Link>
                </div>
                <div class="inline-flex rounded-md border p-0.5" role="group">
                    <Link
                        :href="
                            planningUrl(slug, 'roster', {
                                view: 'week',
                                date: anchor,
                                scope,
                            })
                        "
                        preserve-scroll
                        class="rounded px-3 py-1 text-sm"
                        :class="
                            !isMonth
                                ? 'bg-muted font-medium'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t('Semaine') }}
                    </Link>
                    <Link
                        :href="
                            planningUrl(slug, 'roster', {
                                view: 'month',
                                date: anchor,
                                scope,
                            })
                        "
                        preserve-scroll
                        class="rounded px-3 py-1 text-sm"
                        :class="
                            isMonth
                                ? 'bg-muted font-medium'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t('Mois') }}
                    </Link>
                </div>
                <template v-if="!isMonth">
                    <Button variant="outline" size="sm" @click="copyWeek">
                        <Copy class="size-4" />
                        {{ t('Copier la semaine précédente') }}
                    </Button>
                    <Button variant="outline" size="sm" @click="notifyWeek">
                        <BellRing class="size-4" />
                        {{ t("Prévenir l'équipe") }}
                    </Button>
                </template>
                <Button
                    size="sm"
                    :disabled="agents.length === 0"
                    @click="openBulk()"
                >
                    <CalendarPlus class="size-4" />
                    {{ t('Affecter') }}
                </Button>
            </div>
        </header>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground"
        >
            <span
                v-for="tpl in activeTemplates"
                :key="tpl.id"
                class="inline-flex items-center gap-1.5"
            >
                <span
                    class="size-2.5 rounded-full"
                    :class="shiftColor(tpl.color).dot"
                />
                <span class="font-medium text-foreground">{{ tpl.code }}</span>
                {{ tpl.name }} · {{ tpl.window }}
                <span v-if="tpl.minAgents"
                    >· {{ t('min. :n', { n: tpl.minAgents }) }}</span
                >
            </span>
            <span class="inline-flex items-center gap-1.5">
                <Info class="size-3.5" />
                {{
                    t(
                        'Max :max h d’affilée · repos :rest h (:long h après un long poste) · :week h/semaine · :mode',
                        {
                            max: rules.maxConsecutiveHours,
                            rest: rules.minRestHours,
                            long: rules.minRestAfterLongHours,
                            week: rules.maxWeeklyHours,
                            mode:
                                rules.enforcement === 'block'
                                    ? t('bloquant')
                                    : t('avertissement'),
                        },
                    )
                }}
            </span>
            <span
                v-if="shortages > 0"
                class="inline-flex items-center gap-1 font-medium text-negative-strong"
            >
                <TriangleAlert class="size-3.5" />
                {{
                    t(':n créneau(x) sous l’effectif minimum', { n: shortages })
                }}
            </span>
        </div>

        <div
            v-if="agents.length === 0"
            class="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{ t("Aucun agent dans l'équipe pour le moment.") }}
        </div>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table
                class="w-full border-collapse text-sm"
                :class="isMonth ? 'min-w-[1200px]' : 'min-w-[860px]'"
            >
                <thead>
                    <tr class="bg-muted/50">
                        <th
                            scope="col"
                            class="sticky left-0 z-10 w-44 border-b bg-muted px-3 py-2 text-left font-medium"
                        >
                            {{ t('Agent') }}
                        </th>
                        <th
                            v-for="day in days"
                            :key="day.date"
                            scope="col"
                            class="border-b border-l px-1 py-2 text-center font-normal"
                            :class="[
                                day.today ? 'bg-primary/10' : '',
                                day.weekend ? 'text-muted-foreground' : '',
                            ]"
                        >
                            <div class="text-[11px] uppercase">
                                {{ day.weekday }}
                            </div>
                            <div
                                class="font-semibold tabular-nums"
                                :class="day.today ? 'text-primary' : ''"
                            >
                                {{ day.day }}
                            </div>
                        </th>
                        <th
                            scope="col"
                            class="w-16 border-b border-l px-2 py-2 text-right font-medium"
                        >
                            {{ t('Heures') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="agent in agents"
                        :key="agent.id"
                        class="group/row"
                    >
                        <th
                            scope="row"
                            class="sticky left-0 z-10 border-b bg-background px-3 py-2 text-left font-normal group-hover/row:bg-muted/40"
                        >
                            <button
                                type="button"
                                class="block max-w-40 truncate text-left font-medium hover:underline"
                                :title="t('Affecter plusieurs jours')"
                                @click="openBulk(agent.id)"
                            >
                                {{ agent.name }}
                            </button>
                            <div
                                v-if="
                                    agent.jobTitle ||
                                    (scope === 'all' && agent.supervisor)
                                "
                                class="truncate text-xs text-muted-foreground"
                                :title="
                                    agent.supervisor
                                        ? t('Supervisé par :name', {
                                              name: agent.supervisor,
                                          })
                                        : undefined
                                "
                            >
                                {{
                                    [
                                        agent.jobTitle,
                                        scope === 'all' && agent.supervisor
                                            ? `↳ ${agent.supervisor}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </div>
                        </th>
                        <td
                            v-for="day in days"
                            :key="day.date"
                            class="h-14 border-b border-l p-1 align-top"
                            :class="[
                                day.today ? 'bg-primary/5' : '',
                                day.weekend ? 'bg-muted/30' : '',
                            ]"
                        >
                            <div class="flex flex-col items-start gap-1">
                                <span
                                    v-if="leaveOf(agent.id, day.date)"
                                    class="w-full truncate rounded border px-1.5 py-0.5 text-[11px]"
                                    :class="
                                        leaveOf(agent.id, day.date)!.status ===
                                        'approved'
                                            ? 'border-notice-border bg-[repeating-linear-gradient(45deg,var(--color-notice-subtle),var(--color-notice-subtle)_4px,transparent_4px,transparent_8px)] text-notice-strong'
                                            : 'border-dashed text-muted-foreground'
                                    "
                                    :title="
                                        `${leaveOf(agent.id, day.date)!.type.name} · ${leaveOf(agent.id, day.date)!.statusLabel}` +
                                        (leaveOf(agent.id, day.date)!.partial
                                            ? ` · ${leaveOf(agent.id, day.date)!.startTime}–${leaveOf(agent.id, day.date)!.endTime}`
                                            : '')
                                    "
                                >
                                    {{
                                        isMonth
                                            ? t('C')
                                            : leaveOf(agent.id, day.date)!.type
                                                  .name
                                    }}{{
                                        leaveOf(agent.id, day.date)!.status ===
                                        'pending'
                                            ? ' ?'
                                            : ''
                                    }}
                                </span>
                                <ShiftChip
                                    v-for="shift in shiftsOf(
                                        agent.id,
                                        day.date,
                                    )"
                                    :key="shift.id"
                                    :shift="shift"
                                    :compact="isMonth"
                                    :removable="day.date >= workforce.today"
                                    @remove="remove"
                                />
                                <DropdownMenu
                                    v-if="
                                        day.date >= workforce.today &&
                                        activeTemplates.length
                                    "
                                >
                                    <DropdownMenuTrigger as-child>
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded p-0.5 text-muted-foreground opacity-40 hover:bg-muted hover:opacity-100 focus-visible:opacity-100 sm:opacity-0 sm:group-hover/row:opacity-60"
                                            :aria-label="
                                                t('Affecter :name le :date', {
                                                    name: agent.name,
                                                    date: formatDay(day.date),
                                                })
                                            "
                                            :disabled="busy"
                                        >
                                            <Plus class="size-3.5" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="start"
                                        class="w-56"
                                    >
                                        <DropdownMenuLabel
                                            class="text-xs font-normal text-muted-foreground"
                                        >
                                            {{ agent.name }} ·
                                            {{ formatDay(day.date) }}
                                        </DropdownMenuLabel>
                                        <DropdownMenuItem
                                            v-for="tpl in activeTemplates"
                                            :key="tpl.id"
                                            @select="
                                                assign(
                                                    agent.id,
                                                    [tpl.id],
                                                    [day.date],
                                                )
                                            "
                                        >
                                            <span
                                                class="size-2.5 rounded-full"
                                                :class="
                                                    shiftColor(tpl.color).dot
                                                "
                                            />
                                            <span class="font-medium">{{
                                                tpl.code
                                            }}</span>
                                            <span
                                                class="text-muted-foreground"
                                                >{{ tpl.window }}</span
                                            >
                                        </DropdownMenuItem>
                                        <template v-if="combo">
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                @select="
                                                    assign(
                                                        agent.id,
                                                        [
                                                            combo[0].id,
                                                            combo[1].id,
                                                        ],
                                                        [day.date],
                                                    )
                                                "
                                            >
                                                <span class="font-medium"
                                                    >{{ combo[0].code }} +
                                                    {{ combo[1].code }}</span
                                                >
                                                <span
                                                    class="text-muted-foreground"
                                                >
                                                    {{ combo[0].startTime }}–{{
                                                        combo[1].endTime
                                                    }}
                                                    ·
                                                    {{
                                                        combo[0].hours +
                                                        combo[1].hours
                                                    }}
                                                    h
                                                </span>
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </td>
                        <td
                            class="border-b border-l px-2 text-right text-xs tabular-nums"
                        >
                            <span
                                :class="
                                    !isMonth &&
                                    agentHours(agent.id) > rules.maxWeeklyHours
                                        ? 'font-semibold text-negative-strong'
                                        : ''
                                "
                            >
                                {{ agentHours(agent.id) }} h
                            </span>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr
                        v-for="tpl in activeTemplates"
                        :key="tpl.id"
                        class="bg-muted/30 text-xs"
                    >
                        <th
                            scope="row"
                            class="sticky left-0 z-10 border-t bg-muted px-3 py-1.5 text-left font-medium"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="size-2 rounded-full"
                                    :class="shiftColor(tpl.color).dot"
                                />
                                {{ t('Couverture :code', { code: tpl.code }) }}
                            </span>
                        </th>
                        <td
                            v-for="day in days"
                            :key="day.date"
                            class="border-t border-l px-1 py-1.5 text-center tabular-nums"
                            :class="
                                coverage[day.date]?.[tpl.id]?.short
                                    ? 'bg-negative-subtle font-semibold text-negative-strong'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ coverage[day.date]?.[tpl.id]?.assigned ?? 0
                            }}<template v-if="tpl.minAgents"
                                >/{{ tpl.minAgents }}</template
                            >
                        </td>
                        <td class="border-t border-l" />
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <Dialog v-model:open="bulkOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('Affecter des postes') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Les règles de repos et de durée sont vérifiées pour chaque jour.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="bulk-agent">{{ t('Agent') }}</Label>
                    <SelectField
                        id="bulk-agent"
                        v-model="bulk.userId"
                        :options="
                            agents.map((a) => ({ value: a.id, label: a.name }))
                        "
                        :placeholder="t('Choisir un agent')"
                    />
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ t('Postes') }}
                    </legend>
                    <label
                        v-for="tpl in activeTemplates"
                        :key="tpl.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="bulk.templateIds.includes(tpl.id)"
                            @update:model-value="
                                toggle(bulk.templateIds, tpl.id, $event)
                            "
                        />
                        <span
                            class="size-2.5 rounded-full"
                            :class="shiftColor(tpl.color).dot"
                        />
                        <span class="font-medium">{{ tpl.code }}</span>
                        {{ tpl.name }} · {{ tpl.window }}
                    </label>
                </fieldset>
                <fieldset>
                    <legend class="mb-2 text-sm font-medium">
                        {{ t('Jours') }}
                    </legend>
                    <div class="grid grid-cols-7 gap-1">
                        <label
                            v-for="day in days.filter(
                                (d) => d.date >= workforce.today,
                            )"
                            :key="day.date"
                            class="flex cursor-pointer flex-col items-center rounded-md border px-1 py-1.5 text-xs has-[:checked]:border-primary has-[:checked]:bg-primary/10"
                        >
                            <input
                                type="checkbox"
                                class="sr-only"
                                :value="day.date"
                                :checked="bulk.dates.includes(day.date)"
                                @change="
                                    toggle(
                                        bulk.dates,
                                        day.date,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            <span class="text-muted-foreground uppercase">{{
                                day.weekday
                            }}</span>
                            <span class="font-semibold tabular-nums">{{
                                day.day
                            }}</span>
                        </label>
                    </div>
                    <p
                        v-if="days.every((d) => d.date < workforce.today)"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('Cette période est passée.') }}
                    </p>
                </fieldset>
                <p
                    v-if="bulk.templateIds.length > 1"
                    class="flex items-start gap-2 rounded-md bg-notice-subtle p-2 text-xs text-notice-strong"
                >
                    <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                    {{
                        t(
                            'Plusieurs postes le même jour : :hours h par jour, refusé au-delà de :max h d’affilée.',
                            {
                                hours: bulkHours,
                                max: rules.maxConsecutiveHours,
                            },
                        )
                    }}
                </p>
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="bulkOpen = false">{{
                    t('Annuler')
                }}</Button>
                <Button
                    :disabled="
                        busy ||
                        !bulk.userId ||
                        bulk.templateIds.length === 0 ||
                        bulk.dates.length === 0
                    "
                    @click="submitBulk"
                >
                    {{ t('Affecter :n jour(s)', { n: bulk.dates.length }) }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
