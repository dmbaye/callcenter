<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CalendarPlus, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import StatusPill from '@/components/workforce/StatusPill.vue';
import WorkforceNav from '@/components/workforce/WorkforceNav.vue';
import { t } from '@/lib/i18n';
import { leavePeriod, planningUrl } from '@/lib/workforce';
import type {
    LeaveBalance,
    LeaveCard,
    LeaveTypeCard,
    Team,
    WorkforceShell,
} from '@/types';

const props = defineProps<{
    workforce: WorkforceShell;
    year: number;
    balances: LeaveBalance[];
    types: LeaveTypeCard[];
    requests: LeaveCard[];
    teamLeave: LeaveCard[];
    hoursPerDay: number;
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
                title: 'Congés',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning/leave`
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');

const open = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const form = ref({
    leave_type_id: '',
    start_date: '',
    end_date: '',
    partial: false,
    start_time: '08:00',
    end_time: '12:00',
    reason: '',
});

function openForm(typeId?: number) {
    errors.value = {};
    form.value = {
        leave_type_id: typeId
            ? String(typeId)
            : props.types[0]
              ? String(props.types[0].id)
              : '',
        start_date: props.workforce.today,
        end_date: props.workforce.today,
        partial: false,
        start_time: '08:00',
        end_time: '12:00',
        reason: '',
    };
    open.value = true;
}

const selectedBalance = computed(() =>
    props.balances.find(
        (balance) => String(balance.type.id) === form.value.leave_type_id,
    ),
);

/** Same rule as the server: Monday to Saturday count, partial = hours / day length. */
const estimate = computed(() => {
    const f = form.value;

    if (!f.start_date) {
        return 0;
    }

    if (f.partial) {
        const [sh, sm] = f.start_time.split(':').map(Number);
        const [eh, em] = f.end_time.split(':').map(Number);
        let minutes = eh * 60 + em - (sh * 60 + sm);

        if (minutes <= 0) {
            minutes += 24 * 60;
        }

        return Math.round((minutes / 60 / props.hoursPerDay) * 100) / 100;
    }

    const start = new Date(`${f.start_date}T00:00`);
    const end = new Date(`${f.end_date || f.start_date}T00:00`);
    let days = 0;

    for (
        let day = new Date(start);
        day <= end;
        day.setDate(day.getDate() + 1)
    ) {
        if (day.getDay() !== 0) {
            days++;
        }
    }

    return days;
});

const overBalance = computed(
    () =>
        selectedBalance.value?.remaining !== null &&
        selectedBalance.value !== undefined &&
        estimate.value > (selectedBalance.value.remaining ?? 0),
);

function submit() {
    processing.value = true;
    router.post(
        planningUrl(slug.value, 'leave'),
        {
            ...form.value,
            leave_type_id: Number(form.value.leave_type_id),
            end_date: form.value.partial
                ? null
                : form.value.end_date || form.value.start_date,
        },
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
            onError: (e) => {
                errors.value = e;
                toast.error(Object.values(e)[0] ?? t('Action impossible.'));
            },
            onFinish: () => (processing.value = false),
        },
    );
}

function cancel(leave: LeaveCard) {
    router.post(
        planningUrl(slug.value, `leave/${leave.id}/cancel`),
        {},
        {
            preserveScroll: true,
            onError: (e) =>
                toast.error(Object.values(e)[0] ?? t('Action impossible.')),
        },
    );
}

function canCancel(leave: LeaveCard) {
    return (
        leave.status === 'pending' ||
        (leave.status === 'approved' && leave.startDate > props.workforce.today)
    );
}

function gauge(balance: LeaveBalance) {
    const allowance = Number(balance.allowance ?? 0);

    if (!allowance) {
        return { used: 0, pending: 0 };
    }

    return {
        used: Math.min(100, (balance.used / allowance) * 100),
        pending: Math.min(100, (balance.pending / allowance) * 100),
    };
}
</script>

<template>
    <Head :title="t('Congés')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <WorkforceNav :workforce="workforce" current="leave" />

        <header class="flex flex-wrap items-center gap-3">
            <h1 class="text-lg font-semibold">
                {{ t('Mes soldes :year', { year }) }}
            </h1>
            <Button
                class="ml-auto"
                :disabled="types.length === 0"
                @click="openForm()"
            >
                <CalendarPlus class="size-4" />
                {{ t('Demander un congé') }}
            </Button>
        </header>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button
                v-for="balance in balances"
                :key="balance.type.id"
                type="button"
                class="grid gap-2 rounded-lg border p-4 text-left transition-colors hover:bg-muted/40"
                @click="openForm(balance.type.id)"
            >
                <p
                    class="flex items-center justify-between text-sm font-medium"
                >
                    {{ balance.type.name }}
                    <span class="text-xs font-normal text-muted-foreground">{{
                        balance.type.paid ? t('payé') : t('non payé')
                    }}</span>
                </p>
                <template v-if="balance.remaining !== null">
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ balance.remaining }}
                        <span class="text-sm font-normal text-muted-foreground"
                            >/ {{ balance.allowance }} {{ t('j') }}</span
                        >
                    </p>
                    <div
                        class="flex h-1.5 overflow-hidden rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <div
                            class="bg-primary"
                            :style="{ width: `${gauge(balance).used}%` }"
                        />
                        <div
                            class="bg-primary/40"
                            :style="{ width: `${gauge(balance).pending}%` }"
                        />
                    </div>
                </template>
                <p v-else class="text-2xl font-semibold tabular-nums">
                    {{ balance.used }}
                    <span class="text-sm font-normal text-muted-foreground">{{
                        t('j pris')
                    }}</span>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(':used j pris · :pending j en attente', {
                            used: balance.used,
                            pending: balance.pending,
                        })
                    }}
                </p>
            </button>
        </div>

        <section class="grid gap-3">
            <h2 class="text-base font-semibold">{{ t('Mes demandes') }}</h2>
            <p
                v-if="requests.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('Aucune demande pour le moment.') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="leave in requests"
                    :key="leave.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                >
                    <StatusPill
                        :status="leave.status"
                        :label="leave.statusLabel"
                    />
                    <span class="font-medium">{{ leave.type.name }}</span>
                    <span class="capitalize">{{ leavePeriod(leave) }}</span>
                    <span class="text-muted-foreground tabular-nums">{{
                        t(':n j', { n: leave.days })
                    }}</span>
                    <span
                        v-if="leave.reviewNote"
                        class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                    >
                        {{ leave.reviewer }} : {{ leave.reviewNote }}
                    </span>
                    <Button
                        v-if="canCancel(leave)"
                        variant="ghost"
                        size="sm"
                        class="ml-auto"
                        @click="cancel(leave)"
                    >
                        {{ t('Annuler') }}
                    </Button>
                </li>
            </ul>
        </section>

        <section v-if="workforce.isSupervisor" class="grid gap-3">
            <h2 class="flex items-center gap-2 text-base font-semibold">
                <Users class="size-4" />
                {{ t("Absences de l'équipe (60 prochains jours)") }}
            </h2>
            <p
                v-if="teamLeave.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('Personne en congé.') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="leave in teamLeave"
                    :key="leave.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-2.5"
                >
                    <StatusPill
                        :status="leave.status"
                        :label="leave.statusLabel"
                    />
                    <span class="font-medium">{{ leave.user.name }}</span>
                    <span>{{ leave.type.name }}</span>
                    <span class="text-muted-foreground capitalize">{{
                        leavePeriod(leave)
                    }}</span>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('Demander un congé') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Votre superviseur est prévenu. Une fois approuvé, vos postes couverts sont retirés du roster.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="leave-type">{{ t('Type') }}</Label>
                    <SelectField
                        id="leave-type"
                        v-model="form.leave_type_id"
                        :options="
                            types.map((type) => ({
                                value: type.id,
                                label: type.name,
                            }))
                        "
                    />
                    <InputError :message="errors.leave_type_id" />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="form.partial" />
                    {{ t('Une partie de la journée seulement') }}
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <div
                        class="grid gap-2"
                        :class="form.partial ? 'col-span-2' : ''"
                    >
                        <Label for="leave-start">{{
                            form.partial ? t('Date') : t('Du')
                        }}</Label>
                        <Input
                            id="leave-start"
                            v-model="form.start_date"
                            type="date"
                            required
                        />
                    </div>
                    <div v-if="!form.partial" class="grid gap-2">
                        <Label for="leave-end">{{ t('Au') }}</Label>
                        <Input
                            id="leave-end"
                            v-model="form.end_date"
                            type="date"
                            :min="form.start_date"
                        />
                    </div>
                    <template v-else>
                        <div class="grid gap-2">
                            <Label for="leave-from">{{ t('De') }}</Label>
                            <Input
                                id="leave-from"
                                v-model="form.start_time"
                                type="time"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="leave-to">{{ t('À') }}</Label>
                            <Input
                                id="leave-to"
                                v-model="form.end_time"
                                type="time"
                            />
                        </div>
                    </template>
                </div>
                <InputError
                    :message="
                        errors.start_date ??
                        errors.end_date ??
                        errors.start_time ??
                        errors.end_time
                    "
                />
                <div class="grid gap-2">
                    <Label for="leave-reason">{{
                        t('Motif (facultatif)')
                    }}</Label>
                    <Textarea
                        id="leave-reason"
                        v-model="form.reason"
                        rows="2"
                        maxlength="500"
                    />
                </div>
                <p
                    class="text-sm"
                    :class="
                        overBalance
                            ? 'text-negative-strong'
                            : 'text-muted-foreground'
                    "
                >
                    {{ t('Décompte estimé : :n j', { n: estimate }) }}
                    <template
                        v-if="
                            selectedBalance?.remaining !== null &&
                            selectedBalance
                        "
                    >
                        ·
                        {{
                            t('solde disponible : :n j', {
                                n: selectedBalance.remaining,
                            })
                        }}
                    </template>
                </p>
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="open = false">{{
                    t('Annuler')
                }}</Button>
                <Button
                    :disabled="
                        processing ||
                        !form.leave_type_id ||
                        !form.start_date ||
                        overBalance
                    "
                    @click="submit"
                >
                    {{ t('Envoyer la demande') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
