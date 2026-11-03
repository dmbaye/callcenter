<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ChevronLeft,
    ChevronRight,
    Coffee,
    LogIn,
    LogOut,
    Play,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import StatusPill from '@/components/workforce/StatusPill.vue';
import WorkforceNav from '@/components/workforce/WorkforceNav.vue';
import { t } from '@/lib/i18n';
import {
    formatDay,
    formatTime,
    hours,
    planningUrl,
    shiftColor,
    shiftLabel,
} from '@/lib/workforce';
import type {
    ShiftCard,
    SwapCard,
    Team,
    WorkforceDay,
    WorkforceShell,
} from '@/types';

const props = defineProps<{
    workforce: WorkforceShell;
    weekStart: string;
    previous: string;
    next: string;
    days: WorkforceDay[];
    shifts: ShiftCard[];
    myUpcoming: ShiftCard[];
    colleagues: { id: number; name: string }[];
    colleagueShifts: ShiftCard[];
    swaps: (SwapCard & { mine: boolean })[];
    clock: {
        entryId: number | null;
        since: string | null;
        onBreak: boolean;
        breakSince: string | null;
        workedMinutes: number | null;
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
                title: 'Mon planning',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning/me`
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const busy = ref(false);

// The worked time keeps counting between page loads.
const loadedAt = ref(Date.now());
const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 30_000);
});

onUnmounted(() => clearInterval(ticker));

const workedLive = computed(() => {
    if (props.clock.workedMinutes === null) {
        return null;
    }

    const extra = props.clock.onBreak
        ? 0
        : (now.value - loadedAt.value) / 60000;

    return props.clock.workedMinutes + extra;
});

function showErrors(errors: Record<string, string | string[]>) {
    toast.error(Object.values(errors).flat()[0] ?? t('Action impossible.'));
}

function punch(action: 'in' | 'out' | 'break-start' | 'break-end') {
    busy.value = true;
    router.post(
        planningUrl(slug.value, `clock/${action}`),
        {},
        {
            preserveScroll: true,
            onError: showErrors,
            onSuccess: () => {
                loadedAt.value = Date.now();
                now.value = Date.now();
            },
            onFinish: () => (busy.value = false),
        },
    );
}

function go(date: string) {
    router.get(
        planningUrl(slug.value, 'me', { date }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function shiftsOn(date: string): ShiftCard[] {
    return props.shifts.filter((shift) => shift.date === date);
}

const weekMinutes = computed(() =>
    props.shifts.reduce(
        (sum, shift) =>
            sum +
            (new Date(shift.endsAt.replace(' ', 'T')).getTime() -
                new Date(shift.startsAt.replace(' ', 'T')).getTime()) /
                60000,
        0,
    ),
);

const incoming = computed(() =>
    props.swaps.filter(
        (swap) => !swap.mine && swap.status === 'pending_counterparty',
    ),
);
const others = computed(() =>
    props.swaps.filter((swap) => !incoming.value.includes(swap)),
);

const openSwapIds = computed(
    () =>
        new Set(
            props.swaps
                .filter((swap) =>
                    ['pending_counterparty', 'pending_supervisor'].includes(
                        swap.status,
                    ),
                )
                .flatMap((swap) => [swap.give?.id, swap.take?.id])
                .filter(Boolean),
        ),
);

// Swap proposal.
const swapOpen = ref(false);
const form = ref({
    give: null as ShiftCard | null,
    counterpartyId: '',
    takeId: '',
    message: '',
});

function proposeSwap(shift: ShiftCard) {
    form.value = { give: shift, counterpartyId: '', takeId: '', message: '' };
    swapOpen.value = true;
}

const theirShifts = computed(() =>
    props.colleagueShifts.filter(
        (shift) => String(shift.userId) === form.value.counterpartyId,
    ),
);

const takeOptions = computed(() => [
    { value: 'none', label: t('Rien en échange (céder mon poste)') },
    ...theirShifts.value
        .filter((shift) => !openSwapIds.value.has(shift.id))
        .map((shift) => ({ value: shift.id, label: shiftLabel(shift) })),
]);

function submitSwap() {
    if (!form.value.give) {
        return;
    }

    busy.value = true;
    router.post(
        planningUrl(slug.value, 'swaps'),
        {
            requester_assignment_id: form.value.give.id,
            counterparty_id: Number(form.value.counterpartyId),
            counterparty_assignment_id:
                form.value.takeId && form.value.takeId !== 'none'
                    ? Number(form.value.takeId)
                    : null,
            message: form.value.message || null,
        },
        {
            preserveScroll: true,
            onError: showErrors,
            onSuccess: () => (swapOpen.value = false),
            onFinish: () => (busy.value = false),
        },
    );
}

function respond(swap: SwapCard, accept: boolean) {
    router.post(
        planningUrl(slug.value, `swaps/${swap.id}/respond`),
        { accept },
        { preserveScroll: true, onError: showErrors },
    );
}

function cancelSwap(swap: SwapCard) {
    router.post(
        planningUrl(slug.value, `swaps/${swap.id}/cancel`),
        {},
        { preserveScroll: true, onError: showErrors },
    );
}
</script>

<template>
    <Head :title="t('Mon planning')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <WorkforceNav :workforce="workforce" current="me" />

        <!-- Clock -->
        <section
            class="flex flex-wrap items-center gap-4 rounded-lg border p-4"
            :aria-label="t('Pointage')"
        >
            <div class="flex items-center gap-3">
                <span
                    class="size-3 rounded-full"
                    :class="
                        clock.entryId
                            ? clock.onBreak
                                ? 'bg-notice'
                                : 'bg-positive'
                            : 'bg-muted-foreground/40'
                    "
                />
                <div>
                    <p class="font-medium">
                        <template v-if="!clock.entryId">{{
                            t('Non pointé')
                        }}</template>
                        <template v-else-if="clock.onBreak">
                            {{
                                t('En pause depuis :time', {
                                    time: formatTime(clock.breakSince),
                                })
                            }}
                        </template>
                        <template v-else>{{
                            t('En poste depuis :time', {
                                time: formatTime(clock.since),
                            })
                        }}</template>
                    </p>
                    <p
                        v-if="workedLive !== null"
                        class="text-sm text-muted-foreground tabular-nums"
                    >
                        {{
                            t('Travaillé : :time', { time: hours(workedLive) })
                        }}
                    </p>
                </div>
            </div>
            <div class="ml-auto flex flex-wrap gap-2">
                <Button
                    v-if="!clock.entryId"
                    :disabled="busy"
                    @click="punch('in')"
                >
                    <LogIn class="size-4" />
                    {{ t('Pointer l’arrivée') }}
                </Button>
                <template v-else>
                    <Button
                        v-if="!clock.onBreak"
                        variant="outline"
                        :disabled="busy"
                        @click="punch('break-start')"
                    >
                        <Coffee class="size-4" />
                        {{ t('Commencer une pause') }}
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        :disabled="busy"
                        @click="punch('break-end')"
                    >
                        <Play class="size-4" />
                        {{ t('Reprendre') }}
                    </Button>
                    <Button
                        variant="secondary"
                        :disabled="busy"
                        @click="punch('out')"
                    >
                        <LogOut class="size-4" />
                        {{ t('Pointer le départ') }}
                    </Button>
                </template>
            </div>
        </section>

        <!-- Incoming swap requests -->
        <section v-if="incoming.length" class="grid gap-3">
            <h2 class="text-base font-semibold">
                {{ t('Échanges proposés par vos collègues') }}
            </h2>
            <article
                v-for="swap in incoming"
                :key="swap.id"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-notice-border bg-notice-subtle/40 p-4"
            >
                <ArrowLeftRight class="size-5 shrink-0 text-notice-strong" />
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-medium">
                        {{
                            swap.take
                                ? t(
                                      ':name propose de permuter avec lui',
                                      { name: swap.requester.name },
                                  )
                                : t(
                                      ':name vous propose une permutation',
                                      { name: swap.requester.name },
                                  )
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            t('Vous prenez : :shift', {
                                shift: shiftLabel(swap.give),
                            })
                        }}
                        <template v-if="swap.take">
                            ·
                            {{
                                t('Vous cédez : :shift', {
                                    shift: shiftLabel(swap.take),
                                })
                            }}</template
                        >
                    </p>
                    <p v-if="swap.message" class="mt-1 italic">
                        « {{ swap.message }} »
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="respond(swap, false)"
                        >{{ t('Refuser') }}</Button
                    >
                    <Button size="sm" @click="respond(swap, true)">{{
                        t('Accepter')
                    }}</Button>
                </div>
            </article>
        </section>

        <!-- Week -->
        <section class="grid gap-3">
            <header class="flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    size="icon"
                    :aria-label="t('Semaine précédente')"
                    @click="go(previous)"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    @click="go(workforce.today)"
                    >{{ t("Aujourd'hui") }}</Button
                >
                <Button
                    variant="outline"
                    size="icon"
                    :aria-label="t('Semaine suivante')"
                    @click="go(next)"
                >
                    <ChevronRight class="size-4" />
                </Button>
                <h2 class="text-base font-semibold">
                    {{
                        t('Semaine du :date', {
                            date: formatDay(weekStart, {
                                day: 'numeric',
                                month: 'long',
                            }),
                        })
                    }}
                </h2>
                <span
                    class="ml-auto text-sm text-muted-foreground tabular-nums"
                >
                    {{ t(':time planifiées', { time: hours(weekMinutes) }) }}
                </span>
            </header>
            <ol class="grid gap-2 sm:grid-cols-2 lg:grid-cols-7">
                <li
                    v-for="day in days"
                    :key="day.date"
                    class="flex min-h-24 flex-col gap-2 rounded-lg border p-3"
                    :class="
                        day.today
                            ? 'border-primary ring-1 ring-primary/30'
                            : day.weekend
                              ? 'bg-muted/30'
                              : ''
                    "
                >
                    <p class="text-xs text-muted-foreground uppercase">
                        {{ day.weekday }}
                        <span class="text-sm font-semibold text-foreground">{{
                            day.day
                        }}</span>
                        {{ day.month }}
                    </p>
                    <div
                        v-for="shift in shiftsOn(day.date)"
                        :key="shift.id"
                        class="rounded-md border px-2 py-1.5 text-sm"
                        :class="shiftColor(shift.color).chip"
                    >
                        <p class="font-semibold">
                            {{ shift.name ?? shift.code }}
                        </p>
                        <p class="text-xs tabular-nums opacity-80">
                            {{ shift.window }}
                        </p>
                        <p v-if="shift.note" class="mt-1 text-xs">
                            {{ shift.note }}
                        </p>
                    </div>
                    <p
                        v-if="shiftsOn(day.date).length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('Repos') }}
                    </p>
                </li>
            </ol>
        </section>

        <!-- Upcoming, swap -->
        <section class="grid gap-3">
            <h2 class="text-base font-semibold">
                {{ t('Mes prochains postes') }}
            </h2>
            <p
                v-if="myUpcoming.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('Aucun poste planifié dans les 45 prochains jours.') }}
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="shift in myUpcoming"
                    :key="shift.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :class="shiftColor(shift.color).dot"
                    />
                    <span class="w-40 capitalize">{{
                        formatDay(shift.date, {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'short',
                        })
                    }}</span>
                    <span class="font-medium">{{
                        shift.name ?? shift.code
                    }}</span>
                    <span class="text-muted-foreground tabular-nums">{{
                        shift.window
                    }}</span>
                    <span
                        v-if="openSwapIds.has(shift.id)"
                        class="ml-auto text-xs text-notice-strong"
                        >{{ t('Échange en cours') }}</span
                    >
                    <Button
                        v-else
                        variant="ghost"
                        size="sm"
                        class="ml-auto"
                        :disabled="colleagues.length === 0"
                        @click="proposeSwap(shift)"
                    >
                        <ArrowLeftRight class="size-4" />
                        {{ t('Permuter') }}
                    </Button>
                </li>
            </ul>
        </section>

        <section v-if="others.length" class="grid gap-3">
            <h2 class="text-base font-semibold">{{ t('Mes permutations') }}</h2>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="swap in others"
                    :key="swap.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                >
                    <StatusPill
                        :status="swap.status"
                        :label="swap.statusLabel"
                    />
                    <div class="min-w-0 flex-1">
                        <p>
                            <template v-if="swap.mine">{{
                                t('Avec :name', {
                                    name: swap.counterparty.name,
                                })
                            }}</template>
                            <template v-else>{{
                                t('Proposé par :name', {
                                    name: swap.requester.name,
                                })
                            }}</template>
                            · {{ shiftLabel(swap.give) }}
                            <template v-if="swap.take">
                                ⇄ {{ shiftLabel(swap.take) }}</template
                            >
                        </p>
                        <p
                            v-if="swap.reviewNote"
                            class="text-xs text-muted-foreground"
                        >
                            {{ swap.reviewer }} : {{ swap.reviewNote }}
                        </p>
                        <p
                            v-else-if="swap.autoApproved"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('Approuvé automatiquement') }}
                        </p>
                    </div>
                    <Button
                        v-if="
                            swap.mine &&
                            [
                                'pending_counterparty',
                                'pending_supervisor',
                            ].includes(swap.status)
                        "
                        variant="ghost"
                        size="sm"
                        @click="cancelSwap(swap)"
                    >
                        {{ t('Annuler') }}
                    </Button>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="swapOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('Proposer un échange') }}</DialogTitle>
                <DialogDescription>{{
                    shiftLabel(form.give)
                }}</DialogDescription>
            </DialogHeader>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="swap-colleague">{{ t('Collègue') }}</Label>
                    <SelectField
                        id="swap-colleague"
                        v-model="form.counterpartyId"
                        :options="
                            colleagues.map((c) => ({
                                value: c.id,
                                label: c.name,
                            }))
                        "
                        :placeholder="t('Choisir un collègue')"
                        @update:model-value="form.takeId = ''"
                    />
                </div>
                <div v-if="form.counterpartyId" class="grid gap-2">
                    <Label for="swap-take">{{ t('En échange de') }}</Label>
                    <SelectField
                        id="swap-take"
                        v-model="form.takeId"
                        :options="takeOptions"
                        :placeholder="t('Choisir son poste')"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="swap-message">{{
                        t('Message (facultatif)')
                    }}</Label>
                    <Textarea
                        id="swap-message"
                        v-model="form.message"
                        rows="2"
                        maxlength="500"
                    />
                </div>
                <p class="flex items-start gap-2 text-xs text-muted-foreground">
                    <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                    {{
                        t(
                            'Votre collègue doit accepter, puis un superviseur valide. Les règles de repos et de chevauchement sont vérifiées pour vous deux.',
                        )
                    }}
                </p>
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="swapOpen = false">{{
                    t('Annuler')
                }}</Button>
                <Button
                    :disabled="busy || !form.counterpartyId || !form.takeId"
                    @click="submitSwap"
                >
                    {{ t('Envoyer la proposition') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
