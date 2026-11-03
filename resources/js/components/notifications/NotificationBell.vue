<script setup lang="ts">
import {
    ArrowLeftRight,
    Bell,
    BellRing,
    CalendarClock,
    CalendarDays,
    CalendarPlus,
    CalendarX2,
    CalendarOff,
    CheckCheck,
    CreditCard,
    Receipt,
    TimerReset,
    TriangleAlert,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useNotifications } from '@/composables/useNotifications';
import type { AppNotification } from '@/composables/useNotifications';
import { formatRelative } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The bell in the header: meeting invitations, reminders, changes and
 * cancellations. Live pushes also pop a toast with a "Rejoindre" button.
 */
const { items, unread, loaded, loading, load, markAllRead, open, listen } =
    useNotifications();

listen();

const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));

const icons: Record<string, typeof Bell> = {
    'meeting.invitation': Video,
    'meeting.started': Video,
    'meeting.reminder': BellRing,
    'meeting.scheduled': CalendarPlus,
    'meeting.updated': CalendarClock,
    'meeting.cancelled': CalendarX2,
    'billing.ending': CreditCard,
    'billing.expired': CreditCard,
    'billing.payment': Receipt,
    'workforce.roster.assigned': CalendarPlus,
    'workforce.roster.removed': CalendarX2,
    'workforce.roster.week': CalendarDays,
    'workforce.swap.requested': ArrowLeftRight,
    'workforce.swap.pending': ArrowLeftRight,
    'workforce.swap.approved': ArrowLeftRight,
    'workforce.swap.declined': ArrowLeftRight,
    'workforce.swap.rejected': ArrowLeftRight,
    'workforce.leave.requested': CalendarOff,
    'workforce.leave.approved': CalendarOff,
    'workforce.leave.rejected': CalendarOff,
    'workforce.leave.cancelled': CalendarOff,
    'workforce.coverage.alert': TriangleAlert,
    'workforce.timesheet.adjustment': TimerReset,
    'workforce.timesheet.approved': TimerReset,
    'workforce.timesheet.rejected': TimerReset,
};

const tones: Record<string, string> = {
    'meeting.invitation': 'bg-positive-subtle text-positive-strong',
    'meeting.started': 'bg-positive-subtle text-positive-strong',
    'meeting.reminder': 'bg-notice-subtle text-notice-strong',
    'meeting.cancelled': 'bg-negative-subtle text-negative-strong',
    'billing.ending': 'bg-notice-subtle text-notice-strong',
    'billing.expired': 'bg-negative-subtle text-negative-strong',
    'billing.payment': 'bg-positive-subtle text-positive-strong',
    'workforce.swap.approved': 'bg-positive-subtle text-positive-strong',
    'workforce.leave.approved': 'bg-positive-subtle text-positive-strong',
    'workforce.timesheet.approved': 'bg-positive-subtle text-positive-strong',
    'workforce.swap.requested': 'bg-notice-subtle text-notice-strong',
    'workforce.swap.pending': 'bg-notice-subtle text-notice-strong',
    'workforce.leave.requested': 'bg-notice-subtle text-notice-strong',
    'workforce.timesheet.adjustment': 'bg-notice-subtle text-notice-strong',
    'workforce.swap.declined': 'bg-negative-subtle text-negative-strong',
    'workforce.swap.rejected': 'bg-negative-subtle text-negative-strong',
    'workforce.leave.rejected': 'bg-negative-subtle text-negative-strong',
    'workforce.timesheet.rejected': 'bg-negative-subtle text-negative-strong',
    'workforce.roster.removed': 'bg-negative-subtle text-negative-strong',
    'workforce.coverage.alert': 'bg-negative-subtle text-negative-strong',
};

function iconFor(item: AppNotification) {
    return icons[item.kind] ?? Bell;
}

function onOpenChange(isOpen: boolean) {
    if (isOpen) {
        void load();
    }
}
</script>

<template>
    <DropdownMenu @update:open="onOpenChange">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="
                    unread > 0
                        ? t('Notifications : :count non lue(s)', {
                              count: unread,
                          })
                        : t('Notifications')
                "
                data-tour="notifications"
            >
                <Bell class="size-5" />
                <span
                    v-if="unread > 0"
                    class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-negative px-1 text-[10px] leading-none font-bold text-white tabular-nums"
                    aria-hidden="true"
                    >{{ badge }}</span
                >
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            align="end"
            :side-offset="8"
            class="w-[min(24rem,calc(100vw-2rem))] p-0"
        >
            <div class="flex items-center justify-between border-b px-4 py-3">
                <p class="text-sm font-bold text-heading">
                    {{ t('Notifications') }}
                </p>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-xs font-medium text-primary hover:bg-accent"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-3.5" />
                    {{ t('Tout marquer comme lu') }}
                </button>
            </div>

            <div class="max-h-[26rem] overflow-y-auto">
                <p
                    v-if="loading && !loaded"
                    class="px-4 py-8 text-center text-sm text-muted-foreground"
                >
                    {{ t('Chargement…') }}
                </p>

                <div
                    v-else-if="items.length === 0"
                    class="flex flex-col items-center gap-2 px-6 py-10 text-center"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <Bell class="size-5" />
                    </span>
                    <p class="text-sm font-semibold text-heading">
                        {{ t('Aucune notification') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Les invitations et rappels de réunion apparaîtront ici.',
                            )
                        }}
                    </p>
                </div>

                <ul v-else class="divide-y">
                    <li v-for="item in items" :key="item.id">
                        <button
                            type="button"
                            class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/60"
                            :class="{
                                'bg-informative-subtle/40': !item.readAt,
                            }"
                            @click="open(item)"
                        >
                            <span
                                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    tones[item.kind] ??
                                    'bg-informative-subtle text-informative-strong'
                                "
                            >
                                <component :is="iconFor(item)" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block text-sm leading-snug text-heading"
                                    :class="
                                        item.readAt
                                            ? 'font-medium'
                                            : 'font-bold'
                                    "
                                    >{{ item.title }}</span
                                >
                                <span
                                    class="mt-0.5 block truncate text-xs text-muted-foreground"
                                    >{{ item.body }}</span
                                >
                                <span
                                    class="mt-1 block text-[11px] text-muted-foreground"
                                    >{{ formatRelative(item.createdAt) }}</span
                                >
                            </span>
                            <span
                                v-if="!item.readAt"
                                class="mt-2 size-2 shrink-0 rounded-full bg-primary"
                                :aria-label="t('Non lue')"
                            />
                        </button>
                    </li>
                </ul>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
