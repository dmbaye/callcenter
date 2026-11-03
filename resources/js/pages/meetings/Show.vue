<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    Circle,
    Copy,
    Hourglass,
    Info,
    NotebookPen,
    Link2,
    LogOut,
    Mic,
    MicOff,
    MonitorUp,
    PhoneOff,
    UserPlus,
    Users,
    Video,
    VideoOff,
    Volume2,
    X,
} from '@lucide/vue';
import axios from 'axios';
import { ConnectionState } from 'livekit-client';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import AddToCalendar from '@/components/meetings/AddToCalendar.vue';
import MeetingLobby from '@/components/meetings/MeetingLobby.vue';
import MemberPicker from '@/components/meetings/MemberPicker.vue';
import ParticipantTile from '@/components/meetings/ParticipantTile.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
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
import { useAgentQueue } from '@/composables/useAgentQueue';
import { useTeamMode } from '@/composables/useTeamMode';
import type { JoinMedia } from '@/composables/useMeetingRoom';
import { useMeetingRoom } from '@/composables/useMeetingRoom';
import { avatarTone, initials } from '@/lib/avatar';
import MeetingNotes from '@/components/meetings/MeetingNotes.vue';
import { meetingDurationText } from '@/data/plans';
import { getEcho } from '@/lib/echo';
import { index as billing } from '@/routes/billing';
import { end, index, invite, joined, token } from '@/routes/meetings';
import {
    start as startRecording,
    stop as stopRecording,
} from '@/routes/meetings/recording';
import type { MeetingRow, Team } from '@/types';

const props = defineProps<{
    meeting: MeetingRow;
    identity: string;
    me: { id: number; name: string };
    canEnd: boolean;
    canRecord: boolean;
    planName: string;
    meetingMinutes: number | null;
    canManageBilling: boolean;
    members: { id: number; name: string; role?: string }[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null; meeting?: MeetingRow }) => ({
        breadcrumbs: [
            {
                title: 'Réunions',
                href: props.currentTeam ? index(props.currentTeam.slug) : '/',
            },
            ...(props.meeting
                ? [{ title: props.meeting.title, href: props.meeting.url }]
                : []),
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const routeArgs = computed(() => ({
    current_team: slug.value,
    meeting: props.meeting.uuid,
}));

const {
    tiles,
    state,
    error,
    micEnabled,
    cameraEnabled,
    screenEnabled,
    audioBlocked,
    agentState: meetingAgentState,
    connect,
    disconnect,
    toggleMic,
    toggleCamera,
    toggleScreen,
    startAudio,
} = useMeetingRoom();

const { agentState, pauseReason, applyState, setAgentState } = useAgentQueue();
// Internal-only organizations have no queues: no pause, no "Repasser Prêt".
const { isInternalOnly } = useTeamMode();

/** Reason the server sets while an agent is in a meeting (AgentStates::MEETING_REASON). */
const MEETING_REASON = 'Réunion vidéo';

/** Lobby first; the room once "Rejoindre" is pressed. */
const phase = ref<'lobby' | 'room'>('lobby');
const audioHost = ref<HTMLElement | null>(null);
const inviteOpen = ref(false);
const invitees = ref<number[]>([]);
const recording = ref(props.meeting.recording);
const busy = ref(false);
const panel = ref<'people' | 'info' | 'notes' | null>(null);

/** Why the room closed: plan limit, organizer, or we lost the connection. */
const ended = ref<'limit' | 'host' | 'empty' | 'lost' | null>(null);
let leaving = false;

/** Latest notes: kept here so the panel reopens on the current text. */
const notesState = ref({
    notes: props.meeting.notes,
    updatedAt: props.meeting.notesUpdatedAt,
    updatedBy: props.meeting.notesUpdatedBy,
});

function onNotesSaved(payload: {
    notes: string;
    updatedAt: string | null;
    updatedBy: string | null;
}) {
    notesState.value = { ...payload };
}
const lastMedia = ref<JoinMedia>({});

/* Clock since the start of the meeting (or since we joined). */
const now = ref(Date.now());
const joinedAt = ref<number | null>(null);
let ticker: ReturnType<typeof setInterval> | undefined;

const elapsed = computed(() => {
    const from = props.meeting.startedAt
        ? new Date(props.meeting.startedAt).getTime()
        : joinedAt.value;

    if (!from) {
        return '';
    }

    const seconds = Math.max(0, Math.floor((now.value - from) / 1000));
    const h = Math.floor(seconds / 3600);
    const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
    const s = String(seconds % 60).padStart(2, '0');

    return h ? `${h}:${m}:${s}` : `${m}:${s}`;
});

/* Stage layout: a screen share takes the stage, everyone else the strip. */
const stage = computed(() => tiles.value.find((tile) => tile.isScreen) ?? null);
const others = computed(() =>
    tiles.value.filter((tile) => tile.key !== stage.value?.key),
);
const people = computed(() => tiles.value.filter((tile) => !tile.isScreen));

const gridClass = computed(() => {
    const count = others.value.length;

    if (stage.value) {
        return 'grid-cols-2 lg:grid-cols-1';
    }

    if (count <= 1) {
        return 'grid-cols-1 max-w-4xl mx-auto w-full';
    }

    if (count <= 4) {
        return 'grid-cols-1 sm:grid-cols-2 max-w-6xl mx-auto w-full';
    }

    return 'grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4';
});

/** Invited but not (yet) in the call. */
const absent = computed(() => {
    const present = new Set(people.value.map((tile) => tile.name));

    return props.meeting.participants.filter((p) => !present.has(p.name));
});

const connecting = computed(
    () =>
        state.value === ConnectionState.Connecting ||
        state.value === ConnectionState.Reconnecting ||
        state.value === ConnectionState.SignalReconnecting,
);

async function join(media: JoinMedia = lastMedia.value) {
    lastMedia.value = media;
    phase.value = 'room';
    await nextTick();

    if (!audioHost.value) {
        return;
    }

    await connect(
        token(routeArgs.value).url,
        audioHost.value,
        joined(routeArgs.value).url,
        media,
    );

    if (state.value === ConnectionState.Connected) {
        joinedAt.value ??= Date.now();
    }

    // Joining paused the agent in the queues: show it in the status menu at once.
    const status = meetingAgentState.value;

    if (status && !isInternalOnly.value) {
        applyState(status);

        if (status.reason === MEETING_REASON) {
            toast.info(t('Statut : Non prêt – Réunion vidéo'), {
                description:
                    'Les appels des files ne vous sont plus présentés pendant la réunion.',
            });
        }
    }
}

/** Still paused by the meeting: offer to take calls again (never automatic). */
function offerReady() {
    if (
        isInternalOnly.value ||
        agentState.value !== 'not_ready' ||
        pauseReason.value !== MEETING_REASON
    ) {
        return;
    }

    toast(t('Vous êtes toujours en Non prêt – Réunion vidéo'), {
        description: t('Repassez en Prêt pour recevoir à nouveau les appels.'),
        duration: 15000,
        action: {
            label: t('Repasser Prêt'),
            onClick: () =>
                void setAgentState('ready').catch(() =>
                    toast.error(t("Le statut n'a pas pu être modifié.")),
                ),
        },
    });
}

async function leave() {
    leaving = true;
    await disconnect();
    offerReady();
    router.visit(index(slug.value).url);
}

async function endForAll() {
    leaving = true;
    await disconnect();
    offerReady();
    router.post(end(routeArgs.value).url);
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.meeting.url);
        toast.success(t('Lien de la réunion copié.'));
    } catch {
        toast.error(t('Copie impossible.'));
    }
}

async function sendInvites() {
    if (invitees.value.length === 0) {
        return;
    }

    busy.value = true;

    try {
        await axios.post(invite(routeArgs.value).url, {
            invitees: invitees.value,
        });
        toast.success(
            t(
                'Invitations envoyées : une notification avec le lien est arrivée chez chacun.',
            ),
        );
        invitees.value = [];
        inviteOpen.value = false;
    } catch {
        toast.error(t("Les invitations n'ont pas pu être envoyées."));
    } finally {
        busy.value = false;
    }
}

async function toggleRecording() {
    busy.value = true;

    try {
        if (recording.value) {
            await axios.delete(stopRecording(routeArgs.value).url);
            recording.value = false;
            toast.success(t('Enregistrement arrêté.'));
        } else {
            await axios.post(startRecording(routeArgs.value).url);
            recording.value = true;
            toast.success(t('Enregistrement démarré.'));
        }
    } catch (exception) {
        const message = axios.isAxiosError(exception)
            ? (exception.response?.data?.message as string | undefined)
            : undefined;
        toast.error(message || "L'enregistrement n'a pas pu être modifié.");
    } finally {
        busy.value = false;
    }
}

function togglePanel(name: 'people' | 'info' | 'notes') {
    panel.value = panel.value === name ? null : name;
}

const schedule = computed(() => {
    if (!props.meeting.scheduledAt) {
        return null;
    }

    const day = new Intl.DateTimeFormat(intlLocale(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(props.meeting.scheduledAt));
    const hour = new Intl.DateTimeFormat(intlLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    });

    return `${day.charAt(0).toUpperCase()}${day.slice(1)}, ${hour.format(new Date(props.meeting.scheduledAt))} – ${props.meeting.endsAt ? hour.format(new Date(props.meeting.endsAt)) : ''}`;
});

/* Plan time limit: countdown, warnings, then the room closes. */
const limitEnd = computed<number | null>(() => {
    if (props.meeting.limitEndsAt) {
        return new Date(props.meeting.limitEndsAt).getTime();
    }

    // Opened by us just now: the server started the clock when we joined.
    return props.meetingMinutes && joinedAt.value
        ? joinedAt.value + props.meetingMinutes * 60_000
        : null;
});

const remaining = computed(() =>
    limitEnd.value === null
        ? null
        : Math.max(0, Math.floor((limitEnd.value - now.value) / 1000)),
);

const remainingLabel = computed(() => {
    const seconds = remaining.value ?? 0;
    const h = Math.floor(seconds / 3600);
    const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
    const sec = String(seconds % 60).padStart(2, '0');

    return h ? `${h}:${m}:${sec}` : `${m}:${sec}`;
});

/** Shown in the last hour of a limited meeting. */
const showCountdown = computed(
    () =>
        phase.value === 'room' &&
        remaining.value !== null &&
        remaining.value <= 3600,
);

const warned = new Set<number>();

watch(remaining, (seconds) => {
    if (seconds === null || phase.value !== 'room' || ended.value) {
        return;
    }

    for (const minutes of [10, 1]) {
        if (seconds <= minutes * 60 && seconds > 0 && !warned.has(minutes)) {
            warned.add(minutes);
            toast.warning(
                minutes === 1
                    ? 'La réunion se termine dans 1 minute'
                    : t('La réunion se termine dans :p0 minutes', {
                          p0: minutes,
                      }),
                {
                    description: t('Durée maximale de l’offre :p0 : :p1.', {
                        p0: props.planName,
                        p1: meetingDurationText(props.meetingMinutes),
                    }),
                    duration: 10_000,
                },
            );
        }
    }

    if (seconds === 0) {
        void closeRoom('limit');
    }
});

async function closeRoom(reason: 'limit' | 'host' | 'empty' | 'lost') {
    if (ended.value) {
        return;
    }

    ended.value = reason;
    leaving = true;
    await disconnect();
    offerReady();
}

// LiveKit dropped us without "Quitter": the room was closed, or the network.
watch(state, (next, previous) => {
    if (
        next === ConnectionState.Disconnected &&
        previous !== ConnectionState.Disconnected &&
        phase.value === 'room' &&
        !leaving &&
        joinedAt.value !== null
    ) {
        setTimeout(() => {
            if (!ended.value && !leaving) {
                ended.value = remaining.value === 0 ? 'limit' : 'lost';
            }
        }, 800);
    }
});

const endedText = computed(() => {
    switch (ended.value) {
        case 'limit':
            return {
                title: t('La réunion est terminée'),
                body: `Elle a atteint la durée maximale de ${meetingDurationText(props.meeting.timeLimitMinutes ?? props.meetingMinutes)} de l’offre ${props.planName}.`,
            };
        case 'host':
            return {
                title: t('La réunion est terminée'),
                body: t('L’organisateur a terminé la réunion pour tous.'),
            };
        case 'empty':
            return {
                title: t('La réunion est terminée'),
                body: t('Tout le monde est parti.'),
            };
        default:
            return {
                title: t('Vous avez été déconnecté(e)'),
                body: t(
                    'La connexion au serveur vidéo a été perdue. Vérifiez votre connexion internet, puis rejoignez à nouveau.',
                ),
            };
    }
});

async function rejoin() {
    ended.value = null;
    leaving = false;
    await join();
}

let meetingChannel: string | null = null;

const onEnded = (payload: { reason: 'limit' | 'host' | 'empty' | null }) => {
    if (phase.value === 'room' && !leaving) {
        void closeRoom(payload.reason ?? 'host');
    }
};

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000);

    const echo = getEcho();

    if (echo) {
        meetingChannel = `meeting.${props.meeting.uuid}`;
        echo.private(meetingChannel)
            .listen('.meeting.ended', onEnded)
            .listen('.meeting.notes', onNotesSaved);
    }
});

onBeforeUnmount(() => {
    clearInterval(ticker);
    leaving = true;
    void disconnect();

    if (meetingChannel) {
        getEcho()
            ?.private(meetingChannel)
            .stopListening('.meeting.ended', onEnded)
            .stopListening('.meeting.notes', onNotesSaved);
    }
});
</script>

<template>
    <Head :title="meeting.title" />

    <MeetingLobby
        v-if="phase === 'lobby'"
        :meeting="meeting"
        :me="me"
        :plan-name="planName"
        :meeting-minutes="meetingMinutes"
        @join="join"
    />

    <!-- The room closed -->
    <div
        v-else-if="ended"
        class="mx-auto grid w-full max-w-5xl items-start gap-8 p-4 md:p-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]"
    >
        <div class="space-y-5 rounded-2xl border bg-background p-6">
            <span
                class="flex size-12 items-center justify-center rounded-2xl"
                :class="
                    ended === 'limit'
                        ? 'bg-notice-subtle text-notice-strong'
                        : ended === 'lost'
                          ? 'bg-negative-subtle text-negative-strong'
                          : 'bg-informative-subtle text-informative-strong'
                "
            >
                <Hourglass v-if="ended === 'limit'" class="size-6" />
                <PhoneOff v-else class="size-6" />
            </span>
            <div class="space-y-1">
                <h1 class="text-xl font-bold text-heading">
                    {{ endedText.title }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ endedText.body }}
                </p>
            </div>
            <div
                v-if="ended === 'limit'"
                class="rounded-xl bg-muted p-4 text-sm"
            >
                <p class="font-semibold text-heading">
                    {{ t('Besoin de réunions plus longues ?') }}
                </p>
                <p class="mt-1 text-muted-foreground">
                    {{
                        t(
                            'Les offres supérieures permettent des réunions jusqu’à 24 h, avec plus de participants.',
                        )
                    }}
                </p>
                <Button v-if="canManageBilling" as-child size="sm" class="mt-3">
                    <a :href="billing().url">{{ t('Voir les offres') }}</a>
                </Button>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="ended === 'lost'" @click="rejoin">
                    <Video /> {{ t('Rejoindre à nouveau') }}
                </Button>
                <Button
                    :variant="ended === 'lost' ? 'outline' : 'default'"
                    as-child
                >
                    <a :href="index(slug).url">{{
                        t('Retour aux réunions')
                    }}</a>
                </Button>
            </div>
        </div>

        <section
            v-if="meeting.canEditNotes || notesState.notes"
            class="space-y-3 rounded-2xl border bg-background p-6"
        >
            <div>
                <h2 class="flex items-center gap-2 font-bold text-heading">
                    <NotebookPen class="size-4" /> {{ t('Compte rendu') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Notez les décisions et les actions : les participants les retrouvent dans la page Réunions.',
                        )
                    }}
                </p>
            </div>
            <MeetingNotes
                :slug="slug"
                :meeting-uuid="meeting.uuid"
                :meeting-title="meeting.title"
                :notes="notesState.notes"
                :updated-at="notesState.updatedAt"
                :updated-by="notesState.updatedBy"
                :can-edit="meeting.canEditNotes"
                :rows="12"
                @saved="onNotesSaved"
            />
        </section>
    </div>

    <div
        v-else
        class="flex h-[calc(100svh-4rem)] min-h-[32rem] flex-col bg-muted/50"
    >
        <!-- Top bar -->
        <header
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b bg-background px-4 py-3 md:px-6"
        >
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                >
                    <Video class="size-4" />
                </span>
                <div class="min-w-0">
                    <h1 class="truncate text-base font-bold text-heading">
                        {{ meeting.title }}
                    </h1>
                    <p
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span
                            v-if="elapsed"
                            class="font-mono tabular-nums"
                            :aria-label="t('Durée de la réunion')"
                            >{{ elapsed }}</span
                        >
                        <span v-if="elapsed">·</span>
                        <span>{{
                            t(':p0 / :p1 participants', {
                                p0: people.length,
                                p1: meeting.maxParticipants,
                            })
                        }}</span>
                        <span v-if="connecting" class="text-notice-strong">{{
                            t('· Connexion…')
                        }}</span>
                    </p>
                </div>
                <span
                    v-if="showCountdown"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-bold tabular-nums"
                    :class="
                        (remaining ?? 0) <= 120
                            ? 'bg-negative-subtle text-negative-strong'
                            : (remaining ?? 0) <= 600
                              ? 'bg-notice-subtle text-notice-strong'
                              : 'bg-muted text-muted-foreground'
                    "
                    :title="`Durée maximale de l’offre ${planName}`"
                >
                    <Hourglass class="size-3" />
                    {{ t('Fin dans :p0', { p0: remainingLabel }) }}
                </span>
                <span
                    v-if="recording"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-negative-subtle px-2 py-0.5 text-xs font-bold text-negative-strong"
                >
                    <Circle class="size-2.5 fill-current" />
                    {{ t('Enregistrement') }}
                </span>
            </div>

            <div class="flex items-center gap-1.5">
                <Button variant="ghost" size="sm" @click="copyLink">
                    <Link2 />
                    <span class="hidden sm:inline">{{
                        t('Copier le lien')
                    }}</span>
                </Button>
                <Button
                    :variant="panel === 'people' ? 'secondary' : 'ghost'"
                    size="sm"
                    :aria-pressed="panel === 'people'"
                    @click="togglePanel('people')"
                >
                    <Users />
                    <span class="tabular-nums">{{ people.length }}</span>
                </Button>
                <Button
                    :variant="panel === 'notes' ? 'secondary' : 'ghost'"
                    size="sm"
                    :aria-pressed="panel === 'notes'"
                    @click="togglePanel('notes')"
                >
                    <NotebookPen />
                    <span class="hidden sm:inline">{{ t('Notes') }}</span>
                </Button>
                <Button
                    :variant="panel === 'info' ? 'secondary' : 'ghost'"
                    size="icon-sm"
                    title="Informations"
                    :aria-label="t('Informations sur la réunion')"
                    :aria-pressed="panel === 'info'"
                    @click="togglePanel('info')"
                >
                    <Info />
                </Button>
            </div>
        </header>

        <div class="relative flex min-h-0 flex-1">
            <!-- Stage -->
            <main
                class="flex min-w-0 flex-1 flex-col gap-3 overflow-y-auto p-3 md:p-5"
            >
                <p
                    v-if="error"
                    class="flex flex-wrap items-center gap-2 rounded-lg border border-negative-border bg-negative-subtle px-3 py-2 text-sm text-negative-strong"
                    role="alert"
                >
                    {{ error }}
                    <Button
                        v-if="state === ConnectionState.Disconnected"
                        size="sm"
                        variant="outline"
                        @click="join()"
                        >{{ t('Réessayer') }}</Button
                    >
                </p>

                <button
                    v-if="audioBlocked"
                    type="button"
                    class="flex items-center gap-2 self-center rounded-full bg-notice px-4 py-2 text-sm font-semibold text-white"
                    @click="startAudio"
                >
                    <Volume2 class="size-4" />
                    {{ t('Activer le son de la réunion') }}
                </button>

                <div
                    class="grid flex-1 content-center gap-3"
                    :class="stage ? 'lg:grid-cols-[minmax(0,1fr)_15rem]' : ''"
                >
                    <ParticipantTile v-if="stage" :tile="stage" large />
                    <div class="grid content-center gap-3" :class="gridClass">
                        <ParticipantTile
                            v-for="tile in others"
                            :key="tile.key"
                            :tile="tile"
                            :large="!stage && others.length <= 2"
                        />
                    </div>
                </div>

                <p
                    v-if="
                        !connecting &&
                        state === ConnectionState.Connected &&
                        people.length === 1
                    "
                    class="text-center text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Vous êtes seul(e) pour le moment. Les invités ont reçu une notification avec le lien.',
                        )
                    }}
                    <button
                        type="button"
                        class="font-semibold text-primary hover:underline"
                        @click="inviteOpen = true"
                    >
                        {{ t('Inviter quelqu’un') }}
                    </button>
                </p>
            </main>

            <!-- Side panel -->
            <aside
                v-if="panel"
                class="absolute inset-y-0 right-0 z-10 flex w-full max-w-xs flex-col border-l bg-background shadow-lg lg:static lg:shadow-none"
                :aria-label="
                    panel === 'people'
                        ? 'Participants'
                        : panel === 'notes'
                          ? t('Notes de réunion')
                          : 'Informations'
                "
            >
                <div
                    class="flex items-center justify-between border-b px-4 py-3"
                >
                    <p class="font-bold text-heading">
                        {{
                            panel === 'people'
                                ? 'Participants'
                                : panel === 'notes'
                                  ? t('Notes de réunion')
                                  : 'Informations'
                        }}
                    </p>
                    <button
                        type="button"
                        class="rounded-md p-1 text-muted-foreground hover:bg-accent"
                        :aria-label="t('Fermer le panneau')"
                        @click="panel = null"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div
                    v-if="panel === 'people'"
                    class="flex-1 space-y-5 overflow-y-auto p-4"
                >
                    <div>
                        <p
                            class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{
                                t('Dans la réunion (:p0)', {
                                    p0: people.length,
                                })
                            }}
                        </p>
                        <ul class="space-y-1">
                            <li
                                v-for="tile in people"
                                :key="tile.key"
                                class="flex items-center gap-3 rounded-lg px-2 py-1.5"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                    :class="[
                                        avatarTone(tile.name),
                                        tile.isSpeaking
                                            ? 'ring-2 ring-primary'
                                            : '',
                                    ]"
                                    >{{ initials(tile.name) }}</span
                                >
                                <span
                                    class="min-w-0 flex-1 truncate text-sm font-medium"
                                >
                                    {{ tile.name
                                    }}<span
                                        v-if="tile.isLocal"
                                        class="text-muted-foreground"
                                    >
                                        {{ t('(vous)') }}</span
                                    >
                                </span>
                                <MicOff
                                    v-if="!tile.micEnabled"
                                    class="size-4 text-negative"
                                    :aria-label="t('Micro coupé')"
                                />
                                <Mic
                                    v-else
                                    class="size-4 text-muted-foreground"
                                />
                            </li>
                        </ul>
                    </div>

                    <div v-if="absent.length">
                        <p
                            class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{
                                t('Invités, pas encore là (:p0)', {
                                    p0: absent.length,
                                })
                            }}
                        </p>
                        <ul class="space-y-1">
                            <li
                                v-for="person in absent"
                                :key="person.id"
                                class="flex items-center gap-3 rounded-lg px-2 py-1.5 opacity-70"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                    :class="avatarTone(person.name)"
                                    >{{ initials(person.name) }}</span
                                >
                                <span class="truncate text-sm">{{
                                    person.name
                                }}</span>
                            </li>
                        </ul>
                    </div>

                    <Button
                        variant="outline"
                        class="w-full"
                        @click="inviteOpen = true"
                    >
                        <UserPlus /> {{ t('Inviter des participants') }}
                    </Button>
                </div>

                <div
                    v-else-if="panel === 'notes'"
                    class="flex min-h-0 flex-1 flex-col p-4"
                >
                    <MeetingNotes
                        :slug="slug"
                        :meeting-uuid="meeting.uuid"
                        :meeting-title="meeting.title"
                        :notes="notesState.notes"
                        :updated-at="notesState.updatedAt"
                        :updated-by="notesState.updatedBy"
                        :can-edit="meeting.canEditNotes"
                        @saved="onNotesSaved"
                    />
                </div>

                <div
                    v-else
                    class="flex-1 space-y-5 overflow-y-auto p-4 text-sm"
                >
                    <div v-if="schedule" class="space-y-1">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('Horaire') }}
                        </p>
                        <p>{{ schedule }}</p>
                    </div>
                    <div class="space-y-1">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('Organisateur') }}
                        </p>
                        <p>{{ meeting.creator ?? '—' }}</p>
                    </div>
                    <div v-if="meeting.description" class="space-y-1">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('Ordre du jour') }}
                        </p>
                        <p class="whitespace-pre-line text-muted-foreground">
                            {{ meeting.description }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('Lien de la réunion') }}
                        </p>
                        <div
                            class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2"
                        >
                            <span
                                class="min-w-0 flex-1 truncate font-mono text-xs"
                                >{{ meeting.url }}</span
                            >
                            <button
                                type="button"
                                class="rounded p-1 hover:bg-accent"
                                :aria-label="t('Copier le lien')"
                                @click="copyLink"
                            >
                                <Copy class="size-4" />
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('Réservé aux membres de l’équipe connectés.')
                            }}
                        </p>
                    </div>
                    <AddToCalendar
                        v-if="meeting.calendar"
                        :calendar="meeting.calendar"
                        label
                    />
                </div>
            </aside>
        </div>

        <!-- Controls -->
        <nav
            class="flex flex-wrap items-center justify-center gap-2 border-t bg-background px-3 py-3"
            :aria-label="t('Commandes de la réunion')"
        >
            <button
                type="button"
                class="control"
                :class="micEnabled ? 'control-on' : 'control-off'"
                :aria-pressed="!micEnabled"
                :title="
                    micEnabled ? t('Couper le micro') : t('Activer le micro')
                "
                @click="toggleMic"
            >
                <Mic v-if="micEnabled" class="size-5" />
                <MicOff v-else class="size-5" />
                <span class="control-label">{{ t('Micro') }}</span>
            </button>
            <button
                type="button"
                class="control"
                :class="cameraEnabled ? 'control-on' : 'control-off'"
                :aria-pressed="!cameraEnabled"
                :title="
                    cameraEnabled
                        ? t('Couper la caméra')
                        : t('Activer la caméra')
                "
                @click="toggleCamera"
            >
                <Video v-if="cameraEnabled" class="size-5" />
                <VideoOff v-else class="size-5" />
                <span class="control-label">{{ t('Caméra') }}</span>
            </button>
            <button
                type="button"
                class="control control-on hidden sm:flex"
                :class="{ 'control-active': screenEnabled }"
                :aria-pressed="screenEnabled"
                :title="
                    screenEnabled
                        ? t('Arrêter le partage')
                        : t('Partager l’écran')
                "
                @click="toggleScreen"
            >
                <MonitorUp class="size-5" />
                <span class="control-label">{{
                    screenEnabled ? t('Arrêter') : 'Partager'
                }}</span>
            </button>
            <button
                type="button"
                class="control control-on"
                :title="t('Inviter des participants')"
                @click="inviteOpen = true"
            >
                <UserPlus class="size-5" />
                <span class="control-label">{{ t('Inviter') }}</span>
            </button>
            <button
                v-if="canRecord"
                type="button"
                class="control control-on"
                :class="{ 'control-recording': recording }"
                :disabled="busy"
                :aria-pressed="recording"
                :title="
                    recording ? t('Arrêter l’enregistrement') : 'Enregistrer'
                "
                @click="toggleRecording"
            >
                <Circle
                    class="size-5"
                    :class="recording ? 'fill-current' : ''"
                />
                <span class="control-label">{{
                    recording ? t('Arrêter') : 'Enregistrer'
                }}</span>
            </button>

            <span class="mx-1 hidden h-8 w-px bg-border sm:block" />

            <div class="flex items-center">
                <button
                    type="button"
                    class="flex h-11 items-center gap-2 bg-destructive px-5 text-sm font-bold text-white transition-colors hover:bg-[var(--destructive-hover)]"
                    :class="canEnd ? 'rounded-l-full' : 'rounded-full'"
                    @click="leave"
                >
                    <LogOut class="size-4" /> {{ t('Quitter') }}
                </button>
                <DropdownMenu v-if="canEnd">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex h-11 items-center rounded-r-full border-l border-white/30 bg-destructive px-2.5 text-white hover:bg-[var(--destructive-hover)]"
                            :aria-label="t('Plus d’options pour quitter')"
                        >
                            <ChevronDown class="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" side="top" class="w-56">
                        <DropdownMenuItem @select="leave">
                            <LogOut /> {{ t('Quitter la réunion') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="text-negative-strong focus:text-negative-strong"
                            @select="endForAll"
                        >
                            <PhoneOff /> {{ t('Terminer pour tous') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </nav>

        <div ref="audioHost" class="hidden" aria-hidden="true" />
    </div>

    <Dialog v-model:open="inviteOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('Inviter à la réunion') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Les membres sélectionnés reçoivent une notification avec un bouton « Rejoindre ».',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <MemberPicker v-model="invitees" :members="members" />
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ t('Annuler') }}</Button>
                </DialogClose>
                <Button
                    :disabled="busy || invitees.length === 0"
                    @click="sendInvites"
                    >{{ t('Envoyer') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.control {
    display: flex;
    min-width: 4.25rem;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    border-radius: 0.875rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.6875rem;
    font-weight: 600;
    transition:
        background-color 0.15s ease,
        color 0.15s ease;
}

.control:disabled {
    opacity: 0.5;
}

.control-on {
    color: var(--foreground);
}

.control-on:hover {
    background-color: var(--accent);
}

.control-off {
    background-color: var(--negative-subtle);
    color: var(--negative-strong);
}

.control-active {
    background-color: var(--informative-subtle);
    color: var(--informative-strong);
}

.control-recording {
    color: var(--negative-strong);
}

.control-label {
    line-height: 1;
}
</style>
