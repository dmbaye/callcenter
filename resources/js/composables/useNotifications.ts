import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { getEcho } from '@/lib/echo';
import {
    index as notificationsRoute,
    read as readRoute,
    readAll as readAllRoute,
} from '@/routes/notifications';

export type AppNotification = {
    id: string;
    kind: string;
    title: string;
    body: string;
    url: string | null;
    action: string | null;
    readAt: string | null;
    createdAt: string;
    meeting?: {
        uuid: string;
        title: string;
        scheduledAt: string | null;
        team: string;
    };
};

const items = ref<AppNotification[]>([]);
const unread = ref(0);
const loaded = ref(false);
const loading = ref(false);
let channel: string | null = null;

/** Kinds that deserve a toast with a join button when they arrive live. */
const urgent = new Set([
    'meeting.invitation',
    'meeting.started',
    'meeting.reminder',
]);

async function load() {
    loading.value = true;

    try {
        const { data } = await axios.get<{
            unread: number;
            items: AppNotification[];
        }>(notificationsRoute.url());
        items.value = data.items;
        unread.value = data.unread;
        loaded.value = true;
    } finally {
        loading.value = false;
    }
}

async function markRead(notification: AppNotification) {
    if (notification.readAt) {
        return;
    }

    notification.readAt = new Date().toISOString();
    unread.value = Math.max(0, unread.value - 1);

    try {
        const { data } = await axios.post<{ unread: number }>(
            readRoute.url(notification.id),
        );
        unread.value = data.unread;
    } catch {
        // The badge catches up on the next load.
    }
}

async function markAllRead() {
    items.value.forEach((item) => (item.readAt ??= new Date().toISOString()));
    unread.value = 0;

    await axios.post(readAllRoute.url()).catch(() => undefined);
}

function open(notification: AppNotification) {
    void markRead(notification);

    if (notification.url) {
        router.visit(notification.url);
    }
}

/** A notification pushed live on users.{id} (Laravel broadcast channel). */
function receive(
    payload: Omit<AppNotification, 'readAt' | 'createdAt'> & { type?: string },
) {
    const notification: AppNotification = {
        ...payload,
        url: payload.url ?? null,
        action: payload.action ?? null,
        readAt: null,
        createdAt: new Date().toISOString(),
    };

    items.value = [
        notification,
        ...items.value.filter((item) => item.id !== notification.id),
    ].slice(0, 30);
    unread.value++;

    const show = urgent.has(notification.kind) ? toast.info : toast;

    show(notification.title, {
        description: notification.body,
        duration: urgent.has(notification.kind) ? 60_000 : 8_000,
        action:
            notification.url && notification.action
                ? {
                      label: notification.action,
                      onClick: () => open(notification),
                  }
                : undefined,
    });
}

/**
 * The header bell: unread count (shared Inertia prop on every page),
 * the latest notifications (loaded when opened) and live pushes.
 * Call listen() once from a component that stays mounted.
 */
export function useNotifications() {
    const page = usePage();

    function listen() {
        watch(
            () => page.props.unreadNotifications,
            (count) => {
                if (typeof count === 'number') {
                    unread.value = count;
                }
            },
            { immediate: true },
        );

        watch(
            () => page.props.auth?.user?.id,
            (userId) => {
                const echo = getEcho();

                if (channel) {
                    echo?.private(channel).stopListeningForNotification(
                        receive,
                    );
                    channel = null;
                }

                if (echo && userId) {
                    channel = `users.${userId}`;
                    echo.private(channel).notification(receive);
                }
            },
            { immediate: true },
        );
    }

    return {
        items,
        unread,
        loaded,
        loading,
        load,
        markRead,
        markAllRead,
        open,
        listen,
    };
}
