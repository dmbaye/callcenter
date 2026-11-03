<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Activity, Phone, Settings2, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';

import AgentPerformanceTable from '@/components/AgentPerformanceTable.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import StatCard from '@/components/StatCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDay, formatDuration } from '@/lib/format';
import { dashboard, monitoring } from '@/routes';
import { edit as editTeam } from '@/routes/teams';
import type {
    CallSummary,
    DailyPoint,
    DashboardInvitation,
    RosterAgent,
    Team,
} from '@/types';

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    today: CallSummary;
    week: CallSummary;
    daily: DailyPoint[];
    roster: RosterAgent[];
    roles: Record<string, number>;
    setup: {
        members: number;
        without_extension: string[];
        without_queue: string[];
    };
    queues: string[];
    csat?: { average: number | null; responses: number; satisfied: number };
    callbacksWaiting?: number;
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

const maxDaily = computed(() =>
    Math.max(1, ...props.daily.map((d) => d.total)),
);
const barHeight = (value: number) =>
    `${Math.round((value / maxDaily.value) * 100)}%`;

const rateTone = computed(() =>
    props.today.total === 0
        ? 'default'
        : props.today.answer_rate >= 80
          ? 'good'
          : props.today.answer_rate >= 60
            ? 'warn'
            : 'bad',
);

const roleLabels: Record<string, string> = {
    owner: 'Propriétaires',
    admin: 'Administrateurs',
    supervisor: 'Superviseurs',
    planner: 'Planificateurs',
    member: 'Agents',
};

const todayLabel = new Intl.DateTimeFormat(intlLocale(), {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
}).format(new Date());

const hasSetupIssues = computed(
    () =>
        props.setup.without_extension.length > 0 ||
        props.setup.without_queue.length > 0 ||
        props.queues.length === 0,
);
</script>

<template>
    <Head :title="t('Tableau de bord de l\'équipe')" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-auto p-4 sm:p-6">
        <div
            class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between"
        >
            <div class="min-w-0">
                <div class="text-sm font-medium text-muted-foreground">
                    {{ todayLabel }}
                </div>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                    {{ t("Tableau de bord de l'équipe") }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            "Les performances de votre centre d'appels, la composition de l'équipe et l'état de la configuration.",
                        )
                    }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <Button
                    v-if="$page.props.billing?.features?.monitoring !== false"
                    variant="outline"
                    class="gap-2"
                    @click="router.visit(monitoring(slug).url)"
                >
                    <Activity class="h-4 w-4" />
                    {{ t('Supervision en direct') }}
                </Button>
                <Button
                    variant="outline"
                    class="gap-2"
                    @click="router.visit(dashboard(slug).url + '?view=agent')"
                >
                    <Phone class="h-4 w-4" />
                    {{ t('Ma vue agent') }}
                </Button>
                <Button class="gap-2" @click="router.visit(editTeam(slug).url)">
                    <Settings2 class="h-4 w-4" />
                    {{ t("Gérer l'équipe") }}
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <StatCard :label="t('Appels aujourd\'hui')" :value="today.total" />
            <StatCard
                :label="t('Répondus')"
                :value="today.answered"
                :tone="rateTone"
                :hint="
                    today.total > 0
                        ? `${today.answer_rate} % des appels`
                        : undefined
                "
            />
            <StatCard
                :label="t('Manqués')"
                :value="today.missed"
                :tone="today.missed > 0 ? 'bad' : 'default'"
            />
            <StatCard
                :label="t('Durée moy. de conversation')"
                :value="formatDuration(today.avg_talk_seconds)"
            />
            <StatCard
                :label="t('Satisfaction (7 j)')"
                :value="
                    props.csat?.average !== null &&
                    props.csat?.average !== undefined
                        ? `${props.csat.average} / 5`
                        : '—'
                "
                :hint="
                    props.csat?.responses
                        ? `${props.csat.satisfied} % satisfaits · ${props.csat.responses} avis`
                        : 'Enquête désactivée ou sans réponse'
                "
                tone="info"
            />
            <StatCard
                :label="t('Rappels en attente')"
                :value="props.callbacksWaiting ?? 0"
                :tone="(props.callbacksWaiting ?? 0) > 0 ? 'warn' : 'default'"
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('7 derniers jours') }}</CardTitle>
                        <CardDescription>
                            {{
                                t(':p0 appels, :p1 % répondus, :p2 manqués.', {
                                    p0: week.total,
                                    p1: week.answer_rate,
                                    p2: week.missed,
                                })
                            }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="flex h-40 items-end gap-3">
                            <div
                                v-for="day in daily"
                                :key="day.date"
                                class="flex h-full flex-1 flex-col items-center justify-end gap-1"
                            >
                                <div
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ day.total }}
                                </div>
                                <div
                                    class="flex w-full max-w-12 flex-1 flex-col justify-end overflow-hidden rounded-t-[4px] bg-muted"
                                >
                                    <div
                                        class="w-full bg-negative"
                                        :style="{
                                            height: barHeight(day.missed),
                                        }"
                                        :title="`${day.missed} manqués`"
                                    />
                                    <div
                                        class="w-full bg-primary"
                                        :style="{
                                            height: barHeight(day.answered),
                                        }"
                                        :title="`${day.answered} répondus`"
                                    />
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ formatDay(day.date) }}
                                </div>
                            </div>
                        </div>
                        <div
                            class="mt-3 flex gap-4 text-xs text-muted-foreground"
                        >
                            <span class="flex items-center gap-1.5"
                                ><span
                                    class="h-2 w-2 rounded-full bg-primary"
                                />{{ t('Répondus') }}</span
                            >
                            <span class="flex items-center gap-1.5"
                                ><span
                                    class="h-2 w-2 rounded-full bg-negative"
                                />{{ t('Manqués') }}</span
                            >
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{ t("Agents aujourd'hui") }}</CardTitle>
                        <CardDescription>{{
                            t(
                                "Les appels répondus et manqués sont ceux qui ont sonné sur le téléphone de l'agent.",
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <AgentPerformanceTable :agents="roster" />
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Équipe') }}</CardTitle>
                        <CardDescription>{{
                            t(':p0 membres', { p0: setup.members })
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div
                            v-for="(count, role) in roles"
                            :key="role"
                            class="flex items-center justify-between text-sm"
                        >
                            <span class="text-muted-foreground">{{
                                roleLabels[role] ?? role
                            }}</span>
                            <span class="font-medium tabular-nums">{{
                                count
                            }}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Files') }}</CardTitle>
                        <CardDescription>{{
                            t('Les files auxquelles vos agents répondent.')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-wrap gap-2">
                        <Badge
                            v-for="queue in queues"
                            :key="queue"
                            variant="secondary"
                            >{{ queue }}</Badge
                        >
                        <span
                            v-if="queues.length === 0"
                            class="text-sm text-muted-foreground"
                            >{{ t("Aucune file pour l'instant.") }}</span
                        >
                    </CardContent>
                </Card>

                <Card v-if="hasSetupIssues" class="border-notice/40">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-notice">
                            <TriangleAlert class="h-5 w-5" />
                            {{ t('À surveiller') }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <p v-if="queues.length === 0">
                            {{
                                t(
                                    "Aucun agent n'a encore de poste et de file : aucun appel ne peut être pris.",
                                )
                            }}
                        </p>
                        <div v-if="setup.without_extension.length > 0">
                            <div class="font-medium">
                                {{ t('Aucun poste SIP') }}
                            </div>
                            <div class="text-muted-foreground">
                                {{ setup.without_extension.join(', ') }}
                            </div>
                        </div>
                        <div v-if="setup.without_queue.length > 0">
                            <div class="font-medium">
                                {{ t('Aucune file assignée') }}
                            </div>
                            <div class="text-muted-foreground">
                                {{ setup.without_queue.join(', ') }}
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="router.visit(editTeam(slug).url)"
                        >
                            {{ t("Corriger dans les paramètres de l'équipe") }}
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
