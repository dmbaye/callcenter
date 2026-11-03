<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Check,
    CheckCircle2,
    Clock,
    CreditCard,
    Megaphone,
    Mic,
    Minus,
    PhoneOutgoing,
    Video,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { planHighlights } from '@/data/plans';
import { formatDate, formatDuration, formatMoney } from '@/lib/format';
import { checkout, index } from '@/routes/billing';
import type {
    BillingCycle,
    PaymentRow,
    Plan,
    PlanLimits,
    SubscriptionDetails,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Abonnement', href: index() }],
    },
});

const props = defineProps<{
    plans: Plan[];
    subscription: SubscriptionDetails;
    usage: { agents: number; supervisors: number; queues: number };
    limits: PlanLimits;
    payments: PaymentRow[];
    returnedPayment: PaymentRow | null;
    currency: string;
    annualMonthsCharged: number;
    graceDays: number;
    paymentsEnabled: boolean;
    /** Outside calls of the chosen month (null: Asterisk database unreachable). */
    outbound: OutboundUsage | null;
}>();

type OutboundUsage = {
    month: string;
    months: { value: string; label: string }[];
    rate: number;
    summary: {
        calls: number;
        seconds: number;
        minutes: number;
        included_minutes: number;
        free_minutes: number;
        billable_minutes: number;
        amount: number;
    };
    calls: {
        id: string;
        start: string;
        seconds: number;
        minutes: number;
        number: string;
        extension: string | null;
        campaign: boolean;
        rate: number;
        free_minutes: number;
        cost: number;
    }[];
};

function changeOutboundMonth(event: Event): void {
    router.get(
        index().url,
        { month: (event.target as HTMLSelectElement).value },
        { preserveScroll: true, preserveState: true, only: ['outbound'] },
    );
}

const form = useForm<{
    plan: string;
    cycle: BillingCycle;
    extra_agents: number;
}>({
    plan: props.subscription.plan,
    cycle: props.subscription.cycle,
    extra_agents: props.subscription.extraAgents,
});

const selectedPlan = computed(
    () => props.plans.find((p) => p.code === form.plan) ?? props.plans[0],
);

const extraAgents = computed(() =>
    selectedPlan.value?.extraAgentPrice
        ? Math.max(0, Number(form.extra_agents) || 0)
        : 0,
);

const total = computed(() => {
    const plan = selectedPlan.value;

    if (!plan) {
        return 0;
    }

    const monthly =
        plan.monthlyPrice + extraAgents.value * (plan.extraAgentPrice ?? 0);
    const months = form.cycle === 'annual' ? props.annualMonthsCharged : 1;

    return Math.round((monthly * months) / 5) * 5;
});

/* Plans that include a feature, from config/billing.php (shared by the server). */
const page = usePage();
const includedIn = (feature: string) =>
    page.props.billing?.includedIn?.[feature] ?? '';
const notIncluded = (feature: string) =>
    t('Non inclus (offres :plans)', { plans: includedIn(feature) });

/* Free plan: no payment, and not while a paid period still runs (it would be lost). */
const selectedIsFree = computed(() => !!selectedPlan.value?.isFree);
const paidPeriodRunning = computed(
    () =>
        props.subscription.state === 'active' &&
        !props.subscription.onTrial &&
        !props.subscription.isFree,
);
const freeBlocked = computed(
    () =>
        selectedIsFree.value &&
        (paidPeriodRunning.value || form.plan === props.subscription.plan),
);

const isRenewal = computed(
    () =>
        !props.subscription.onTrial &&
        form.plan === props.subscription.plan &&
        extraAgents.value === props.subscription.extraAgents,
);

const stateTone = computed(
    () =>
        ({
            trialing: 'bg-informative text-white',
            active: 'bg-positive text-white',
            grace: 'bg-notice text-foreground',
            expired: 'bg-negative text-white',
        })[props.subscription.state],
);

/** The plan the team is on, with its options (video first). */
const currentPlan = computed(
    () =>
        props.plans.find((plan) => plan.code === props.subscription.plan) ??
        null,
);

/** The plan's options, video first, each with a short detail line. */
const currentOptions = computed(() => {
    const plan = currentPlan.value;

    if (!plan) {
        return [];
    }

    const { limits, features } = plan;
    const video = features.video && !!limits.meeting_participants;
    const outboundMinutes = limits.outbound_minutes ?? 0;

    return [
        {
            key: 'video',
            included: video,
            text: video
                ? t("Jusqu'à :p0 participants par réunion", {
                      p0: limits.meeting_participants,
                  }) +
                  (features.recording ? t(', enregistrement des réunions') : '')
                : notIncluded('video'),
        },
        {
            key: 'call-recording',
            included: !!features.call_recording,
            text: features.call_recording
                ? t('Conservés :p0 jours.', { p0: limits.recording_days ?? 0 })
                : notIncluded('call_recording'),
        },
        {
            key: 'campaigns',
            included: features.campaigns,
            text: features.campaigns
                ? t('Aperçu, progressif et « power »')
                : notIncluded('campaigns'),
        },
        {
            key: 'outbound',
            included: outboundMinutes > 0,
            text:
                outboundMinutes > 0
                    ? t(':p0 min incluses par mois', { p0: outboundMinutes })
                    : t("Facturés à la minute, en plus de l'abonnement"),
        },
    ];
});

const optionTitles: Record<string, string> = {
    video: t('Réunions vidéo'),
    'call-recording': t('Enregistrement des appels'),
    campaigns: t('Campagnes sortantes'),
    outbound: t('Appels sortants'),
};

const optionIcons: Record<string, Component> = {
    video: Video,
    'call-recording': Mic,
    campaigns: Megaphone,
    outbound: PhoneOutgoing,
};

const usageRows = computed(() => [
    {
        label: t('Agents'),
        used: props.usage.agents,
        limit: props.limits.agents,
    },
    {
        label: t('Superviseurs'),
        used: props.usage.supervisors,
        limit: props.limits.supervisors,
    },
    {
        label: t("Files d'appels"),
        used: props.usage.queues,
        limit: props.limits.queues,
    },
]);

function pay() {
    form.transform((data) => ({
        ...data,
        extra_agents: extraAgents.value,
    })).post(checkout().url, { preserveScroll: true });
}

function refresh() {
    router.reload({ only: ['returnedPayment', 'payments', 'subscription'] });
}
</script>

<template>
    <Head :title="t('Abonnement')" />

    <h1 class="sr-only">{{ t('Abonnement') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Abonnement')"
            :description="
                t(
                    'Offre, paiements Mobile Money / carte et utilisation de votre équipe',
                )
            "
        />

        <!-- Back from the payment page -->
        <Alert v-if="returnedPayment?.status === 'accepted'">
            <CheckCircle2 class="size-4 text-positive-strong" />
            <AlertTitle>{{ t('Paiement reçu') }}</AlertTitle>
            <AlertDescription>
                {{
                    t(":p0 — votre abonnement est payé jusqu'au :p1.", {
                        p0: formatMoney(
                            returnedPayment.amount,
                            returnedPayment.currency,
                        ),
                        p1: formatDate(returnedPayment.periodEndsAt),
                    })
                }}
            </AlertDescription>
        </Alert>
        <Alert v-else-if="returnedPayment?.status === 'pending'">
            <Clock class="size-4" />
            <AlertTitle>{{
                t('Paiement en attente de confirmation')
            }}</AlertTitle>
            <AlertDescription class="flex flex-wrap items-center gap-3">
                {{
                    t(
                        "Validez l'opération sur votre téléphone si on vous le demande. La confirmation peut prendre quelques minutes.",
                    )
                }}
                <Button size="sm" variant="outline" @click="refresh">{{
                    t('Actualiser')
                }}</Button>
            </AlertDescription>
        </Alert>
        <Alert
            v-else-if="returnedPayment?.status === 'refused'"
            variant="destructive"
        >
            <XCircle class="size-4" />
            <AlertTitle>{{ t("Le paiement n'a pas abouti") }}</AlertTitle>
            <AlertDescription>
                {{
                    t(
                        "Aucun montant n'a été débité. Vous pouvez réessayer ci-dessous.",
                    )
                }}
            </AlertDescription>
        </Alert>

        <!-- Current subscription -->
        <section class="rounded-lg border p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold">
                            {{ t('Offre :p0', { p0: subscription.planName }) }}
                        </h2>
                        <Badge class="border-transparent" :class="stateTone">{{
                            subscription.stateLabel
                        }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        <template v-if="subscription.isFree">{{
                            t('Offre gratuite, sans échéance ni paiement.')
                        }}</template>
                        <template
                            v-else-if="subscription.state === 'trialing'"
                            >{{
                                t("Essai gratuit jusqu'au :p0.", {
                                    p0: formatDate(subscription.endsAt),
                                })
                            }}</template
                        >
                        <template v-else-if="subscription.state === 'active'">{{
                            t("Payé jusqu'au :p0 (:p1).", {
                                p0: formatDate(subscription.endsAt),
                                p1:
                                    subscription.cycle === 'annual'
                                        ? t('annuel')
                                        : t('mensuel'),
                            })
                        }}</template>
                        <template v-else-if="subscription.state === 'grace'">{{
                            t(
                                'Échéance dépassée le :p0. Renouvelez avant le :p1 pour éviter la lecture seule.',
                                {
                                    p0: formatDate(subscription.endsAt),
                                    p1: formatDate(subscription.graceEndsAt),
                                },
                            )
                        }}</template>
                        <template v-else>{{
                            t(
                                "Compte en lecture seule depuis le :p0. Les agents ne peuvent plus prendre d'appels ; vos données sont conservées.",
                                {
                                    p0: formatDate(
                                        subscription.graceEndsAt ??
                                            subscription.endsAt,
                                    ),
                                },
                            )
                        }}</template>
                    </p>
                </div>
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-3">
                <div v-for="row in usageRows" :key="row.label">
                    <dt class="text-sm text-muted-foreground">
                        {{ row.label }}
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums">
                        {{ row.used }}
                        <span class="font-normal text-muted-foreground">
                            / {{ row.limit ?? '∞' }}</span
                        >
                    </dd>
                    <div
                        v-if="row.limit"
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full"
                            :class="
                                row.used >= row.limit
                                    ? 'bg-notice'
                                    : 'bg-primary'
                            "
                            :style="{
                                width: `${Math.min(100, (row.used / row.limit) * 100)}%`,
                            }"
                        />
                    </div>
                </div>
            </dl>
            <p class="mt-3 text-xs text-muted-foreground">
                {{
                    t(
                        'Les invitations en attente comptent dans les places. Propriétaires et administrateurs ne sont pas comptés.',
                    )
                }}
            </p>
        </section>

        <!-- What the current plan includes -->
        <section v-if="currentPlan" class="rounded-lg border p-5">
            <h2 class="font-semibold">
                {{ t("Inclus dans l'offre :p0", { p0: currentPlan.name }) }}
            </h2>
            <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                <li
                    v-for="line in currentOptions"
                    :key="line.key"
                    class="flex items-start gap-3 rounded-md bg-muted/60 p-3 text-sm"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-md"
                        :class="
                            line.included
                                ? 'bg-informative-subtle text-informative-strong'
                                : 'bg-muted text-muted-foreground'
                        "
                    >
                        <component :is="optionIcons[line.key]" class="size-4" />
                    </span>
                    <span
                        :class="
                            line.included
                                ? 'text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        <span class="block font-medium">{{
                            optionTitles[line.key]
                        }}</span>
                        {{ line.text }}
                    </span>
                </li>
            </ul>
        </section>

        <!-- Choose and pay -->
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">
                    {{ t('Choisir et payer une période') }}
                </h2>
                <div
                    v-if="!selectedIsFree"
                    class="inline-flex rounded-full border bg-muted p-1 text-sm"
                    role="radiogroup"
                    :aria-label="t('Période de facturation')"
                >
                    <button
                        v-for="option in [
                            { value: 'monthly', label: t('1 mois') },
                            {
                                value: 'annual',
                                label: t('12 mois (:p0 payés)', {
                                    p0: annualMonthsCharged,
                                }),
                            },
                        ] as const"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.cycle === option.value"
                        class="rounded-full px-3 py-1 transition"
                        :class="
                            form.cycle === option.value
                                ? 'bg-background font-semibold shadow-sm'
                                : 'text-muted-foreground'
                        "
                        @click="form.cycle = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="plan in plans"
                    :key="plan.code"
                    type="button"
                    class="flex flex-col rounded-lg border p-4 text-left transition hover:border-primary disabled:cursor-not-allowed disabled:opacity-60"
                    :class="
                        form.plan === plan.code
                            ? 'border-2 border-primary bg-accent/50'
                            : ''
                    "
                    :disabled="(plan.blockers ?? []).length > 0"
                    :aria-pressed="form.plan === plan.code"
                    @click="form.plan = plan.code"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ plan.name }}</span>
                        <Badge
                            v-if="plan.code === subscription.plan"
                            variant="outline"
                            >{{ t('Actuelle') }}</Badge
                        >
                    </div>
                    <div class="mt-2 text-xl font-semibold tabular-nums">
                        <template v-if="plan.isFree">{{
                            t('Gratuit')
                        }}</template>
                        <template v-else>
                            {{ formatMoney(plan.monthlyPrice, currency) }}
                            <span
                                class="text-sm font-normal text-muted-foreground"
                                >{{ t('/ mois') }}</span
                            >
                        </template>
                    </div>
                    <ul class="mt-3 space-y-1.5 text-xs">
                        <li
                            v-for="line in planHighlights(plan)"
                            :key="line.key"
                            class="flex items-start gap-1.5"
                            :class="
                                line.included
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            <Video
                                v-if="line.key === 'video' && line.included"
                                class="mt-px size-3.5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <Check
                                v-else-if="line.included"
                                class="mt-px size-3.5 shrink-0 text-positive"
                                aria-hidden="true"
                            />
                            <Minus
                                v-else
                                class="mt-px size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{ line.text }}</span>
                        </li>
                    </ul>
                    <p
                        v-for="blocker in plan.blockers ?? []"
                        :key="blocker"
                        class="mt-2 text-xs text-negative-strong"
                    >
                        {{ blocker }}
                    </p>
                </button>
            </div>

            <div
                v-if="selectedPlan?.extraAgentPrice"
                class="grid max-w-sm gap-2"
            >
                <Label for="extra_agents">{{
                    t('Agents supplémentaires (+:p0 / agent / mois)', {
                        p0: formatMoney(selectedPlan.extraAgentPrice, currency),
                    })
                }}</Label>
                <Input
                    id="extra_agents"
                    v-model.number="form.extra_agents"
                    type="number"
                    min="0"
                    max="1000"
                />
                <InputError :message="form.errors.extra_agents" />
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-muted p-4"
            >
                <div v-if="selectedIsFree">
                    <div class="text-sm text-muted-foreground">
                        {{ t('Nouvelle offre') }} — {{ selectedPlan?.name }}
                    </div>
                    <div class="text-2xl font-semibold">{{ t('Gratuit') }}</div>
                    <p
                        v-if="paidPeriodRunning"
                        class="mt-1 text-xs text-notice-strong"
                    >
                        {{
                            t(
                                "Votre offre payée court jusqu'au :p0 : vous pourrez passer à :p1 à son échéance.",
                                {
                                    p0: formatDate(subscription.endsAt),
                                    p1: selectedPlan?.name,
                                },
                            )
                        }}
                    </p>
                </div>
                <div v-else>
                    <div class="text-sm text-muted-foreground">
                        {{
                            isRenewal
                                ? t('Renouvellement')
                                : t('Nouvelle offre')
                        }}
                        — {{ selectedPlan?.name }},
                        {{
                            form.cycle === 'annual' ? t('12 mois') : t('1 mois')
                        }}
                    </div>
                    <div class="text-2xl font-semibold tabular-nums">
                        {{ formatMoney(total, currency) }}
                    </div>
                    <p
                        v-if="!isRenewal && subscription.state === 'active'"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{
                            t(
                                'Le temps restant sur votre offre actuelle sera converti en jours supplémentaires.',
                            )
                        }}
                    </p>
                </div>
                <Button
                    v-if="selectedIsFree"
                    size="lg"
                    :disabled="form.processing || freeBlocked"
                    @click="pay"
                >
                    {{ t("Passer à l'offre :p0", { p0: selectedPlan?.name }) }}
                </Button>
                <Button
                    v-else
                    size="lg"
                    :disabled="form.processing || !paymentsEnabled"
                    @click="pay"
                >
                    <CreditCard />
                    {{ t('Payer avec Mobile Money ou carte') }}
                </Button>
            </div>
            <InputError :message="form.errors.plan" />
            <p
                v-if="!paymentsEnabled && !selectedIsFree"
                class="text-sm text-notice-strong"
            >
                {{
                    t(
                        "Le paiement en ligne n'est pas encore configuré sur ce serveur.",
                    )
                }}
            </p>
        </section>

        <!-- Outside calls, metered from the call records -->
        <section v-if="outbound" class="space-y-4 rounded-lg border p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <h2 class="font-semibold">{{ t('Appels sortants') }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Facturés :p0 par minute commencée, en plus de l’abonnement.',
                                { p0: formatMoney(outbound.rate, currency) },
                            )
                        }}
                    </p>
                </div>
                <select
                    :value="outbound.month"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                    :aria-label="t('Mois')"
                    @change="changeOutboundMonth"
                >
                    <option
                        v-for="m in outbound.months"
                        :key="m.value"
                        :value="m.value"
                    >
                        {{ m.label }}
                    </option>
                </select>
            </div>

            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-md bg-muted/50 p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Appels') }}
                    </dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ outbound.summary.calls }}
                    </dd>
                </div>
                <div class="rounded-md bg-muted/50 p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Minutes') }}
                    </dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ outbound.summary.minutes }}
                    </dd>
                </div>
                <div class="rounded-md bg-muted/50 p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Minutes facturables') }}
                    </dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ outbound.summary.billable_minutes }}
                    </dd>
                </div>
                <div class="rounded-md bg-muted/50 p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Montant') }}
                    </dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ formatMoney(outbound.summary.amount, currency) }}
                    </dd>
                </div>
            </dl>
            <p
                v-if="outbound.summary.included_minutes > 0"
                class="text-xs text-muted-foreground"
            >
                {{
                    t(':p0 min incluses dans votre offre, :p1 utilisées.', {
                        p0: outbound.summary.included_minutes,
                        p1: outbound.summary.free_minutes,
                    })
                }}
            </p>

            <p
                v-if="outbound.calls.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('Aucun appel sortant ce mois-ci.') }}
            </p>
            <div v-else class="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Date') }}</TableHead>
                            <TableHead>{{ t('Numéro') }}</TableHead>
                            <TableHead>{{ t('Poste') }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Durée')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Montant')
                            }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="call in outbound.calls" :key="call.id">
                            <TableCell class="whitespace-nowrap">{{
                                formatDate(call.start)
                            }}</TableCell>
                            <TableCell class="tabular-nums">
                                {{ call.number }}
                                <Badge
                                    v-if="call.campaign"
                                    variant="secondary"
                                    class="ml-1"
                                    >{{ t('Campagne') }}</Badge
                                >
                            </TableCell>
                            <TableCell>{{ call.extension ?? '—' }}</TableCell>
                            <TableCell class="text-right tabular-nums">
                                {{ formatDuration(call.seconds) }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ call.minutes }} min)</span
                                >
                            </TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                call.cost === 0 && call.free_minutes > 0
                                    ? t('Inclus')
                                    : formatMoney(call.cost, currency)
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <p
                v-if="outbound.summary.calls > outbound.calls.length"
                class="text-xs text-muted-foreground"
            >
                {{
                    t('Les :p0 appels les plus récents sont affichés.', {
                        p0: outbound.calls.length,
                    })
                }}
            </p>
        </section>

        <!-- History -->
        <section v-if="payments.length" class="space-y-3">
            <h2 class="font-semibold">{{ t('Historique des paiements') }}</h2>
            <div class="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Date') }}</TableHead>
                            <TableHead>{{ t('Offre') }}</TableHead>
                            <TableHead>{{ t('Montant') }}</TableHead>
                            <TableHead>{{ t('Moyen') }}</TableHead>
                            <TableHead>{{ t('Statut') }}</TableHead>
                            <TableHead>{{ t('Référence') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="payment in payments"
                            :key="payment.transactionId"
                        >
                            <TableCell>{{
                                formatDate(payment.createdAt)
                            }}</TableCell>
                            <TableCell
                                >{{ payment.planName }}
                                <span class="text-muted-foreground"
                                    >({{
                                        payment.cycle === 'annual'
                                            ? t('12 mois')
                                            : t('1 mois')
                                    }})</span
                                ></TableCell
                            >
                            <TableCell class="tabular-nums">{{
                                formatMoney(payment.amount, payment.currency)
                            }}</TableCell>
                            <TableCell>{{ payment.method ?? '—' }}</TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        payment.status === 'accepted'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    >{{ payment.statusLabel }}</Badge
                                >
                            </TableCell>
                            <TableCell class="font-mono text-xs">{{
                                payment.transactionId
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>
    </div>
</template>
