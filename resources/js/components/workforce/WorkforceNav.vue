<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarCheck2,
    CalendarDays,
    CalendarOff,
    Inbox,
    Settings2,
    TimerReset,
} from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { planningUrl } from '@/lib/workforce';
import type { WorkforceShell } from '@/types';

/** Tabs shared by every planning page, with the badges of what waits. */
const props = defineProps<{
    workforce: WorkforceShell;
    current: 'me' | 'roster' | 'requests' | 'timesheets' | 'leave' | 'settings';
}>();

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');

const pendingTotal = computed(() => {
    const p = props.workforce.pending;

    return p ? p.swaps + p.leave + p.adjustments : 0;
});

const tabs = computed(() =>
    [
        {
            key: 'me',
            label: t('Mon planning'),
            icon: CalendarCheck2,
            badge: props.workforce.incomingSwaps,
            show: true,
        },
        {
            key: 'roster',
            label: t('Roster'),
            icon: CalendarDays,
            badge: 0,
            show: props.workforce.isSupervisor,
        },
        {
            key: 'requests',
            label: t('Demandes'),
            icon: Inbox,
            badge: pendingTotal.value,
            show: props.workforce.isSupervisor,
        },
        {
            key: 'timesheets',
            label: t('Feuilles de temps'),
            icon: TimerReset,
            badge: 0,
            show: true,
        },
        {
            key: 'leave',
            label: t('Congés'),
            icon: CalendarOff,
            badge: 0,
            show: true,
        },
        {
            key: 'settings',
            label: t('Réglages'),
            icon: Settings2,
            badge: 0,
            show: props.workforce.isSupervisor,
        },
    ].filter((item) => item.show),
);
</script>

<template>
    <nav
        class="-mx-1 flex gap-1 overflow-x-auto border-b px-1 pb-px"
        :aria-label="t('Planning')"
    >
        <Link
            v-for="item in tabs"
            :key="item.key"
            :href="planningUrl(slug, item.key)"
            class="relative flex shrink-0 items-center gap-2 rounded-t-md px-3 py-2 text-sm font-medium transition-colors"
            :class="
                current === item.key
                    ? 'text-foreground after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :aria-current="current === item.key ? 'page' : undefined"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
            <span
                v-if="item.badge > 0"
                class="rounded-full bg-primary px-1.5 text-[11px] leading-5 font-semibold text-primary-foreground tabular-nums"
            >
                {{ item.badge > 99 ? '99+' : item.badge }}
            </span>
        </Link>
    </nav>
</template>
