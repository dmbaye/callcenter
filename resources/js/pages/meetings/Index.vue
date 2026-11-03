<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarDays,
    CalendarX2,
    Circle,
    Clock,
    Copy,
    History,
    Lock,
    MoreHorizontal,
    NotebookPen,
    Pencil,
    Radio,
    Users,
    Video,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import AddToCalendar from '@/components/meetings/AddToCalendar.vue';
import MeetingFormDialog from '@/components/meetings/MeetingFormDialog.vue';
import MeetingNotes from '@/components/meetings/MeetingNotes.vue';
import ParticipantAvatars from '@/components/meetings/ParticipantAvatars.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { meetingDurationText } from '@/data/plans';
import { getEcho } from '@/lib/echo';
import { index as billing } from '@/routes/billing';
import { cancel, index, show } from '@/routes/meetings';
import type { MeetingRow, Team } from '@/types';

const props = defineProps<{
    videoEnabled: boolean;
    configured: boolean;
    maxParticipants: number | null;
    planName: string;
    canManageBilling: boolean;
    scope: 'mine' | 'all';
    canSeeAll: boolean;
    durations: number[];
    meetingMinutes: number | null;
    joinEarlyMinutes: number;
    live: MeetingRow[];
    upcoming: MeetingRow[];
    past: MeetingRow[];
    members: { id: number; name: string; role: string }[];
}>();

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Réunions',
                href: props.currentTeam ? index(props.currentTeam.slug) : '/',
            },
        ],
    }),
});

// Clock for "dans 25 min" / "depuis 12 min".
const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

/* Dialogs */
const formOpen = ref(false);
const formMode = ref<'instant' | 'scheduled'>('scheduled');
const editing = ref<MeetingRow | null>(null);
const cancelling = ref<MeetingRow | null>(null);
const notesFor = ref<MeetingRow | null>(null);
const cancelBusy = ref(false);

function openForm(
    mode: 'instant' | 'scheduled',
    meeting: MeetingRow | null = null,
) {
    formMode.value = mode;
    editing.value = meeting;
    formOpen.value = true;
}

function confirmCancel() {
    const meeting = cancelling.value;

    if (!meeting) {
        return;
    }

    cancelBusy.value = true;
    router.post(
        cancel({ current_team: slug.value, meeting: meeting.uuid }).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                cancelBusy.value = false;
                cancelling.value = null;
            },
        },
    );
}

async function copyLink(meeting: MeetingRow) {
    try {
        await navigator.clipboard.writeText(meeting.url);
        toast.success(t('Lien de la réunion copié.'));
    } catch {
        toast.error(
            t('Copie impossible : sélectionnez le lien dans la réunion.'),
        );
    }
}

function setScope(scope: 'mine' | 'all') {
    router.get(index(slug.value).url, scope === 'all' ? { scope } : {}, {
        preserveScroll: true,
        preserveState: true,
    });
}

/* Formatting */
const hour = new Intl.DateTimeFormat(intlLocale(), {
    hour: '2-digit',
    minute: '2-digit',
});

function time(value: string | null): string {
    return value ? hour.format(new Date(value)) : '—';
}

function dayKey(value: string): string {
    const date = new Date(value);

    return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
}

function dayLabel(value: string): { title: string; detail: string } {
    const date = new Date(value);
    const today = new Date();
    const tomorrow = new Date(Date.now() + 86_400_000);
    const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();
    const detail = new Intl.DateTimeFormat(intlLocale(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(date);

    if (sameDay(date, today)) {
        return { title: t("Aujourd'hui"), detail };
    }

    if (sameDay(date, tomorrow)) {
        return { title: 'Demain', detail };
    }

    return {
        title: detail.charAt(0).toUpperCase() + detail.slice(1),
        detail: '',
    };
}

function relative(value: string | null, prefix: 'dans' | 'depuis'): string {
    if (!value) {
        return '';
    }

    const minutes = Math.round(
        Math.abs(new Date(value).getTime() - now.value) / 60_000,
    );

    if (minutes < 1) {
        return prefix === 'dans' ? 'maintenant' : "à l'instant";
    }

    if (minutes < 60) {
        return `${prefix} ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours < 24) {
        return `${prefix} ${hours} h${rest ? ` ${String(rest).padStart(2, '0')}` : ''}`;
    }

    return `${prefix} ${Math.round(hours / 24)} j`;
}

function durationLabel(minutes: number | null): string {
    const value = minutes ?? 30;

    return value < 60
        ? `${value} min`
        : `${Math.floor(value / 60)} h${value % 60 ? ` ${value % 60}` : ''}`;
}

/** Started time can be in the past: "Rejoindre" is the main action then. */
function isJoinable(meeting: MeetingRow): boolean {
    return (
        !!meeting.scheduledAt &&
        new Date(meeting.scheduledAt).getTime() - now.value <=
            props.joinEarlyMinutes * 60_000
    );
}

function people(meeting: MeetingRow) {
    return [
        ...(meeting.creator ? [{ name: meeting.creator }] : []),
        ...meeting.participants,
    ];
}

const groups = computed(() => {
    const map = new Map<
        string,
        {
            key: string;
            label: { title: string; detail: string };
            items: MeetingRow[];
        }
    >();

    for (const meeting of props.upcoming) {
        const key = dayKey(meeting.scheduledAt!);

        if (!map.has(key)) {
            map.set(key, {
                key,
                label: dayLabel(meeting.scheduledAt!),
                items: [],
            });
        }

        map.get(key)!.items.push(meeting);
    }

    return [...map.values()];
});

const nextMeeting = computed(() => props.upcoming[0] ?? null);

const joinHref = (meeting: MeetingRow) =>
    show({ current_team: slug.value, meeting: meeting.uuid }).url;

// The lists follow starts / ends / plans pushed over Reverb.
let channel: string | null = null;

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 30_000);

    const echo = getEcho();
    const teamId = page.props.currentTeam?.id;

    if (!echo || !teamId) {
        return;
    }

    channel = `team.${teamId}.meetings`;
    echo.private(channel).listen('.meetings.changed', () =>
        router.reload({ only: ['live', 'upcoming', 'past'] }),
    );
});

onUnmounted(() => {
    clearInterval(ticker);

    if (channel) {
        getEcho()?.leave(channel);
    }
});
</script>

<template>
    <Head :title="t('Réunions')" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-8">
        <!-- Header -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="space-y-1">
                <h1
                    class="text-2xl font-bold tracking-tight text-heading sm:text-3xl"
                >
                    {{ t('Réunions') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    <template v-if="videoEnabled"
                        >Visioconférence dans le navigateur : jusqu'à
                        {{ maxParticipants }} participants,
                        {{ meetingDurationText(meetingMinutes) }} max par
                        réunion (offre {{ planName }}).</template
                    >
                    <template v-else>{{
                        t(
                            'Réunissez vos agents et superviseurs en vidéo, depuis le navigateur.',
                        )
                    }}</template>
                </p>
            </div>
            <div v-if="videoEnabled" class="flex flex-wrap gap-2">
                <Button variant="outline" @click="openForm('scheduled')">
                    <CalendarClock /> {{ t('Planifier') }}
                </Button>
                <Button
                    :disabled="!configured"
                    data-test="instant-meeting"
                    @click="openForm('instant')"
                >
                    <Video /> {{ t('Réunion instantanée') }}
                </Button>
            </div>
        </header>

        <!-- Not in the plan -->
        <section
            v-if="!videoEnabled"
            class="flex max-w-2xl flex-col gap-4 rounded-2xl border bg-background p-6 sm:flex-row sm:items-center"
        >
            <span
                class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-muted"
            >
                <Lock class="size-5" />
            </span>
            <div class="flex-1 space-y-1">
                <p class="font-bold text-heading">
                    {{ t("Non inclus dans l'offre :p0", { p0: planName }) }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            "Changez d'offre pour réunir votre équipe en vidéo, avec invitations agenda et notes partagées.",
                        )
                    }}
                </p>
            </div>
            <Button v-if="canManageBilling" as-child>
                <Link :href="billing().url">{{ t("Changer d'offre") }}</Link>
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                {{ t("Demandez à l'administrateur de changer d'offre.") }}
            </p>
        </section>

        <template v-else>
            <p
                v-if="!configured"
                class="rounded-lg border border-notice-border bg-notice-subtle px-4 py-3 text-sm text-notice-strong"
            >
                {{
                    t(
                        "Le serveur vidéo (LiveKit) n'est pas encore configuré : vous pouvez planifier des réunions, mais pas encore les rejoindre.",
                    )
                }}
            </p>

            <div
                class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
            >
                <div class="min-w-0 space-y-8">
                    <!-- Scope -->
                    <div
                        v-if="canSeeAll"
                        class="inline-flex rounded-full border bg-background p-0.5 text-sm"
                        role="tablist"
                    >
                        <button
                            v-for="option in [
                                { value: 'mine', label: 'Mes réunions' },
                                { value: 'all', label: 'Toute l’équipe' },
                            ] as const"
                            :key="option.value"
                            type="button"
                            role="tab"
                            :aria-selected="scope === option.value"
                            class="rounded-full px-3 py-1 font-medium transition-colors"
                            :class="
                                scope === option.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="setScope(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <!-- Live -->
                    <section v-if="live.length" aria-labelledby="live-title">
                        <h2
                            id="live-title"
                            class="mb-3 flex items-center gap-2 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <Radio class="size-4 text-negative" />
                            {{ t('En direct') }}
                        </h2>
                        <ul class="grid gap-3 md:grid-cols-2">
                            <li
                                v-for="meeting in live"
                                :key="meeting.uuid"
                                class="flex flex-col gap-4 rounded-2xl border border-positive-border bg-background p-5 shadow-sm"
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="truncate text-base font-bold text-heading"
                                        >
                                            {{ meeting.title }}
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ meeting.creator ?? '—' }} ·
                                            {{
                                                relative(
                                                    meeting.startedAt,
                                                    'depuis',
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <span
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-negative-subtle px-2 py-0.5 text-xs font-bold text-negative-strong"
                                    >
                                        <span class="relative flex size-2">
                                            <span
                                                class="absolute inline-flex size-full animate-ping rounded-full bg-negative opacity-60"
                                            />
                                            <span
                                                class="relative inline-flex size-2 rounded-full bg-negative"
                                            />
                                        </span>
                                        {{
                                            meeting.recording
                                                ? 'REC'
                                                : 'En cours'
                                        }}
                                    </span>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <ParticipantAvatars
                                        :people="people(meeting)"
                                        size="md"
                                    />
                                    <Button as-child>
                                        <Link :href="joinHref(meeting)"
                                            ><Video />
                                            {{ t('Rejoindre') }}</Link
                                        >
                                    </Button>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <!-- Upcoming -->
                    <section aria-labelledby="upcoming-title">
                        <h2
                            id="upcoming-title"
                            class="mb-3 flex items-center gap-2 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <CalendarDays class="size-4" /> {{ t('À venir') }}
                        </h2>

                        <div
                            v-if="groups.length === 0"
                            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-background px-6 py-12 text-center"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-full bg-informative-subtle text-informative-strong"
                            >
                                <CalendarClock class="size-6" />
                            </span>
                            <div class="space-y-1">
                                <p class="font-bold text-heading">
                                    {{ t('Aucune réunion planifiée') }}
                                </p>
                                <p
                                    class="max-w-sm text-sm text-muted-foreground"
                                >
                                    {{
                                        t(
                                            'Planifiez un briefing ou une formation : vos participants reçoivent l’invitation par e-mail et un rappel avant le début.',
                                        )
                                    }}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                @click="openForm('scheduled')"
                            >
                                <CalendarClock />
                                {{ t('Planifier une réunion') }}
                            </Button>
                        </div>

                        <div v-else class="space-y-5">
                            <div v-for="group in groups" :key="group.key">
                                <p class="mb-2 text-sm">
                                    <span class="font-bold text-heading">{{
                                        group.label.title
                                    }}</span>
                                    <span
                                        v-if="group.label.detail"
                                        class="text-muted-foreground"
                                    >
                                        · {{ group.label.detail }}</span
                                    >
                                </p>
                                <ul
                                    class="divide-y overflow-hidden rounded-2xl border bg-background"
                                >
                                    <li
                                        v-for="meeting in group.items"
                                        :key="meeting.uuid"
                                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"
                                        :data-test="`upcoming-${meeting.uuid}`"
                                    >
                                        <div class="flex min-w-0 flex-1 gap-4">
                                            <div
                                                class="w-16 shrink-0 border-l-[3px] pl-3"
                                                :class="
                                                    isJoinable(meeting)
                                                        ? 'border-positive'
                                                        : 'border-primary'
                                                "
                                            >
                                                <p
                                                    class="font-bold text-heading tabular-nums"
                                                >
                                                    {{
                                                        time(
                                                            meeting.scheduledAt,
                                                        )
                                                    }}
                                                </p>
                                                <p
                                                    class="text-xs text-muted-foreground tabular-nums"
                                                >
                                                    {{
                                                        durationLabel(
                                                            meeting.durationMinutes,
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                            <div
                                                class="min-w-0 flex-1 space-y-1"
                                            >
                                                <p
                                                    class="truncate font-semibold text-heading"
                                                >
                                                    {{ meeting.title }}
                                                </p>
                                                <p
                                                    v-if="meeting.description"
                                                    class="line-clamp-1 text-sm text-muted-foreground"
                                                >
                                                    {{ meeting.description }}
                                                </p>
                                                <div
                                                    class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                                                >
                                                    <ParticipantAvatars
                                                        :people="
                                                            people(meeting)
                                                        "
                                                    />
                                                    <span>{{
                                                        t('Organisée par :p0', {
                                                            p0: meeting.isOrganizer
                                                                ? 'vous'
                                                                : meeting.creator,
                                                        })
                                                    }}</span>
                                                    <span
                                                        v-if="
                                                            isJoinable(meeting)
                                                        "
                                                        class="font-semibold text-positive-strong"
                                                        >{{
                                                            new Date(
                                                                meeting.scheduledAt!,
                                                            ).getTime() > now
                                                                ? `Commence ${relative(meeting.scheduledAt, 'dans')}`
                                                                : t('À l’heure')
                                                        }}</span
                                                    >
                                                </div>
                                            </div>
                                        </div>

                                        <div
                                            class="flex shrink-0 items-center gap-1.5 sm:pl-2"
                                        >
                                            <Button
                                                as-child
                                                size="sm"
                                                :variant="
                                                    isJoinable(meeting)
                                                        ? 'default'
                                                        : 'outline'
                                                "
                                            >
                                                <Link :href="joinHref(meeting)"
                                                    ><Video />
                                                    {{ t('Rejoindre') }}</Link
                                                >
                                            </Button>
                                            <AddToCalendar
                                                v-if="meeting.calendar"
                                                :calendar="meeting.calendar"
                                            />
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        :aria-label="
                                                            t('Plus d\'actions')
                                                        "
                                                    >
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent
                                                    align="end"
                                                    class="w-52"
                                                >
                                                    <DropdownMenuItem
                                                        @select="
                                                            copyLink(meeting)
                                                        "
                                                    >
                                                        <Copy />
                                                        {{
                                                            t('Copier le lien')
                                                        }}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="
                                                            meeting.canEditNotes
                                                        "
                                                        @select="
                                                            notesFor = meeting
                                                        "
                                                    >
                                                        <NotebookPen />
                                                        {{
                                                            t(
                                                                'Préparer des notes',
                                                            )
                                                        }}
                                                    </DropdownMenuItem>
                                                    <template
                                                        v-if="meeting.canManage"
                                                    >
                                                        <DropdownMenuItem
                                                            @select="
                                                                openForm(
                                                                    'scheduled',
                                                                    meeting,
                                                                )
                                                            "
                                                        >
                                                            <Pencil />
                                                            {{ t('Modifier') }}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            class="text-negative-strong focus:text-negative-strong"
                                                            @select="
                                                                cancelling =
                                                                    meeting
                                                            "
                                                        >
                                                            <CalendarX2 />
                                                            {{
                                                                t(
                                                                    'Annuler la réunion',
                                                                )
                                                            }}
                                                        </DropdownMenuItem>
                                                    </template>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <!-- History -->
                    <section v-if="past.length" aria-labelledby="past-title">
                        <h2
                            id="past-title"
                            class="mb-3 flex items-center gap-2 text-sm font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <History class="size-4" />
                            {{ t('30 derniers jours') }}
                        </h2>
                        <ul
                            class="divide-y overflow-hidden rounded-2xl border bg-background text-sm"
                        >
                            <li
                                v-for="meeting in past"
                                :key="meeting.uuid"
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate font-medium"
                                    >{{ meeting.title }}</span
                                >
                                <span
                                    v-if="meeting.hasRecording"
                                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                    ><Circle
                                        class="size-2.5 fill-negative text-negative"
                                    />
                                    {{ t('Enregistrée') }}</span
                                >
                                <span
                                    v-if="meeting.endedReason === 'limit'"
                                    class="text-xs text-notice-strong"
                                    >{{ t('Limite de durée atteinte') }}</span
                                >
                                <button
                                    v-if="meeting.notes || meeting.canEditNotes"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium hover:bg-accent"
                                    :class="
                                        meeting.notes
                                            ? 'text-primary'
                                            : 'text-muted-foreground'
                                    "
                                    @click="notesFor = meeting"
                                >
                                    <NotebookPen class="size-3.5" />
                                    {{
                                        meeting.notes
                                            ? 'Notes'
                                            : t('Ajouter des notes')
                                    }}
                                </button>
                                <span
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    {{
                                        new Date(
                                            meeting.startedAt ??
                                                meeting.scheduledAt ??
                                                meeting.createdAt,
                                        ).toLocaleDateString(intlLocale(), {
                                            day: 'numeric',
                                            month: 'short',
                                        })
                                    }}
                                    <template v-if="meeting.startedAt">
                                        · {{ time(meeting.startedAt) }}–{{
                                            time(meeting.endedAt)
                                        }}</template
                                    >
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        meeting.status === 'cancelled'
                                            ? 'bg-negative-subtle text-negative-strong'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    >{{
                                        meeting.status === 'cancelled'
                                            ? t('Annulée')
                                            : t('Terminée')
                                    }}</span
                                >
                            </li>
                        </ul>
                    </section>
                </div>

                <!-- Side -->
                <aside class="space-y-4 lg:sticky lg:top-6">
                    <section
                        class="overflow-hidden rounded-2xl border bg-background"
                    >
                        <div
                            class="bg-primary px-5 py-4 text-primary-foreground"
                        >
                            <p
                                class="text-xs font-semibold tracking-wide uppercase opacity-80"
                            >
                                {{ t('Prochaine réunion') }}
                            </p>
                            <p
                                v-if="nextMeeting"
                                class="mt-1 text-2xl font-bold tabular-nums"
                            >
                                {{ relative(nextMeeting.scheduledAt, 'dans') }}
                            </p>
                            <p v-else class="mt-1 text-lg font-bold">
                                {{ t('Rien de prévu') }}
                            </p>
                        </div>
                        <div v-if="nextMeeting" class="space-y-3 p-5">
                            <div>
                                <p class="font-semibold text-heading">
                                    {{ nextMeeting.title }}
                                </p>
                                <p
                                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                                >
                                    <Clock class="size-3.5" />
                                    {{
                                        dayLabel(nextMeeting.scheduledAt!)
                                            .title
                                    }}, {{ time(nextMeeting.scheduledAt) }} –
                                    {{ time(nextMeeting.endsAt) }}
                                </p>
                                <p
                                    class="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"
                                >
                                    <Users class="size-3.5" />
                                    {{ nextMeeting.participants.length + 1 }}
                                    participant(s)
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <Button as-child size="sm" class="flex-1">
                                    <Link :href="joinHref(nextMeeting)"
                                        ><Video /> {{ t('Rejoindre') }}</Link
                                    >
                                </Button>
                                <AddToCalendar
                                    v-if="nextMeeting.calendar"
                                    :calendar="nextMeeting.calendar"
                                />
                            </div>
                        </div>
                        <p v-else class="p-5 text-sm text-muted-foreground">
                            {{
                                t(
                                    'Les réunions planifiées où vous êtes invité(e) apparaîtront ici avec un compte à rebours.',
                                )
                            }}
                        </p>
                    </section>

                    <section
                        class="space-y-3 rounded-2xl border bg-background p-5 text-sm"
                    >
                        <p class="font-semibold text-heading">
                            {{ t('Bon à savoir') }}
                        </p>
                        <ul class="space-y-2 text-muted-foreground">
                            <li class="flex gap-2">
                                <CalendarClock class="mt-0.5 size-4 shrink-0" />
                                {{
                                    t(
                                        'Les invités reçoivent l’invitation par e-mail, compatible Gmail, Outlook et Apple Calendar.',
                                    )
                                }}
                            </li>
                            <li class="flex gap-2">
                                <Radio class="mt-0.5 size-4 shrink-0" />
                                {{
                                    t(
                                        'Un rappel arrive 10 minutes avant le début, puis une notification quand la réunion commence.',
                                    )
                                }}
                            </li>
                            <li class="flex gap-2">
                                <Video class="mt-0.5 size-4 shrink-0" />
                                {{
                                    t(
                                        'Les agents passent en « Non prêt » pendant la réunion : les appels ne sonnent pas.',
                                    )
                                }}
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </template>
    </div>

    <MeetingFormDialog
        v-model:open="formOpen"
        :mode="formMode"
        :meeting="editing"
        :members="members"
        :durations="durations"
        :meeting-minutes="meetingMinutes"
        :plan-name="planName"
        :slug="slug"
        :disabled="formMode === 'instant' && !configured"
    />

    <Dialog
        :open="notesFor !== null"
        @update:open="(value) => !value && (notesFor = null)"
    >
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    t('Notes — :p0', { p0: notesFor?.title })
                }}</DialogTitle>
                <DialogDescription>
                    {{ t('Partagées avec les participants de la réunion.') }}
                </DialogDescription>
            </DialogHeader>
            <MeetingNotes
                v-if="notesFor"
                :slug="slug"
                :meeting-uuid="notesFor.uuid"
                :meeting-title="notesFor.title"
                :notes="notesFor.notes"
                :updated-at="notesFor.notesUpdatedAt"
                :updated-by="notesFor.notesUpdatedBy"
                :can-edit="notesFor.canEditNotes"
                :rows="14"
                @saved="
                    (payload) => {
                        if (notesFor) {
                            notesFor.notes = payload.notes;
                            notesFor.notesUpdatedAt = payload.updatedAt;
                            notesFor.notesUpdatedBy = payload.updatedBy;
                        }
                    }
                "
            />
        </DialogContent>
    </Dialog>

    <Dialog
        :open="cancelling !== null"
        @update:open="(value) => !value && (cancelling = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    t('Annuler « :p0 » ?', { p0: cancelling?.title })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Les participants sont prévenus par notification et par e-mail ; la réunion est retirée de leur agenda.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="cancelling = null">{{
                    t('Garder la réunion')
                }}</Button>
                <Button
                    variant="destructive"
                    :disabled="cancelBusy"
                    @click="confirmCancel"
                    >{{ t('Annuler la réunion') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
