<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CalendarOff,
    CheckCheck,
    TimerReset,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import ReviewDialog from '@/components/workforce/ReviewDialog.vue';
import StatusPill from '@/components/workforce/StatusPill.vue';
import WorkforceNav from '@/components/workforce/WorkforceNav.vue';
import { t } from '@/lib/i18n';
import {
    formatDay,
    formatTime,
    leavePeriod,
    planningUrl,
    shiftLabel,
} from '@/lib/workforce';
import type {
    AdjustmentCard,
    LeaveBalance,
    LeaveCard,
    ShiftCard,
    SwapCard,
    Team,
    WorkforceShell,
} from '@/types';

const props = defineProps<{
    workforce: WorkforceShell;
    swaps: (SwapCard & { ruleWarnings: string[] })[];
    leave: (LeaveCard & {
        impact: {
            assignments: ShiftCard[];
            partial: ShiftCard[];
            shortages: string[];
        };
        balance: LeaveBalance | null;
    })[];
    adjustments: (AdjustmentCard & {
        current: {
            clockIn: string;
            clockOut: string | null;
            breakMinutes: number;
        } | null;
    })[];
    history: {
        swaps: SwapCard[];
        leave: LeaveCard[];
        adjustments: AdjustmentCard[];
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
                title: 'Demandes',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning/requests`
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');

const empty = computed(
    () =>
        props.swaps.length + props.leave.length + props.adjustments.length ===
        0,
);

// One dialog for every kind of request.
const dialog = ref<{
    open: boolean;
    approve: boolean;
    title: string;
    url: string;
}>({
    open: false,
    approve: true,
    title: '',
    url: '',
});
const processing = ref(false);

function ask(url: string, approve: boolean, title: string) {
    dialog.value = { open: true, approve, title, url };
}

function confirm(note: string) {
    processing.value = true;
    router.post(
        dialog.value.url,
        { approve: dialog.value.approve, review_note: note || null },
        {
            preserveScroll: true,
            onSuccess: () => (dialog.value.open = false),
            onError: (errors) =>
                toast.error(
                    Object.values(errors).flat()[0] ?? t('Action impossible.'),
                ),
            onFinish: () => (processing.value = false),
        },
    );
}

const adjustmentStatus: Record<string, string> = {
    pending: 'En attente',
    approved: 'Approuvée',
    rejected: 'Refusée',
};
</script>

<template>
    <Head :title="t('Demandes')" />

    <div class="flex flex-col gap-8 p-4 md:p-6">
        <WorkforceNav :workforce="workforce" current="requests" />

        <div
            v-if="empty"
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed p-10 text-center"
        >
            <CheckCheck class="size-8 text-positive" />
            <p class="font-medium">{{ t('Rien à traiter') }}</p>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Les échanges, congés et corrections de pointage à valider apparaîtront ici.',
                    )
                }}
            </p>
        </div>

        <!-- Swaps -->
        <section v-if="swaps.length" class="grid gap-3">
            <h2 class="flex items-center gap-2 text-base font-semibold">
                <ArrowLeftRight class="size-4" />
                {{ t('Échanges de postes') }}
                <span class="text-sm font-normal text-muted-foreground"
                    >({{ swaps.length }})</span
                >
            </h2>
            <article
                v-for="swap in swaps"
                :key="swap.id"
                class="grid gap-3 rounded-lg border p-4 md:grid-cols-[1fr_auto]"
            >
                <div class="grid gap-1 text-sm">
                    <p class="font-medium">
                        {{ swap.requester.name }}
                        <ArrowLeftRight
                            class="mx-1 inline size-3.5 text-muted-foreground"
                        />
                        {{ swap.counterparty.name }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">{{
                            t(':name cède :', { name: swap.requester.name })
                        }}</span>
                        {{ shiftLabel(swap.give) }}
                    </p>
                    <p v-if="swap.take">
                        <span class="text-muted-foreground">{{
                            t(':name cède :', { name: swap.counterparty.name })
                        }}</span>
                        {{ shiftLabel(swap.take) }}
                    </p>
                    <p v-else class="text-muted-foreground">
                        {{ t('Cession simple, sans contrepartie.') }}
                    </p>
                    <p v-if="swap.message" class="italic">
                        « {{ swap.message }} »
                    </p>
                    <ul v-if="swap.ruleWarnings.length" class="mt-1 grid gap-1">
                        <li
                            v-for="warning in swap.ruleWarnings"
                            :key="warning"
                            class="flex items-start gap-1.5 text-notice-strong"
                        >
                            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                            {{ warning }}
                        </li>
                    </ul>
                </div>
                <div class="flex items-start gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="
                            ask(
                                planningUrl(slug, `swaps/${swap.id}/review`),
                                false,
                                t('Refuser l’échange'),
                            )
                        "
                    >
                        {{ t('Refuser') }}
                    </Button>
                    <Button
                        size="sm"
                        @click="
                            ask(
                                planningUrl(slug, `swaps/${swap.id}/review`),
                                true,
                                t('Approuver l’échange'),
                            )
                        "
                    >
                        {{ t('Approuver') }}
                    </Button>
                </div>
            </article>
        </section>

        <!-- Leave -->
        <section v-if="leave.length" class="grid gap-3">
            <h2 class="flex items-center gap-2 text-base font-semibold">
                <CalendarOff class="size-4" />
                {{ t('Congés') }}
                <span class="text-sm font-normal text-muted-foreground"
                    >({{ leave.length }})</span
                >
            </h2>
            <article
                v-for="item in leave"
                :key="item.id"
                class="grid gap-3 rounded-lg border p-4 md:grid-cols-[1fr_auto]"
            >
                <div class="grid gap-1 text-sm">
                    <p class="font-medium">
                        {{ item.user.name }} · {{ item.type.name }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ t(':n j', { n: item.days }) }}</span
                        >
                    </p>
                    <p class="capitalize">{{ leavePeriod(item) }}</p>
                    <p v-if="item.reason" class="italic">
                        « {{ item.reason }} »
                    </p>
                    <p
                        v-if="item.balance && item.balance.remaining !== null"
                        class="text-muted-foreground"
                    >
                        {{
                            t('Solde après cette demande : :n j sur :total', {
                                n: item.balance.remaining,
                                total: item.balance.allowance,
                            })
                        }}
                    </p>
                    <p
                        v-if="item.impact.assignments.length"
                        class="text-muted-foreground"
                    >
                        {{ t('Postes retirés du roster :') }}
                        {{
                            item.impact.assignments
                                .map((s) => `${s.code} ${formatDay(s.date)}`)
                                .join(', ')
                        }}
                    </p>
                    <p
                        v-if="item.impact.partial.length"
                        class="text-muted-foreground"
                    >
                        {{ t('Postes raccourcis :') }}
                        {{
                            item.impact.partial
                                .map((s) => `${s.code} ${formatDay(s.date)}`)
                                .join(', ')
                        }}
                    </p>
                    <ul
                        v-if="item.impact.shortages.length"
                        class="mt-1 grid gap-1"
                    >
                        <li
                            v-for="shortage in item.impact.shortages"
                            :key="shortage"
                            class="flex items-start gap-1.5 text-negative-strong"
                        >
                            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                            {{ shortage }}
                        </li>
                    </ul>
                </div>
                <div class="flex items-start gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="
                            ask(
                                planningUrl(slug, `leave/${item.id}/review`),
                                false,
                                t('Refuser le congé'),
                            )
                        "
                    >
                        {{ t('Refuser') }}
                    </Button>
                    <Button
                        size="sm"
                        @click="
                            ask(
                                planningUrl(slug, `leave/${item.id}/review`),
                                true,
                                t('Approuver le congé'),
                            )
                        "
                    >
                        {{ t('Approuver') }}
                    </Button>
                </div>
            </article>
        </section>

        <!-- Adjustments -->
        <section v-if="adjustments.length" class="grid gap-3">
            <h2 class="flex items-center gap-2 text-base font-semibold">
                <TimerReset class="size-4" />
                {{ t('Corrections de pointage') }}
                <span class="text-sm font-normal text-muted-foreground"
                    >({{ adjustments.length }})</span
                >
            </h2>
            <article
                v-for="item in adjustments"
                :key="item.id"
                class="grid gap-3 rounded-lg border p-4 md:grid-cols-[1fr_auto]"
            >
                <div class="grid gap-1 text-sm">
                    <p class="font-medium capitalize">
                        {{ item.user.name }} ·
                        {{
                            formatDay(item.date, {
                                weekday: 'long',
                                day: 'numeric',
                                month: 'long',
                            })
                        }}
                    </p>
                    <p
                        v-if="item.current"
                        class="text-muted-foreground tabular-nums"
                    >
                        {{ t('Pointé :') }}
                        {{ formatTime(item.current.clockIn) }} –
                        {{ formatTime(item.current.clockOut) }} ·
                        {{
                            t('pause :n min', { n: item.current.breakMinutes })
                        }}
                    </p>
                    <p v-else class="text-muted-foreground">
                        {{ t('Aucun pointage ce jour-là.') }}
                    </p>
                    <p class="tabular-nums">
                        {{ t('Demandé :') }} {{ formatTime(item.clockIn) }} –
                        {{ formatTime(item.clockOut) }}
                        <template v-if="item.breakMinutes !== null"
                            >·
                            {{
                                t('pause :n min', { n: item.breakMinutes })
                            }}</template
                        >
                    </p>
                    <p class="italic">« {{ item.reason }} »</p>
                </div>
                <div class="flex items-start gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="
                            ask(
                                planningUrl(
                                    slug,
                                    `adjustments/${item.id}/review`,
                                ),
                                false,
                                t('Refuser la correction'),
                            )
                        "
                    >
                        {{ t('Refuser') }}
                    </Button>
                    <Button
                        size="sm"
                        @click="
                            ask(
                                planningUrl(
                                    slug,
                                    `adjustments/${item.id}/review`,
                                ),
                                true,
                                t('Approuver la correction'),
                            )
                        "
                    >
                        {{ t('Approuver') }}
                    </Button>
                </div>
            </article>
        </section>

        <!-- History -->
        <section
            v-if="
                history.swaps.length ||
                history.leave.length ||
                history.adjustments.length
            "
            class="grid gap-3"
        >
            <h2 class="text-base font-semibold">
                {{ t('Décisions récentes') }}
            </h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="swap in history.swaps"
                    :key="`s${swap.id}`"
                    class="flex flex-wrap items-center gap-3 px-4 py-2.5"
                >
                    <ArrowLeftRight class="size-4 text-muted-foreground" />
                    <span class="flex-1"
                        >{{ swap.requester.name }} ⇄
                        {{ swap.counterparty.name }} ·
                        {{ shiftLabel(swap.give) }}</span
                    >
                    <span class="text-xs text-muted-foreground">{{
                        swap.autoApproved ? t('automatique') : swap.reviewer
                    }}</span>
                    <StatusPill
                        :status="swap.status"
                        :label="swap.statusLabel"
                    />
                </li>
                <li
                    v-for="item in history.leave"
                    :key="`l${item.id}`"
                    class="flex flex-wrap items-center gap-3 px-4 py-2.5"
                >
                    <CalendarOff class="size-4 text-muted-foreground" />
                    <span class="flex-1"
                        >{{ item.user.name }} · {{ item.type.name }} ·
                        {{ leavePeriod(item) }}</span
                    >
                    <span class="text-xs text-muted-foreground">{{
                        item.reviewer
                    }}</span>
                    <StatusPill
                        :status="item.status"
                        :label="item.statusLabel"
                    />
                </li>
                <li
                    v-for="item in history.adjustments"
                    :key="`a${item.id}`"
                    class="flex flex-wrap items-center gap-3 px-4 py-2.5"
                >
                    <TimerReset class="size-4 text-muted-foreground" />
                    <span class="flex-1">
                        {{ item.user.name }} · {{ formatDay(item.date) }} ·
                        {{ formatTime(item.clockIn) }}–{{
                            formatTime(item.clockOut)
                        }}
                    </span>
                    <span class="text-xs text-muted-foreground">{{
                        item.reviewer
                    }}</span>
                    <StatusPill
                        :status="item.status"
                        :label="t(adjustmentStatus[item.status] ?? item.status)"
                    />
                </li>
            </ul>
        </section>
    </div>

    <ReviewDialog
        v-model:open="dialog.open"
        :approve="dialog.approve"
        :title="dialog.title"
        :processing="processing"
        @confirm="confirm"
    />
</template>
