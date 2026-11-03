<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AgentStatusMenu from '@/components/AgentStatusMenu.vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTeamMode } from '@/composables/useTeamMode';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

// Only users with a phone extension in this team take calls.
const page = usePage();
const { isCallCenter } = useTeamMode();
// Ready / pause states only exist for call-center agents (queues).
const isAgent = computed(
    () => isCallCenter.value && Boolean(page.props.sipCredentials),
);
</script>

<template>
    <!-- Stays at the top while the page scrolls (breadcrumbs, theme, bell, status). -->
    <header
        class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 bg-background/95 px-6 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 supports-[backdrop-filter]:bg-background/80 md:rounded-t-xl md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <AppearanceToggle data-tour="theme" />
            <NotificationBell />
            <div v-if="isAgent" data-tour="agent-status">
                <AgentStatusMenu />
            </div>
        </div>
    </header>
</template>
