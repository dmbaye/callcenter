<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useTeamMode } from '@/composables/useTeamMode';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import { index as dialplan } from '@/routes/asterisk/dialplan';
import { index as asteriskIvr } from '@/routes/asterisk/ivr';
import { index as pjsip } from '@/routes/asterisk/pjsip';
import { index as holdMusic } from '@/routes/asterisk/hold-music';
import { index as voicemail } from '@/routes/asterisk/voicemail';
import { index as asteriskQueues } from '@/routes/asterisk/queues';
import { index as pauseReasons } from '@/routes/pause-reasons';
import { index as billing } from '@/routes/billing';
import { edit as editNotifications } from '@/routes/notification-preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editTeam, index as teams } from '@/routes/teams';
import type { NavItem } from '@/types';

const page = usePage();
const { isCallCenter } = useTeamMode();

const isTeamAdmin = computed(() => {
    const role = (page.props.currentTeam as { role?: string } | null)?.role;

    return role === 'owner' || role === 'admin';
});

const sidebarNavItems = computed<(NavItem & { match?: NavItem['href'] })[]>(
    () => [
        {
            title: t('Profil'),
            href: editProfile(),
        },
        {
            title: t('Notifications'),
            href: editNotifications(),
        },
        {
            title: t('Sécurité'),
            href: editSecurity(),
        },
        // One entry for the organization (= team): its page, and the list of
        // organizations the user belongs to, both live under /settings/teams.
        {
            title: t('Organisation'),
            href: page.props.currentTeam
                ? editTeam(page.props.currentTeam.slug)
                : teams(),
            match: teams(),
        },
        ...(isTeamAdmin.value
            ? [
                  {
                      title: t('Abonnement'),
                      href: billing(),
                  },
                  // Call-center settings: hidden for internal-only organizations.
                  ...(isCallCenter.value
                      ? [
                            {
                                title: t("Files d'attente"),
                                href: asteriskQueues(),
                            },
                            {
                                title: t('Numéros et SVI'),
                                href: asteriskIvr(),
                            },
                        ]
                      : []),
                  {
                      title: t("Plan d'appel"),
                      href: dialplan(),
                  },
                  {
                      title: t('Postes SIP'),
                      href: pjsip(),
                  },
                  {
                      title: t('Messagerie vocale'),
                      href: voicemail(),
                  },
                  {
                      title: t("Musique d'attente"),
                      href: holdMusic(),
                  },
                  ...(isCallCenter.value
                      ? [
                            {
                                title: t('Motifs de pause'),
                                href: pauseReasons(),
                            },
                        ]
                      : []),
              ]
            : []),
    ],
);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            :title="t('Paramètres')"
            :description="
                t('Gérez votre profil et les paramètres de votre compte')
            "
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    :aria-label="t('Paramètres')"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start rounded-[var(--control-radius)] [font-weight:400]',
                            {
                                'bg-accent [font-weight:700] text-[var(--heading)]':
                                    isCurrentOrParentUrl(
                                        item.match ?? item.href,
                                    ),
                            },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="min-w-0 flex-1">
                <section class="w-full min-w-0 space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
