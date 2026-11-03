import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

import { getEcho } from '@/lib/echo';
import { status as monitoringStatus } from '@/routes/monitoring';
import type { MonitoringSnapshot } from '@/types';

/** Never fetch more often than this, however fast pings arrive. */
const MIN_GAP_MS = 1000;
/** Safety net while the socket is connected, in case a ping is ever missed. */
const SAFETY_REFRESH_MS = 30000;

/**
 * Live monitoring: agents, callers waiting in queue and per-queue counters.
 * Used by the monitoring page and the supervisor dashboard.
 *
 * Updates are pushed: the server sends a tiny "monitoring.changed" ping over
 * Reverb whenever Asterisk's state changes, and we refetch the authorized
 * snapshot. If the socket is not configured or drops, it falls back to
 * polling every `fallbackIntervalMs` until it reconnects.
 *
 * Call durations and waiting times are extended locally every second, so they
 * keep ticking between pushes.
 */
export function useLiveMonitoring(
    initial?: Partial<MonitoringSnapshot>,
    fallbackIntervalMs = 3000,
) {
    const page = usePage();
    const slug = computed(() => page.props.currentTeam?.slug ?? '');
    const teamId = computed(() => page.props.currentTeam?.id ?? null);

    const empty: MonitoringSnapshot = {
        live: false,
        agents: [],
        agentCallDetails: [],
        waiting: [],
        queues: [],
        summary: {
            agents: 0,
            waiting: 0,
            longest_wait: 0,
            free: 0,
            in_call: 0,
            ringing: 0,
            paused: 0,
            offline: 0,
        },
    };

    const raw = ref<MonitoringSnapshot>({ ...empty, ...initial });
    const receivedAt = ref(Date.now());
    const now = ref(Date.now());
    const loading = ref(false);
    const error = ref('');
    const connected = ref(false);

    // Same shape the pages already read, with elapsed seconds added to the
    // time counters since the snapshot arrived.
    const snapshot = computed<MonitoringSnapshot>(() => {
        const elapsed = Math.max(
            0,
            Math.floor((now.value - receivedAt.value) / 1000),
        );
        const s = raw.value;

        if (elapsed === 0) {
            return s;
        }

        return {
            ...s,
            agents: s.agents.map((a) =>
                a.call && a.call.duration !== null
                    ? {
                          ...a,
                          call: {
                              ...a.call,
                              duration: a.call.duration + elapsed,
                          },
                      }
                    : a,
            ),
            waiting: s.waiting.map((w) => ({ ...w, wait: w.wait + elapsed })),
            queues: s.queues.map((q) =>
                q.waiting > 0
                    ? { ...q, longest_wait: q.longest_wait + elapsed }
                    : q,
            ),
            summary: {
                ...s.summary,
                longest_wait:
                    s.summary.waiting > 0
                        ? s.summary.longest_wait + elapsed
                        : s.summary.longest_wait,
            },
        };
    });

    let lastLoad = 0;
    let pending: ReturnType<typeof setTimeout> | null = null;
    let tick: ReturnType<typeof setInterval> | null = null;
    let poll: ReturnType<typeof setInterval> | null = null;
    let safety: ReturnType<typeof setInterval> | null = null;
    let unsubscribe: (() => void) | null = null;

    const setSnapshot = (data: MonitoringSnapshot) => {
        raw.value = data;
        receivedAt.value = Date.now();
        now.value = receivedAt.value;
    };

    const load = async () => {
        if (loading.value || !slug.value) {
            return;
        }

        loading.value = true;
        error.value = '';
        lastLoad = Date.now();

        try {
            const response = await axios.get<MonitoringSnapshot>(
                monitoringStatus.url(slug.value),
                {
                    headers: { Accept: 'application/json' },
                },
            );
            setSnapshot(response.data);
        } catch (e: any) {
            // 503 still carries the queue-only snapshot (Asterisk AMI unreachable).
            if (e?.response?.status === 503 && e.response.data?.agents) {
                setSnapshot(e.response.data);
                error.value =
                    'Les données Asterisk en direct sont indisponibles. Seule la présence dans les files est affichée.';
            } else {
                error.value =
                    e?.response?.data?.message ??
                    "Impossible d'actualiser le statut en direct.";
            }
        } finally {
            loading.value = false;
        }
    };

    /** Coalesce bursts of pings into at most one fetch per MIN_GAP_MS. */
    const requestLoad = () => {
        if (pending) {
            return;
        }

        pending = setTimeout(
            () => {
                pending = null;
                void load();
            },
            Math.max(0, MIN_GAP_MS - (Date.now() - lastLoad)),
        );
    };

    const connect = () => {
        const echo = getEcho();

        if (!echo || teamId.value === null) {
            return;
        }

        const channelName = `team.${teamId.value}.monitoring`;
        echo.private(channelName).listen('.monitoring.changed', requestLoad);

        const connection = (echo.connector as any)?.pusher?.connection;
        const onState = ({ current }: { current: string }) => {
            const up = current === 'connected';

            if (up && !connected.value) {
                requestLoad(); // catch up on anything missed while offline
            }

            connected.value = up;
        };

        connected.value = connection?.state === 'connected';
        connection?.bind('state_change', onState);

        unsubscribe = () => {
            connection?.unbind('state_change', onState);
            echo.leave(channelName);
        };
    };

    onMounted(() => {
        tick = setInterval(() => (now.value = Date.now()), 1000);

        // Pages that did not get server-rendered data need a first load.
        if (!initial) {
            void load();
        }

        connect();

        // Polling only runs while the socket is down (or not configured).
        poll = setInterval(() => {
            if (!connected.value) {
                void load();
            }
        }, fallbackIntervalMs);

        safety = setInterval(() => {
            if (connected.value) {
                void load();
            }
        }, SAFETY_REFRESH_MS);
    });

    onUnmounted(() => {
        [tick, poll, safety].forEach((timer) => timer && clearInterval(timer));

        if (pending) {
            clearTimeout(pending);
        }

        unsubscribe?.();
    });

    return { snapshot, loading, error, load, connected };
}
