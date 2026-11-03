import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

import { requestHeaders } from '@/lib/teamHeader';
import { update as agentStateRoute } from '@/routes/agent-state';
import {
    destroy as resumeRoute,
    status as statusRoute,
    store as pauseRoute,
} from '@/routes/pauses';
import { join as joinRoute, leave as leaveRoute } from '@/routes/presence';
import type { AgentStateValue, PauseReason, QueueStatus } from '@/types';

export type StatePayload = {
    status?: QueueStatus;
    state?: AgentStateValue;
    stateLabel?: string;
    reason?: string | null;
    reasonId?: number | null;
    since?: string | null;
    reasons?: PauseReason[];
    heartbeat?: number;
};

// Server-side queue membership state (Asterisk queue_members).
const queueStatus = ref<QueueStatus>('offline');
const loading = ref(false);
const initialized = ref(false);

// What the agent chose in the status menu (kept by the server across tabs and reloads).
const agentState = ref<AgentStateValue>('ready');
const stateLabel = ref('Prêt');
const pauseReason = ref<string | null>(null);
const pauseReasonId = ref<number | null>(null);
const stateSince = ref<string | null>(null);
const pauseReasons = ref<PauseReason[]>([]);

const applyPayload = (data: StatePayload) => {
    queueStatus.value = data.status ?? 'offline';
    initialized.value = true;

    if (data.state) {
        agentState.value = data.state;
        stateLabel.value = data.stateLabel ?? stateLabel.value;
        pauseReason.value = data.reason ?? null;
        pauseReasonId.value = data.reasonId ?? null;
        stateSince.value = data.since ?? null;
    }

    if (data.reasons) {
        pauseReasons.value = data.reasons;
    }
};

/** Apply a state the server returned from another request (e.g. joining a meeting). */
const applyState = (data: StatePayload) => applyPayload(data);

const isPaused = computed(() => queueStatus.value === 'paused');
const isAvailable = computed(() => queueStatus.value === 'available');

// Team whose queues the softphone joined; leave() targets it even after a team switch.
let joinedTeam: string | null = null;
let heartbeat: ReturnType<typeof setInterval> | null = null;

const currentSlug = (): string => usePage().props.currentTeam?.slug ?? '';

// Requests run one after the other, so a quick "Post-appel" then "Prêt"
// (call ends, outcome saved) reach the server in order and none is dropped.
let queue: Promise<unknown> = Promise.resolve();

const request = (
    method: 'get' | 'post' | 'put' | 'delete',
    url: string,
    data?: Record<string, unknown>,
): Promise<StatePayload> => {
    const run = async () => {
        loading.value = true;

        try {
            const response = await axios.request<StatePayload>({
                method,
                url,
                data,
                headers: { Accept: 'application/json' },
            });

            applyPayload(response.data);

            return response.data;
        } finally {
            loading.value = false;
        }
    };

    const next = queue.then(run, run);
    queue = next.catch(() => undefined);

    return next;
};

const loadStatus = () =>
    currentSlug()
        ? request('get', statusRoute.url(currentSlug()))
        : Promise.resolve(undefined);
const pauseAgent = () => request('post', pauseRoute.url(currentSlug()));
const resumeAgent = () => request('delete', resumeRoute.url(currentSlug()));
const togglePause = () => (isPaused.value ? resumeAgent() : pauseAgent());

/** Choose a state in the status menu (a reason only applies to not_ready). */
const setAgentState = (state: AgentStateValue, reasonId?: number | null) =>
    request('put', agentStateRoute.url(currentSlug()), {
        state,
        reason_id: state === 'not_ready' ? (reasonId ?? null) : null,
    });

/** Post-call work is done (outcome saved or skipped): back to Prêt, unless the agent already chose another state. */
const finishWrapUp = () => {
    if (agentState.value !== 'wrap_up') {
        return Promise.resolve(undefined);
    }

    return setAgentState('ready').catch((error) => {
        console.warn('[Statut] Retour à Prêt impossible :', error);

        return undefined;
    });
};

const stopHeartbeat = () => {
    if (heartbeat) {
        clearInterval(heartbeat);
        heartbeat = null;
    }
};

/** Softphone registered: join the team queues (an existing pause is kept) and keep the heartbeat going. */
const joinQueues = async (slug: string, fallbackIntervalSeconds = 60) => {
    stopHeartbeat();
    joinedTeam = slug;

    const ping = async () => {
        const response = await axios.post<StatePayload>(
            joinRoute.url(slug),
            null,
            { headers: { Accept: 'application/json' } },
        );
        applyPayload(response.data);

        return response.data.heartbeat;
    };

    try {
        const interval = (await ping()) || fallbackIntervalSeconds;
        heartbeat = setInterval(
            () => void ping().catch(() => undefined),
            interval * 1000,
        );
    } catch (error) {
        console.warn('[Queue] Could not join the team queues:', error);
    }
};

/**
 * Softphone unregistered / page closing: leave the queues. keepalive lets
 * the request finish while the tab is unloading.
 */
const leaveQueues = () => {
    stopHeartbeat();

    if (!joinedTeam) {
        return;
    }

    const url = leaveRoute.url(joinedTeam);
    joinedTeam = null;
    queueStatus.value = 'offline';

    void fetch(url, {
        method: 'POST',
        headers: requestHeaders(),
        keepalive: true,
        credentials: 'same-origin',
    }).catch(() => undefined);
};

export function useAgentQueue() {
    return {
        queueStatus,
        isPaused,
        isAvailable,
        loading,
        initialized,

        agentState,
        stateLabel,
        pauseReason,
        pauseReasonId,
        stateSince,
        pauseReasons,

        loadStatus,
        applyState,
        setAgentState,
        finishWrapUp,
        pauseAgent,
        resumeAgent,
        togglePause,
        joinQueues,
        leaveQueues,
    };
}
