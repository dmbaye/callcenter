<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    CircleCheck,
    Info,
    Phone,
    PhoneCall,
    PhoneIncoming,
    PhoneMissed,
    Users,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';

import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import StatCard from '@/components/StatCard.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useSoftphone } from '@/composables/useSoftphone';
import { initials } from '@/lib/avatar';
import { formatDuration, formatTime } from '@/lib/format';
import { calls as callsRoute, dashboard } from '@/routes';
import { index as meetingsIndex, show as meetingShow } from '@/routes/meetings';
import type { DashboardCall, DashboardInvitation, Team } from '@/types';

type DayState = { state: string; label: string; seconds: number };
type TodayMeeting = {
    uuid: string;
    title: string;
    scheduledAt: string | null;
    durationMinutes: number | null;
    live: boolean;
    participants: number;
};

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    totalCalls: number;
    answeredCalls: number;
    missedCalls: number;
    avgTalkSeconds: number | null;
    talkSeconds?: number;
    recentCalls: DashboardCall[];
    missedList: DashboardCall[];
    hasExtension: boolean;
    teamViewAvailable?: boolean;
    myDay?: DayState[];
    meetings?: TodayMeeting[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Tableau de bord',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const firstName = computed(
    () => (page.props.auth?.user?.name ?? '').split(' ')[0] ?? '',
);

// The agent's status lives in the top bar menu and the softphone; this page
// is about the day's work, so it does not repeat it.
const { openDialer } = useSoftphone();

const answerRate = computed(() =>
    props.totalCalls === 0
        ? 0
        : Math.round((props.answeredCalls / props.totalCalls) * 100),
);

const todayLabel = (() => {
    const label = new Intl.DateTimeFormat(intlLocale(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date());

    return label.charAt(0).toUpperCase() + label.slice(1);
})();

const greeting = new Date().getHours() < 18 ? 'Bonjour' : 'Bonsoir';

/** Short durations for the day summary: "2 h 05", "18 min", "40 s". */
function compactDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds} s`;
    }

    const minutes = Math.round(seconds / 60);

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`;
}

const stateColors: Record<string, string> = {
    ready: 'bg-positive',
    not_ready: 'bg-notice',
    wrap_up: 'bg-informative',
    dnd: 'bg-negative',
    logged_out: 'bg-muted-foreground/40',
};

const dayTotal = computed(() =>
    (props.myDay ?? []).reduce((sum, row) => sum + row.seconds, 0),
);

const readyShare = computed(() => {
    const ready = props.myDay?.find((row) => row.state === 'ready');

    return dayTotal.value === 0 || !ready
        ? 0
        : Math.round((ready.seconds / dayTotal.value) * 100);
});

const meetingTime = (meeting: TodayMeeting) => {
    if (meeting.live) {
        return 'En cours';
    }

    if (!meeting.scheduledAt) {
        return '';
    }

    const start = new Date(meeting.scheduledAt);
    const end = new Date(
        start.getTime() + (meeting.durationMinutes ?? 30) * 60_000,
    );
    const hour = new Intl.DateTimeFormat(intlLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    });

    return `${hour.format(start)} – ${hour.format(end)}`;
};

const callerName = (call: DashboardCall) =>
    call.name || call.number || 'Appelant inconnu';
</script>

<template>
    <Head :title="t('Tableau de bord')" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-auto p-4 sm:p-6">
        <!-- Header -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="min-w-0">
                <div class="text-sm font-medium text-muted-foreground">
                    {{ todayLabel }}
                </div>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                    {{ greeting }}{{ firstName ? `, ${firstName}` : '' }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            "Votre journée en un coup d'œil : appels, rappels et réunions.",
                        )
                    }}
                </p>
            </div>
            <div class="flex shrink-0 gap-2">
                <Button
                    v-if="teamViewAvailable"
                    variant="outline"
                    class="gap-2"
                    @click="router.visit(dashboard(slug).url)"
                >
                    <Users class="h-4 w-4" />
                    {{ t("Vue d'équipe") }}
                </Button>
                <Button class="gap-2" @click="openDialer()">
                    <Phone class="h-4 w-4" />
                    {{ t('Passer un appel') }}
                </Button>
            </div>
        </div>

        <div
            v-if="!hasExtension"
            class="flex items-start gap-3 rounded-lg border bg-informative-subtle px-4 py-3 text-sm"
        >
            <Info class="mt-0.5 h-4 w-4 shrink-0 text-informative-strong" />
            <div>
                <div class="font-medium">
                    {{ t('Aucune extension attribuée') }}
                </div>
                <div class="text-muted-foreground">
                    {{
                        t(
                            "Vos statistiques d'appels apparaîtront ici dès qu'un administrateur vous aura attribué une extension.",
                        )
                    }}
                </div>
            </div>
        </div>

        <!-- Today -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                :label="t('Appels aujourd\'hui')"
                :value="totalCalls"
                :hint="
                    totalCalls === 0 ? 'Aucun appel pour le moment' : undefined
                "
            />
            <StatCard
                :label="t('Répondus')"
                :value="answeredCalls"
                tone="good"
                :hint="
                    totalCalls > 0 ? `${answerRate} % des appels` : undefined
                "
            />
            <StatCard
                :label="t('Manqués')"
                :value="missedCalls"
                :tone="missedCalls > 0 ? 'bad' : 'default'"
                :hint="
                    missedList.length > 0
                        ? `${missedList.length} à rappeler`
                        : missedCalls > 0
                          ? undefined
                          : 'Vous êtes à jour'
                "
            />
            <StatCard
                :label="t('Temps de conversation')"
                :value="compactDuration(talkSeconds ?? 0)"
                :hint="
                    avgTalkSeconds !== null
                        ? `${formatDuration(avgTalkSeconds)} en moyenne par appel`
                        : undefined
                "
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <!-- Left: what needs doing, then what happened -->
            <div class="flex min-w-0 flex-col gap-6">
                <Card>
                    <CardHeader class="flex flex-row items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                            :class="
                                missedList.length > 0
                                    ? 'bg-negative-subtle text-negative-strong'
                                    : 'bg-positive-subtle text-positive-strong'
                            "
                        >
                            <PhoneMissed
                                v-if="missedList.length > 0"
                                class="size-4"
                            />
                            <CircleCheck v-else class="size-4" />
                        </span>
                        <div>
                            <CardTitle>{{ t('À rappeler') }}</CardTitle>
                            <CardDescription>
                                {{
                                    missedList.length > 0
                                        ? t(
                                              'Appels manqués aujourd’hui, du plus récent au plus ancien.',
                                          )
                                        : t('Aucun appel manqué aujourd’hui.')
                                }}
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent v-if="missedList.length > 0">
                        <ul class="divide-y rounded-md border">
                            <li
                                v-for="call in missedList"
                                :key="call.id"
                                class="flex items-center gap-3 px-3 py-2.5"
                            >
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold"
                                    >{{ initials(callerName(call)) }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ callerName(call) }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ call.number }} ·
                                        {{ formatTime(call.started_at) }}
                                    </div>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="gap-1.5"
                                    @click="openDialer(call.number)"
                                >
                                    <PhoneCall class="h-3.5 w-3.5" />
                                    {{ t('Rappeler') }}
                                </Button>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-start justify-between gap-3"
                    >
                        <div>
                            <CardTitle>{{ t('Appels récents') }}</CardTitle>
                            <CardDescription>{{
                                t('Les derniers appels reçus sur votre poste.')
                            }}</CardDescription>
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="callsRoute(slug).url" class="gap-1">
                                {{ t('Historique') }}
                                <ArrowRight class="h-3.5 w-3.5" />
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <ul
                            v-if="recentCalls.length > 0"
                            class="divide-y rounded-md border"
                        >
                            <li
                                v-for="call in recentCalls"
                                :key="call.id"
                                class="flex items-center gap-3 px-3 py-2.5"
                            >
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        call.callType === 'missed'
                                            ? 'bg-negative-subtle text-negative-strong'
                                            : 'bg-positive-subtle text-positive-strong'
                                    "
                                >
                                    <PhoneMissed
                                        v-if="call.callType === 'missed'"
                                        class="size-4"
                                    />
                                    <PhoneIncoming v-else class="size-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ callerName(call) }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ call.number }}
                                    </div>
                                </div>
                                <div class="text-right text-xs">
                                    <div
                                        :class="
                                            call.callType === 'missed'
                                                ? 'text-negative-strong'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{
                                            call.callType === 'missed'
                                                ? t('Manqué')
                                                : t('Répondu')
                                        }}
                                    </div>
                                    <div class="text-muted-foreground">
                                        {{ formatTime(call.started_at) }}
                                    </div>
                                </div>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    title="Rappeler"
                                    @click="openDialer(call.number)"
                                >
                                    <Phone class="h-4 w-4" />
                                </Button>
                            </li>
                        </ul>
                        <div
                            v-else
                            class="flex flex-col items-center rounded-md border border-dashed px-6 py-10 text-center"
                        >
                            <span
                                class="flex size-10 items-center justify-center rounded-full bg-muted"
                            >
                                <Phone class="size-4 text-muted-foreground" />
                            </span>
                            <div class="mt-3 text-sm font-medium">
                                {{ t("Aucun appel aujourd'hui") }}
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    t(
                                        'Les appels que vous traitez apparaîtront ici.',
                                    )
                                }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Right: the day's rhythm -->
            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Ma journée</CardTitle>
                        <CardDescription
                            >Le temps passé dans chaque statut depuis
                            minuit.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <template v-if="dayTotal > 0">
                            <div class="flex items-baseline justify-between">
                                <div
                                    class="text-2xl font-extrabold tabular-nums"
                                >
                                    {{ compactDuration(dayTotal) }}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    dont {{ readyShare }} % prêt
                                </div>
                            </div>
                            <div
                                class="mt-3 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-muted"
                                role="img"
                                :aria-label="`Répartition du temps : ${myDay!.map((r) => `${r.label} ${compactDuration(r.seconds)}`).join(', ')}`"
                            >
                                <span
                                    v-for="row in myDay"
                                    :key="row.state"
                                    :class="
                                        stateColors[row.state] ??
                                        'bg-muted-foreground'
                                    "
                                    :style="{
                                        width: `${(row.seconds / dayTotal) * 100}%`,
                                    }"
                                />
                            </div>
                            <ul class="mt-4 space-y-2 text-sm">
                                <li
                                    v-for="row in myDay"
                                    :key="row.state"
                                    class="flex items-center gap-2"
                                >
                                    <span
                                        class="size-2.5 rounded-full"
                                        :class="
                                            stateColors[row.state] ??
                                            'bg-muted-foreground'
                                        "
                                    />
                                    <span class="flex-1">{{ row.label }}</span>
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                        >{{
                                            compactDuration(row.seconds)
                                        }}</span
                                    >
                                </li>
                            </ul>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            Rien d'enregistré pour l'instant. Choisissez « Prêt
                            » dans le menu en haut à droite pour commencer votre
                            journée.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader
                        class="flex flex-row items-start justify-between gap-3"
                    >
                        <div>
                            <CardTitle>Réunions du jour</CardTitle>
                            <CardDescription
                                >Celles où vous êtes invité.</CardDescription
                            >
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="meetingsIndex(slug).url" class="gap-1">
                                Tout voir
                                <ArrowRight class="h-3.5 w-3.5" />
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <ul
                            v-if="meetings && meetings.length"
                            class="space-y-2"
                        >
                            <li
                                v-for="meeting in meetings"
                                :key="meeting.uuid"
                                class="flex items-center gap-3 rounded-md border px-3 py-2.5"
                            >
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                    :class="
                                        meeting.live
                                            ? 'bg-positive-subtle text-positive-strong'
                                            : 'bg-informative-subtle text-informative-strong'
                                    "
                                >
                                    <Video v-if="meeting.live" class="size-4" />
                                    <CalendarClock v-else class="size-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ meeting.title }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-muted-foreground"
                                        :title="`${meeting.participants} participants`"
                                    >
                                        <span
                                            :class="
                                                meeting.live &&
                                                'font-medium text-positive-strong'
                                            "
                                            >{{ meetingTime(meeting) }}</span
                                        >
                                        · {{ meeting.participants }} pers.
                                    </div>
                                </div>
                                <Button
                                    size="sm"
                                    :variant="
                                        meeting.live ? 'default' : 'outline'
                                    "
                                    as-child
                                >
                                    <Link
                                        :href="
                                            meetingShow({
                                                current_team: slug,
                                                meeting: meeting.uuid,
                                            }).url
                                        "
                                        >Rejoindre</Link
                                    >
                                </Button>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">
                            Aucune réunion prévue aujourd'hui.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
