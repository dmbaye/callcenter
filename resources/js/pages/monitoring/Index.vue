<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, usePage } from '@inertiajs/vue3';
import {
    Headphones,
    Mic,
    Phone,
    PhoneIncoming,
    RefreshCw,
    UserRound,
} from '@lucide/vue';
import axios from 'axios';
import { computed, ref } from 'vue';

import StatCard from '@/components/StatCard.vue';
import StatusLight from '@/components/StatusLight.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLiveMonitoring } from '@/composables/useLiveMonitoring';
import { listen, whisper } from '@/routes/monitoring';
import { formatDuration } from '@/lib/format';
import type {
    MonitoringSummary,
    QueueSummary,
    ServiceLevelSummary,
    SupervisorAgent,
    WaitingCall,
} from '@/types';

const props = defineProps<{
    agents: SupervisorAgent[];
    waiting: WaitingCall[];
    queues: QueueSummary[];
    summary: MonitoringSummary;
    serviceLevel?: ServiceLevelSummary | null;
    live: boolean;
    amiAvailable: boolean;
}>();

/** Green at or above the team target, amber within 10 points, red below. */
function serviceLevelTone(
    percent: number | null,
): 'default' | 'good' | 'warn' | 'bad' {
    const target = snapshot.value.serviceLevel?.target ?? 80;

    if (percent === null) {
        return 'default';
    }

    if (percent >= target) {
        return 'good';
    }

    return percent >= target - 10 ? 'warn' : 'bad';
}

const page = usePage();
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const {
    snapshot,
    loading,
    error: liveError,
    load,
    connected,
} = useLiveMonitoring(props);

const inCall = computed(() =>
    snapshot.value.agents.filter((a) => a.status === 'busy'),
);
const free = computed(() =>
    snapshot.value.agents.filter((a) => a.status === 'available'),
);
const unavailable = computed(() =>
    snapshot.value.agents.filter((a) =>
        ['ringing', 'paused', 'offline'].includes(a.status),
    ),
);

const waitingTone = computed(() => {
    const { waiting, longest_wait } = snapshot.value.summary;

    if (waiting === 0) return 'default';

    return longest_wait >= 60 ? 'bad' : 'warn';
});

const statusLabel = (status: SupervisorAgent['status']) =>
    ({
        available: 'Libre',
        busy: 'En communication',
        ringing: 'Sonnerie',
        paused: 'En pause',
        offline: 'Déconnecté',
    })[status];

/** Paused agents show the state they chose (Non prêt – Déjeuner, Post-appel…). */
const agentLabel = (agent: SupervisorAgent) => {
    if (agent.status === 'paused') {
        return agent.stateLabel || statusLabel(agent.status);
    }

    if (agent.status === 'offline' && agent.state === 'logged_out') {
        return 'Déconnecté des files';
    }

    return statusLabel(agent.status);
};

// Refreshed with each snapshot (live updates or polling), precise enough for "since".
const timeInState = (agent: SupervisorAgent) => {
    if (!agent.stateSince || agent.status === 'offline') {
        return null;
    }

    return formatDuration(
        Math.max(
            0,
            Math.floor(
                (Date.now() - new Date(agent.stateSince).getTime()) / 1000,
            ),
        ),
    );
};

const statusVariant = (status: SupervisorAgent['status']) =>
    (
        ({
            available: 'positive',
            busy: 'informative',
            ringing: 'notice',
            paused: 'notice',
            offline: 'neutral',
        }) as const
    )[status];

const actionAgent = ref<number | null>(null);
const actionError = ref('');
const notice = ref('');

const error = computed(() => actionError.value || liveError.value);

const runMonitor = async (
    agent: SupervisorAgent,
    mode: 'listen' | 'whisper',
) => {
    actionAgent.value = agent.id;
    actionError.value = '';
    notice.value = '';

    try {
        const response = await axios.post<{ message: string }>(
            (mode === 'listen' ? listen : whisper).url(teamSlug.value),
            { agent: agent.id },
            { headers: { Accept: 'application/json' } },
        );
        notice.value = response.data.message;
    } catch (e: any) {
        actionError.value =
            e?.response?.data?.message ??
            'Impossible de démarrer la surveillance.';
    } finally {
        actionAgent.value = null;
    }
};
</script>

<template>
    <Head title="Supervision" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <div
            class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ t('Surveillance en temps réel') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Appels en attente, agents en communication et agents disponibles. :p0',
                            {
                                p0: connected
                                    ? t('Mise à jour en temps réel.')
                                    : t('Actualisé toutes les 3 secondes.'),
                            },
                        )
                    }}
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                :disabled="loading"
                @click="load"
            >
                <RefreshCw :class="['h-4 w-4', loading && 'animate-spin']" />
                {{ t('Rafraîchir') }}
            </Button>
        </div>

        <div
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            :class="snapshot.serviceLevel ? 'xl:grid-cols-8' : 'xl:grid-cols-7'"
        >
            <StatCard
                v-if="snapshot.serviceLevel"
                :label="t('Niveau de service')"
                :value="
                    snapshot.serviceLevel.percent === null
                        ? '—'
                        : `${snapshot.serviceLevel.percent} %`
                "
                :tone="serviceLevelTone(snapshot.serviceLevel.percent)"
                :title="
                    t(
                        'Objectif : :target % des appels décrochés dans le délai de chaque file',
                        {
                            target: snapshot.serviceLevel.target,
                        },
                    )
                "
            />
            <StatCard
                :label="t('File d\'attente')"
                :value="snapshot.summary.waiting"
                :tone="waitingTone"
            />
            <StatCard
                :label="t('Plus longue attente')"
                :value="formatDuration(snapshot.summary.longest_wait)"
                :tone="waitingTone"
            />
            <StatCard
                :label="t('En communication')"
                :value="snapshot.summary.in_call"
                :tone="snapshot.summary.in_call > 0 ? 'info' : 'default'"
            />
            <StatCard
                :label="t('Agents libres')"
                :value="snapshot.summary.free"
                :tone="snapshot.summary.free > 0 ? 'good' : 'default'"
            />
            <StatCard label="Sonnerie" :value="snapshot.summary.ringing" />
            <StatCard :label="t('En pause')" :value="snapshot.summary.paused" />
            <StatCard
                :label="t('Désconnecté')"
                :value="snapshot.summary.offline"
            />
        </div>

        <div
            v-if="!snapshot.live"
            class="rounded-md border border-notice/30 bg-notice/5 p-3 text-sm"
        >
            {{
                t(
                    "Les données Asterisk en temps réel ne sont pas disponibles (AMI non configuré ou inaccessible). La présence dans la file d'attente reste affichée, mais les appels en attente, la détection des appels en cours ainsi que les fonctions d'écoute et de chuchotement nécessitent l'AMI.",
                )
            }}
        </div>
        <div
            v-if="notice"
            class="rounded-md border border-primary/30 bg-primary/5 p-3 text-sm"
        >
            {{ notice }}
        </div>
        <div
            v-if="error"
            class="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
        >
            {{ error }}
        </div>

        <!-- Appels in queue -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <PhoneIncoming class="h-5 w-5" />
                    {{ t('Appels en attente') }}
                </CardTitle>
                <CardDescription>
                    {{
                        t(
                            "Appelants en attente d'un agent à l'heure actuelle, par file d'attente.",
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-20">{{
                                    t('Position')
                                }}</TableHead>
                                <TableHead>{{ t('Numéro') }}</TableHead>
                                <TableHead>{{ t("File d'attente") }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('En attente')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="call in snapshot.waiting"
                                :key="`${call.queue}-${call.position}-${call.caller}`"
                            >
                                <TableCell class="font-mono"
                                    >#{{ call.position }}</TableCell
                                >
                                <TableCell>
                                    <div class="font-medium">
                                        {{ call.caller || 'Inconnu' }}
                                    </div>
                                    <div
                                        v-if="
                                            call.name &&
                                            call.name !== call.caller
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ call.name }}
                                    </div>
                                </TableCell>
                                <TableCell>{{ call.queue }}</TableCell>
                                <TableCell
                                    class="text-right font-mono tabular-nums"
                                    :class="
                                        call.wait >= 60 &&
                                        'font-semibold text-negative'
                                    "
                                >
                                    {{ formatDuration(call.wait) }}
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="snapshot.waiting.length === 0">
                                <TableCell
                                    colspan="4"
                                    class="h-20 text-center text-muted-foreground"
                                >
                                    {{ t("Aucun appelant n'est en attente.") }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <div
                    v-if="snapshot.queues.length > 0"
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="queue in snapshot.queues"
                        :key="queue.name"
                        class="rounded-md border p-3 text-sm"
                    >
                        <div class="mb-2 font-medium">{{ queue.name }}</div>
                        <dl
                            class="grid grid-cols-2 gap-x-4 gap-y-1 text-muted-foreground"
                        >
                            <dt>{{ t('En attente') }}</dt>
                            <dd class="text-right text-foreground tabular-nums">
                                {{ queue.waiting }}
                            </dd>
                            <dt>{{ t('Agents libres') }}</dt>
                            <dd class="text-right text-foreground tabular-nums">
                                {{ queue.free }}
                            </dd>
                            <dt>{{ t('En communication') }}</dt>
                            <dd class="text-right text-foreground tabular-nums">
                                {{ queue.in_call }}
                            </dd>
                            <dt>{{ t('Traités') }}</dt>
                            <dd class="text-right text-foreground tabular-nums">
                                {{ queue.completed }}
                            </dd>
                            <dt>{{ t('Abandons') }}</dt>
                            <dd class="text-right text-foreground tabular-nums">
                                {{ queue.abandoned }}
                            </dd>
                            <template v-if="queue.service_level">
                                <dt
                                    :title="
                                        t('Appels décrochés en moins de :s s', {
                                            s: queue.service_level.threshold,
                                        })
                                    "
                                >
                                    {{
                                        t('Niveau de service (:s s)', {
                                            s: queue.service_level.threshold,
                                        })
                                    }}
                                </dt>
                                <dd
                                    class="text-right tabular-nums"
                                    :class="
                                        queue.completed === 0
                                            ? 'text-foreground'
                                            : queue.service_level.percent >=
                                                (snapshot.serviceLevel
                                                    ?.target ?? 80)
                                              ? 'font-medium text-positive-strong'
                                              : 'font-medium text-negative-strong'
                                    "
                                >
                                    {{
                                        queue.completed === 0
                                            ? '—'
                                            : `${queue.service_level.percent} %`
                                    }}
                                </dd>
                            </template>
                        </dl>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Agents in communication -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Phone class="h-5 w-5" />
                    {{ t('Agents en communication') }}
                    <Badge>{{ inCall.length }}</Badge>
                </CardTitle>
                <CardDescription>
                    {{
                        t(
                            "Écoutez en silence ou chuchotez pour guider l'agent sans que l'interlocuteur ne vous entende.",
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Agent') }}</TableHead>
                                <TableHead>{{ t('Appelant') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Durée')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Actions')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="agent in inCall" :key="agent.id">
                                <TableCell>
                                    <div class="font-medium">
                                        {{ agent.name }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ agent.extension }} ·
                                        {{ agent.queue }}
                                    </div>
                                </TableCell>
                                <TableCell>{{
                                    agent.call?.caller || t('Appel en cours')
                                }}</TableCell>
                                <TableCell
                                    class="text-right font-mono tabular-nums"
                                >
                                    {{ formatDuration(agent.call?.duration) }}
                                </TableCell>
                                <TableCell>
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :disabled="
                                                !snapshot.live ||
                                                actionAgent === agent.id
                                            "
                                            @click="runMonitor(agent, 'listen')"
                                        >
                                            <Headphones class="h-4 w-4" />
                                            {{ t('Écouter') }}
                                        </Button>
                                        <Button
                                            size="sm"
                                            :disabled="
                                                !snapshot.live ||
                                                actionAgent === agent.id
                                            "
                                            @click="
                                                runMonitor(agent, 'whisper')
                                            "
                                        >
                                            <Mic class="h-4 w-4" />
                                            {{ t('Chuchoter') }}
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="inCall.length === 0">
                                <TableCell
                                    colspan="4"
                                    class="h-20 text-center text-muted-foreground"
                                >
                                    {{ t("Personne n'est en communication.") }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Agents libres -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        {{ t('Agents libres') }}
                        <Badge class="bg-positive text-white hover:bg-positive">
                            {{ free.length }}
                        </Badge>
                    </CardTitle>
                    <CardDescription>
                        {{
                            t(
                                "Connecté à la file d'attente et prêt à prendre un appel.",
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y rounded-md border">
                        <li
                            v-for="agent in free"
                            :key="agent.id"
                            class="flex items-center gap-3 p-3"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-muted"
                            >
                                <UserRound class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">
                                    {{ agent.name }}
                                </div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ agent.extension }} · {{ agent.queue }}
                                </div>
                            </div>
                            <span
                                class="h-2.5 w-2.5 rounded-full bg-positive"
                            />
                        </li>
                        <li
                            v-if="free.length === 0"
                            class="p-6 text-center text-sm text-muted-foreground"
                        >
                            {{ t('Aucun agent libre.') }}
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <!-- Not available -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        {{ t('Pas disponible') }}
                        <Badge variant="secondary">{{
                            unavailable.length
                        }}</Badge>
                    </CardTitle>
                    <CardDescription>
                        {{
                            t(
                                "En attente de réponse, en pause ou déconnecté de la file d'attente.",
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y rounded-md border">
                        <li
                            v-for="agent in unavailable"
                            :key="agent.id"
                            class="flex items-center gap-3 p-3"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-muted"
                            >
                                <UserRound class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">
                                    {{ agent.name }}
                                </div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ agent.extension }} · {{ agent.queue }}
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-0.5">
                                <StatusLight
                                    :variant="statusVariant(agent.status)"
                                    >{{ agentLabel(agent) }}</StatusLight
                                >
                                <span
                                    v-if="timeInState(agent)"
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    {{
                                        t('depuis :p0', {
                                            p0: timeInState(agent),
                                        })
                                    }}
                                </span>
                            </div>
                        </li>
                        <li
                            v-if="unavailable.length === 0"
                            class="p-6 text-center text-sm text-muted-foreground"
                        >
                            {{
                                t(
                                    'Tout le monde est soit libre, soit en communication.',
                                )
                            }}
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <!-- Agent call details (today, from Asterisk CDR) -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t("Détails de l'appel de l'agent") }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'Performances des appels du jour issues du CDR Asterisk. Les appels sont dédoublonnés par ID lié.',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Agent') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t("Nombre total d'appels")
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Répondue')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Manquée')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('DMR')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('DMC')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('DMT')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="agent in snapshot.agentCallDetails"
                                :key="agent.id"
                            >
                                <TableCell>
                                    <div class="font-medium">
                                        {{ agent.name }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ agent.extension }}
                                    </div>
                                </TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    agent.total_calls
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    agent.answered
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    agent.missed
                                }}</TableCell>
                                <TableCell
                                    class="text-right font-mono tabular-nums"
                                >
                                    {{
                                        formatDuration(agent.avg_answer_seconds)
                                    }}
                                </TableCell>
                                <TableCell
                                    class="text-right font-mono tabular-nums"
                                >
                                    {{
                                        formatDuration(
                                            agent.avg_duration_seconds,
                                        )
                                    }}
                                </TableCell>
                                <TableCell
                                    class="text-right font-mono tabular-nums"
                                >
                                    {{
                                        formatDuration(
                                            agent.total_duration_seconds,
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                            <TableRow
                                v-if="snapshot.agentCallDetails.length === 0"
                            >
                                <TableCell
                                    colspan="7"
                                    class="h-20 text-center text-muted-foreground"
                                >
                                    {{
                                        t(
                                            "Aucun enregistrement d'appel d'agent trouvé pour aujourd'hui.",
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
