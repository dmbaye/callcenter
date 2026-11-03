<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Activity, History, Phone, PhoneCall } from '@lucide/vue';
import { computed } from 'vue';

import AgentPerformanceTable from '@/components/AgentPerformanceTable.vue';
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
import { useLiveMonitoring } from '@/composables/useLiveMonitoring';
import { useSoftphone } from '@/composables/useSoftphone';
import { formatDuration, formatTime } from '@/lib/format';
import { calls as callsRoute, dashboard, monitoring } from '@/routes';
import type {
    CallSummary,
    DashboardCall,
    DashboardInvitation,
    RosterAgent,
    Team,
} from '@/types';

defineProps<{
    pendingInvitations?: DashboardInvitation[];
    today: CallSummary;
    roster: RosterAgent[];
    missedList: DashboardCall[];
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

// Live numbers (calls waiting, who is on a call, who is free), refreshed every 5 s.
const { snapshot } = useLiveMonitoring(undefined, 5000);

const waitingTone = computed(() => {
    const { waiting, longest_wait } = snapshot.value.summary;

    return waiting === 0 ? 'default' : longest_wait >= 60 ? 'bad' : 'warn';
});

const { openDialer } = useSoftphone();

const callBack = (number: string) => openDialer(number);

const todayLabel = new Intl.DateTimeFormat(intlLocale(), {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
}).format(new Date());
</script>

<template>
    <Head :title="t('Tableau de bord superviseur')" />

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
                    {{ t('Tableau de bord superviseur') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            "L'activité en ce moment et la performance de l'équipe aujourd'hui.",
                        )
                    }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <Button
                    v-if="$page.props.billing?.features?.monitoring !== false"
                    class="gap-2"
                    @click="router.visit(monitoring(slug).url)"
                >
                    <Activity class="h-4 w-4" />
                    {{ t('Supervision en direct') }}
                </Button>
                <Button
                    variant="outline"
                    class="gap-2"
                    @click="router.visit(callsRoute(slug).url)"
                >
                    <History class="h-4 w-4" />
                    {{ t('Historique des appels') }}
                </Button>
                <Button
                    variant="outline"
                    class="gap-2"
                    @click="router.visit(dashboard(slug).url + '?view=agent')"
                >
                    <Phone class="h-4 w-4" />
                    {{ t('Ma vue agent') }}
                </Button>
            </div>
        </div>

        <!-- Live floor -->
        <div>
            <h2
                class="mb-2 text-sm font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ t('En ce moment') }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    :label="t('Appels en attente')"
                    :value="snapshot.summary.waiting"
                    :tone="waitingTone"
                />
                <StatCard
                    :label="t('Attente la plus longue')"
                    :value="formatDuration(snapshot.summary.longest_wait)"
                    :tone="waitingTone"
                />
                <StatCard
                    :label="t('En communication')"
                    :value="snapshot.summary.in_call"
                    tone="warn"
                />
                <StatCard
                    :label="t('Agents libres')"
                    :value="snapshot.summary.free"
                    tone="good"
                />
            </div>
            <p v-if="!snapshot.live" class="mt-2 text-xs text-muted-foreground">
                {{
                    t(
                        'Les données Asterisk en direct sont indisponibles ; les appels en attente et en cours peuvent être incomplets.',
                    )
                }}
            </p>
        </div>

        <!-- Aujourd'hui -->
        <div>
            <h2
                class="mb-2 text-sm font-medium tracking-wide text-muted-foreground uppercase"
            >
                Aujourd'hui
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Appels" :value="today.total" />
                <StatCard
                    :label="t('Répondus')"
                    :value="today.answered"
                    tone="good"
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
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>{{ t("Agents aujourd'hui") }}</CardTitle>
                    <CardDescription>{{
                        t('Qui est connecté et ce que chaque agent a traité.')
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <AgentPerformanceTable :agents="roster" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Appels à suivre') }}</CardTitle>
                    <CardDescription>{{
                        t("Personne n'a répondu à ceux-ci aujourd'hui.")
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y rounded-md border">
                        <li
                            v-for="call in missedList"
                            :key="call.id"
                            class="flex items-center gap-3 p-3"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ call.name }}
                                </div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ call.number }} ·
                                    {{ formatTime(call.started_at) }}
                                </div>
                            </div>
                            <Button
                                size="icon"
                                variant="outline"
                                title="Rappeler"
                                @click="callBack(call.number)"
                            >
                                <PhoneCall class="h-4 w-4" />
                            </Button>
                        </li>
                        <li
                            v-if="missedList.length === 0"
                            class="p-6 text-center text-sm text-muted-foreground"
                        >
                            {{ t("Aucun appel manqué aujourd'hui.") }}
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
