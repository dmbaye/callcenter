<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    CalendarDays,
    ClipboardList,
    LifeBuoy,
    ServerCog,
    LayoutGrid,
    Megaphone,
    Timeline,
    Video,
} from '@lucide/vue';

import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import { useTeamMode } from '@/composables/useTeamMode';
import { t } from '@/lib/i18n';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';

import { calls, dashboard, monitoring } from '@/routes';
import { index as help } from '@/routes/help';
import { platform } from '@/routes/admin';
import { index as agentLogs } from '@/routes/agent-logs';
import { index as campaigns, workspace } from '@/routes/campaigns';
import { index as meetings } from '@/routes/meetings';
import type { NavItem } from '@/types';

const page = usePage();
const { isCallCenter } = useTeamMode();

/*
 * On a phone the sidebar is a drawer over the page: close it once a link
 * opened another page (the layout stays mounted, so it would stay open).
 * Same-page reloads (polling, forms) leave it as it is.
 */
const { isMobile, setOpenMobile } = useSidebar();
let currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

const stopListening = router.on('navigate', () => {
    const path = window.location.pathname;

    if (path !== currentPath) {
        currentPath = path;

        if (isMobile.value) {
            setOpenMobile(false);
        }
    }
});

onBeforeUnmount(stopListening);

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const callsUrl = computed(() =>
    page.props.currentTeam ? calls(page.props.currentTeam.slug).url : '/',
);

const monitoringUrl = computed(() =>
    page.props.currentTeam ? monitoring(page.props.currentTeam.slug).url : '/',
);

const agentLogsUrl = computed(() =>
    page.props.currentTeam ? agentLogs(page.props.currentTeam.slug).url : '/',
);

const meetingsUrl = computed(() =>
    page.props.currentTeam ? meetings(page.props.currentTeam.slug).url : '/',
);

const canSuperviseCampaigns = computed(
    () =>
        !!page.props.currentTeam &&
        ['owner', 'admin', 'supervisor'].includes(
            page.props.currentTeam.role ?? '',
        ),
);

const campaignsUrl = computed(() => {
    const slug = page.props.currentTeam?.slug;

    if (!slug) {
        return '/';
    }

    return canSuperviseCampaigns.value
        ? campaigns(slug).url
        : workspace(slug).url;
});

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: t('Tableau de bord'),
        href: dashboardUrl.value,
        icon: LayoutGrid,
        tour: 'nav-dashboard',
    },
    {
        title: t('Historique'),
        href: callsUrl.value,
        icon: Timeline,
        tour: 'nav-calls',
    },
    ...(isCallCenter.value &&
    page.props.billing?.features?.monitoring !== false &&
    page.props.currentTeam &&
    ['owner', 'admin', 'supervisor'].includes(page.props.currentTeam.role ?? '')
        ? [
              {
                  title: t('Supervision'),
                  href: monitoringUrl.value,
                  icon: Activity,
                  tour: 'nav-monitoring',
              },
              {
                  title: t('Journal des agents'),
                  href: agentLogsUrl.value,
                  icon: ClipboardList,
                  tour: 'nav-agent-logs',
              },
          ]
        : []),
    // Agents always see it: inbound callback requests land in their workspace.
    ...(isCallCenter.value && page.props.currentTeam
        ? [
              {
                  title: t('Campagnes'),
                  href: campaignsUrl.value,
                  icon: Megaphone,
                  tour: 'nav-campaigns',
              },
          ]
        : []),
    ...(page.props.currentTeam
        ? [
              {
                  title: t('Planning'),
                  href: `/${page.props.currentTeam.slug}/planning`,
                  icon: CalendarDays,
                  tour: 'nav-planning',
              },
              {
                  title: t('Réunions'),
                  href: meetingsUrl.value,
                  icon: Video,
                  tour: 'nav-meetings',
              },
          ]
        : []),
    { title: t('Aide'), href: help().url, icon: LifeBuoy, tour: 'nav-help' },
    // Platform operators only (php artisan platform:admin).
    ...(page.props.auth.user?.is_platform_admin
        ? [{ title: t('Plateforme'), href: platform().url, icon: ServerCog }]
        : []),
]);

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem data-tour="team-switcher">
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser data-tour="user-menu" />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
