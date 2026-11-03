<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    Phone,
    Search,
    Users,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useSoftphone } from '@/composables/useSoftphone';
import { avatarTone, initials } from '@/lib/avatar';
import { intlLocale, t } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index as pjsip } from '@/routes/asterisk/pjsip';
import { index as meetingsIndex, show as meetingShow } from '@/routes/meetings';
import type { DashboardInvitation, Team } from '@/types';

type Colleague = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    jobTitle: string | null;
    department: string | null;
    role: string | null;
    extension: string | null;
};

type TodayMeeting = {
    uuid: string;
    title: string;
    scheduledAt: string | null;
    durationMinutes: number | null;
    live: boolean;
    participants: number;
};

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    me: { extension: string | null };
    colleagues: Colleague[];
    meetings: TodayMeeting[];
    canManage: boolean;
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

/**
 * Home of organizations that use the app for internal calls and video
 * meetings only: the company directory (call a colleague's extension in
 * one click) and today's meetings.
 */
const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const firstName = computed(
    () => (page.props.auth?.user?.name ?? '').split(' ')[0] ?? '',
);
const { openDialer } = useSoftphone();

const search = ref('');

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) {
        return props.colleagues;
    }

    return props.colleagues.filter((c) =>
        [c.name, c.email, c.jobTitle, c.department, c.extension]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(query)),
    );
});

const withoutExtension = computed(
    () => props.colleagues.filter((c) => !c.extension).length,
);

const greeting = computed(() =>
    new Date().getHours() < 18 ? t('Bonjour') : t('Bonsoir'),
);

function meetingTime(meeting: TodayMeeting): string {
    if (meeting.live) {
        return t('En cours');
    }

    if (!meeting.scheduledAt) {
        return '';
    }

    return new Intl.DateTimeFormat(intlLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(meeting.scheduledAt));
}
</script>

<template>
    <Head :title="t('Tableau de bord')" />

    <PendingInvitationsModal
        v-if="pendingInvitations?.length"
        :invitations="pendingInvitations"
    />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-[var(--heading)]">
                    {{ greeting }} {{ firstName }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        me.extension
                            ? t('Votre poste : :ext', { ext: me.extension })
                            : t(
                                  "Aucun poste ne vous est attribué : demandez à l'administrateur.",
                              )
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    :disabled="!me.extension"
                    @click="openDialer()"
                    ><Phone /> {{ t('Composer') }}</Button
                >
                <Button as-child>
                    <Link :href="meetingsIndex(slug).url"
                        ><Video /> {{ t('Réunions') }}</Link
                    >
                </Button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <Card>
                <CardHeader
                    class="flex flex-row flex-wrap items-center justify-between gap-3 space-y-0"
                >
                    <div>
                        <CardTitle class="flex items-center gap-2"
                            ><Users class="size-4" />
                            {{ t('Annuaire') }}</CardTitle
                        >
                        <CardDescription>{{
                            t(
                                'Appelez un collègue sur son poste, gratuitement.',
                            )
                        }}</CardDescription>
                    </div>
                    <div class="relative w-full sm:w-64">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="search"
                            class="pl-8"
                            :placeholder="t('Nom, service, poste…')"
                            :aria-label="t('Rechercher un collègue')"
                        />
                    </div>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p
                        v-if="canManage && withoutExtension > 0"
                        class="rounded-md bg-notice-subtle p-3 text-sm text-notice-strong"
                    >
                        {{
                            t(
                                ':count collègue(s) sans poste ne peuvent pas être appelés.',
                                { count: withoutExtension },
                            )
                        }}
                        <Link
                            :href="pjsip().url"
                            class="font-medium underline underline-offset-4"
                            >{{ t('Attribuer des postes') }}</Link
                        >
                    </p>

                    <ul
                        v-if="filtered.length"
                        class="divide-y rounded-md border"
                    >
                        <li
                            v-for="c in filtered"
                            :key="c.id"
                            class="flex items-center gap-3 px-3 py-2.5"
                        >
                            <Avatar class="size-9 shrink-0 rounded-full">
                                <AvatarImage
                                    v-if="c.avatar"
                                    :src="c.avatar"
                                    :alt="c.name"
                                />
                                <AvatarFallback
                                    class="rounded-full text-xs font-medium"
                                    :class="avatarTone(c.name)"
                                    >{{ initials(c.name) }}</AvatarFallback
                                >
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ c.name }}
                                </div>
                                <div
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{
                                        [c.jobTitle, c.department]
                                            .filter(Boolean)
                                            .join(' · ') || c.email
                                    }}
                                </div>
                            </div>
                            <span
                                class="hidden font-mono text-sm text-muted-foreground sm:inline"
                                >{{ c.extension ?? '—' }}</span
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="!c.extension || !me.extension"
                                :aria-label="
                                    t('Appeler :name', { name: c.name })
                                "
                                @click="c.extension && openDialer(c.extension)"
                                ><Phone /> {{ t('Appeler') }}</Button
                            >
                        </li>
                    </ul>
                    <p
                        v-else
                        class="rounded-md border p-6 text-center text-sm text-muted-foreground"
                    >
                        {{
                            colleagues.length
                                ? t('Aucun collègue ne correspond.')
                                : t(
                                      'Invitez vos collègues depuis Paramètres → Organisation.',
                                  )
                        }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0"
                >
                    <div>
                        <CardTitle>{{ t('Réunions du jour') }}</CardTitle>
                        <CardDescription>{{
                            t('Celles où vous êtes invité.')
                        }}</CardDescription>
                    </div>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="meetingsIndex(slug).url" class="gap-1">
                            {{ t('Tout voir') }}
                            <ArrowRight class="h-3.5 w-3.5" />
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    <ul v-if="meetings.length" class="space-y-2">
                        <li
                            v-for="meeting in meetings"
                            :key="meeting.uuid"
                            class="flex items-center gap-3 rounded-md border px-3 py-2.5"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                :class="
                                    meeting.live
                                        ? 'bg-positive-subtle text-positive-strong'
                                        : 'bg-informative-subtle text-informative-strong'
                                "
                            >
                                <Video v-if="meeting.live" class="size-4" />
                                <CalendarClock v-else class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ meeting.title }}
                                </div>
                                <div
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ meetingTime(meeting) }} ·
                                    {{
                                        t(':count pers.', {
                                            count: meeting.participants,
                                        })
                                    }}
                                </div>
                            </div>
                            <Button
                                size="sm"
                                :variant="meeting.live ? 'default' : 'outline'"
                                as-child
                            >
                                <Link
                                    :href="
                                        meetingShow({
                                            current_team: slug,
                                            meeting: meeting.uuid,
                                        }).url
                                    "
                                    >{{ t('Rejoindre') }}</Link
                                >
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t("Aucune réunion aujourd'hui.") }}
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
