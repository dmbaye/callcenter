<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    Check,
    Minus,
    PhoneOutgoing,
    RefreshCw,
    Shield,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PaymentIllustration from '@/components/marketing/PaymentIllustration.vue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    comparisonSections,
    meetingDurationText,
    planHighlights,
} from '@/data/plans';
import { formatMoney } from '@/lib/format';
import { vReveal } from '@/lib/reveal';
import { register } from '@/routes';
import type { BillingCycle, Plan } from '@/types';

const props = defineProps<{
    plans: Plan[];
    currency: string;
    trialDays: number;
    trialPlan: string;
    annualMonthsCharged: number;
}>();

const cycle = ref<BillingCycle>('monthly');

const trialPlanName = computed(
    () => props.plans.find((p) => p.code === props.trialPlan)?.name ?? '',
);

const freeMonths = computed(() => 12 - props.annualMonthsCharged);

/** "Starter 20 min … Corporate 24 h" for the meetings note. */
const meetingLimits = computed(() =>
    props.plans
        .filter(
            (plan) => plan.features.video && plan.limits.meeting_participants,
        )
        .map((plan) => ({
            name: plan.name,
            duration: meetingDurationText(plan.limits.meeting_minutes),
            participants: plan.limits.meeting_participants,
        })),
);

const cycles: { value: BillingCycle; label: string }[] = [
    { value: 'monthly', label: 'Mensuel' },
    { value: 'annual', label: 'Annuel' },
];

function displayedPrice(plan: Plan): number {
    return cycle.value === 'annual'
        ? Math.round(plan.annualPrice / 12)
        : plan.monthlyPrice;
}

const billingNotes = [
    {
        icon: CalendarClock,
        title: 'Prépayé, sans prélèvement',
        text: "Vous payez un mois ou une année à l'avance. Un rappel arrive 7 jours avant l'échéance.",
    },
    {
        icon: Shield,
        title: 'Délai de grâce',
        text: "Après l'échéance, 5 jours pour renouveler. Ensuite le compte passe en lecture seule, vos données sont conservées.",
    },
    {
        icon: RefreshCw,
        title: "Changement d'offre",
        text: 'Le temps restant est converti en jours sur la nouvelle offre, au prorata du prix.',
    },
];
</script>

<template>
    <Head title="Tarifs — Callcenter" />

    <section class="px-4 pt-14 pb-12 sm:px-6 md:pt-20">
        <div class="mx-auto max-w-3xl text-center">
            <p
                class="mk-pop text-sm font-bold tracking-wide text-primary uppercase"
            >
                Tarifs
            </p>
            <h1
                class="mk-pop mt-3 text-[2.5rem] leading-[1.05] font-extrabold sm:text-6xl"
            >
                Des offres simples,
                <span class="text-primary">payables en Mobile Money.</span>
            </h1>
            <p
                class="mk-pop mx-auto mt-6 max-w-2xl text-lg text-muted-foreground [animation-delay:80ms]"
            >
                Un abonnement par équipe, en francs CFA. Essai gratuit de
                {{ trialDays }} jours sur l'offre {{ trialPlanName }}, sans
                engagement et sans moyen de paiement.
            </p>

            <!-- Spectrum segmented control -->
            <div
                class="mk-pop mt-9 inline-flex rounded-full bg-secondary p-1 [animation-delay:160ms]"
                role="radiogroup"
                aria-label="Période de facturation"
            >
                <button
                    v-for="option in cycles"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="cycle === option.value"
                    class="inline-flex h-9 items-center gap-2 rounded-full px-5 text-[15px] font-bold transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        cycle === option.value
                            ? 'bg-background text-heading shadow-sm'
                            : 'text-muted-foreground hover:text-heading'
                    "
                    @click="cycle = option.value"
                >
                    {{ option.label }}
                    <span
                        v-if="option.value === 'annual'"
                        class="rounded-full bg-positive-subtle px-2 py-0.5 text-xs font-bold text-positive-strong"
                        >{{ freeMonths }} mois offerts</span
                    >
                </button>
            </div>
        </div>
    </section>

    <section class="px-4 pb-20 sm:px-6">
        <div
            class="mx-auto grid max-w-[96rem] items-stretch gap-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5"
        >
            <div
                v-for="(plan, index) in plans"
                :key="plan.code"
                v-reveal="index * 90"
                class="rounded-[22px]"
                :class="
                    plan.highlighted
                        ? 'bg-primary p-[2px] shadow-[var(--mk-mock-shadow)]'
                        : 'border'
                "
            >
                <div class="flex h-full flex-col rounded-[20px] bg-card p-6">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-xl font-extrabold">{{ plan.name }}</h2>
                        <span
                            v-if="plan.highlighted"
                            class="rounded-full bg-primary px-3 py-1 text-xs font-bold text-white"
                            >Recommandé</span
                        >
                    </div>
                    <p class="mt-2 min-h-12 text-sm text-muted-foreground">
                        {{ plan.tagline }}
                    </p>

                    <div class="mt-6 flex flex-wrap items-baseline gap-x-2">
                        <span
                            :key="cycle"
                            class="mk-pop text-[2rem] leading-none font-extrabold text-heading tabular-nums"
                            >{{
                                plan.isFree
                                    ? 'Gratuit'
                                    : formatMoney(
                                          displayedPrice(plan),
                                          currency,
                                      )
                            }}</span
                        >
                        <span
                            v-if="!plan.isFree"
                            class="text-sm text-muted-foreground"
                            >par mois</span
                        >
                    </div>
                    <p class="mt-2 h-5 text-xs text-muted-foreground">
                        <template v-if="plan.isFree"
                            >Sans engagement, sans carte</template
                        >
                        <template v-else-if="cycle === 'annual'"
                            >{{
                                formatMoney(plan.annualPrice, currency)
                            }}
                            facturés une fois par an</template
                        >
                        <template v-else
                            >ou
                            {{ formatMoney(plan.annualPrice, currency) }} par
                            an</template
                        >
                    </p>

                    <Button
                        as-child
                        size="lg"
                        class="mt-7 w-full"
                        :variant="plan.highlighted ? 'default' : 'outline'"
                    >
                        <Link :href="register().url">
                            {{
                                plan.isFree
                                    ? 'Commencer gratuitement'
                                    : "Démarrer l'essai gratuit"
                            }}
                            <ArrowRight />
                        </Link>
                    </Button>

                    <ul class="mt-8 space-y-3 border-t pt-7">
                        <li
                            v-for="item in planHighlights(plan)"
                            :key="item.key"
                            class="flex items-start gap-3 text-sm"
                        >
                            <span
                                v-if="item.key === 'outbound' && !item.included"
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-notice-subtle text-notice-strong"
                            >
                                <PhoneOutgoing class="size-3" />
                            </span>
                            <span
                                v-else-if="!item.included"
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                            >
                                <Minus class="size-3" />
                            </span>
                            <span
                                v-else
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    plan.highlighted
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-informative-subtle text-informative-strong'
                                "
                            >
                                <Video
                                    v-if="item.key === 'video'"
                                    class="size-3"
                                />
                                <Check v-else class="size-3" />
                            </span>
                            <span
                                :class="{
                                    'text-muted-foreground': !item.included,
                                }"
                                >{{ item.text }}</span
                            >
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div v-reveal class="mx-auto mt-8 grid max-w-7xl gap-5 lg:grid-cols-2">
            <div
                class="flex items-start gap-4 rounded-2xl border bg-card p-5 sm:p-6"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-notice-subtle text-notice-strong"
                >
                    <PhoneOutgoing class="size-5" />
                </span>
                <div>
                    <h2 class="font-extrabold">
                        Appels sortants facturés en plus de l'abonnement
                    </h2>
                    <p
                        class="mt-1 text-sm leading-relaxed text-muted-foreground"
                    >
                        L'abonnement couvre la plateforme : softphone, files
                        d'attente, SVI, supervision, campagnes et réunions
                        vidéo. Les appels sortants passés par vos agents ou vos
                        campagnes sont facturés à la minute, selon le réseau
                        appelé.
                        <Link
                            href="/contact"
                            class="font-bold text-primary hover:underline"
                            >Demander la grille tarifaire</Link
                        >.
                    </p>
                </div>
            </div>

            <div
                v-if="meetingLimits.length"
                class="flex items-start gap-4 rounded-2xl border bg-card p-5 sm:p-6"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-informative-subtle text-informative-strong"
                >
                    <Video class="size-5" />
                </span>
                <div>
                    <h2 class="font-extrabold">
                        Durée des réunions vidéo selon l'offre
                    </h2>
                    <p
                        class="mt-1 text-sm leading-relaxed text-muted-foreground"
                    >
                        Comme sur les grandes plateformes de visioconférence,
                        chaque offre fixe la durée maximale d'une réunion :
                        <template
                            v-for="(limit, index) in meetingLimits"
                            :key="limit.name"
                            ><strong class="text-foreground">{{
                                limit.name
                            }}</strong>
                            {{ limit.duration }} ({{
                                limit.participants
                            }}
                            participants)<template
                                v-if="index < meetingLimits.length - 1"
                                >,
                            </template></template
                        >. Un compte à rebours prévient les participants, puis
                        la réunion se ferme automatiquement.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Payment -->
    <section class="bg-[var(--mk-layer)] px-4 py-20 sm:px-6">
        <div
            class="mx-auto grid max-w-7xl items-center gap-12 lg:grid-cols-[1.1fr_1fr]"
        >
            <div v-reveal>
                <h2 class="text-3xl font-extrabold sm:text-[2.5rem]">
                    Payez comme vous en avez l'habitude.
                </h2>
                <p class="mt-4 text-lg text-muted-foreground">
                    Orange Money, Moov Money ou carte bancaire, via notre
                    partenaire de paiement PayDunya : le paiement se fait en
                    quelques secondes depuis votre téléphone, et votre offre est
                    active aussitôt.
                </p>
                <div class="mt-8 grid gap-5 sm:grid-cols-3 lg:grid-cols-1">
                    <div
                        v-for="note in billingNotes"
                        :key="note.title"
                        class="flex gap-4"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-informative-subtle text-informative-strong"
                        >
                            <component :is="note.icon" class="size-5" />
                        </span>
                        <div>
                            <h3 class="font-extrabold">{{ note.title }}</h3>
                            <p
                                class="mt-1 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ note.text }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div v-reveal="120">
                <PaymentIllustration />
            </div>
        </div>
    </section>

    <!-- Comparison -->
    <section class="px-4 py-20 sm:px-6">
        <div class="mx-auto max-w-[96rem]">
            <h2 v-reveal class="text-3xl font-extrabold sm:text-[2.5rem]">
                Comparer les offres
            </h2>
            <div class="mt-8 overflow-x-auto rounded-2xl border">
                <Table>
                    <TableHeader>
                        <TableRow
                            class="bg-[var(--mk-layer)] hover:bg-[var(--mk-layer)]"
                        >
                            <TableHead class="min-w-48 py-4" />
                            <TableHead
                                v-for="plan in plans"
                                :key="plan.code"
                                class="min-w-40 py-4 text-base font-extrabold text-heading"
                                >{{ plan.name }}</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow>
                            <TableCell class="py-3.5 text-muted-foreground"
                                >Prix mensuel</TableCell
                            >
                            <TableCell
                                v-for="plan in plans"
                                :key="plan.code"
                                class="py-3.5 font-bold tabular-nums"
                                >{{
                                    plan.isFree
                                        ? 'Gratuit'
                                        : formatMoney(
                                              plan.monthlyPrice,
                                              currency,
                                          )
                                }}</TableCell
                            >
                        </TableRow>
                        <template
                            v-for="section in comparisonSections"
                            :key="section.title"
                        >
                            <TableRow class="hover:bg-transparent">
                                <TableCell
                                    :colspan="plans.length + 1"
                                    class="pt-7 pb-2 text-xs font-bold tracking-wide text-primary uppercase"
                                    >{{ section.title }}</TableCell
                                >
                            </TableRow>
                            <TableRow
                                v-for="row in section.rows"
                                :key="row.label"
                            >
                                <TableCell
                                    class="py-3.5 text-muted-foreground"
                                    >{{ row.label }}</TableCell
                                >
                                <TableCell
                                    v-for="plan in plans"
                                    :key="plan.code"
                                    class="py-3.5"
                                >
                                    <template
                                        v-if="
                                            typeof row.value(plan) === 'boolean'
                                        "
                                    >
                                        <span
                                            v-if="row.value(plan)"
                                            class="inline-flex size-5 items-center justify-center rounded-full bg-positive-subtle text-positive-strong"
                                        >
                                            <Check
                                                class="size-3"
                                                aria-label="Inclus"
                                            />
                                        </span>
                                        <Minus
                                            v-else
                                            class="size-4 text-muted-foreground"
                                            aria-label="Non inclus"
                                        />
                                    </template>
                                    <template v-else>{{
                                        row.value(plan)
                                    }}</template>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableBody>
                </Table>
            </div>
            <p class="mt-6 text-sm text-muted-foreground">
                Une question sur les offres ?
                <Link
                    href="/contact"
                    class="font-bold text-primary hover:underline"
                    >Parlons-en</Link
                >.
            </p>
        </div>
    </section>
</template>
