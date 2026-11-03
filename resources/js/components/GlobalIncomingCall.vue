<script setup lang="ts">
import { t } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/vue3';
import axios, { isAxiosError } from 'axios';
import { toast } from 'vue-sonner';
import {
    Check,
    ChevronDown,
    Delete,
    Grid3x3,
    Mic,
    MicOff,
    Pause,
    Phone,
    PhoneIncoming,
    PhoneOff,
    PhoneOutgoing,
    Play,
    Search,
    UserRound,
    X,
} from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';

import CallWaveform from '@/components/calls/CallWaveform.vue';
import InboundCallContext from '@/components/InboundCallContext.vue';
import StatusLight from '@/components/StatusLight.vue';
import TransferMenu from '@/components/TransferMenu.vue';
import { Input } from '@/components/ui/input';

import { useAgentQueue } from '@/composables/useAgentQueue';
import { useAudioLevels } from '@/composables/useAudioLevels';
import { useInboundContext } from '@/composables/useInboundContext';
import type { InboundContext } from '@/composables/useInboundContext';
import { formatDuration as formatClock, formatRelative } from '@/lib/format';
import { store as storeOutcome } from '@/routes/call-outcomes';
import { active as activeAttempt, dispose } from '@/routes/campaigns/attempts';
import type { DispositionOutcomeValue } from '@/types';
import { useSoftphone } from '@/composables/useSoftphone';
import { internalExtension, useWebRTC } from '@/composables/useWebRTC';
import { dialTarget } from '@/lib/dialing';

const {
    callState,
    answerCall,
    rejectCall,
    hangup,
    toggleMute,
    toggleHold,
    dismissLastCall,
    makeCall,
    sendDTMF,
} = useWebRTC();

const {
    open: panelOpen,
    number: dialNumber,
    openDialer,
    closeDialer,
} = useSoftphone();

const { loadStatus, agentState, stateLabel, setAgentState, finishWrapUp } =
    useAgentQueue();

const page = usePage();

// Screen pop pushed while the phone rings (queue, IVR choice, history).
const inbound = useInboundContext();
inbound.listen();

const teamSlug = computed(
    () => (page.props.currentTeam as { slug?: string } | null)?.slug ?? null,
);

const outcomeOptions = [
    { value: 'resolved', label: t('Résolu') },
    { value: 'callback', label: t('Rappel planifié') },
    { value: 'voicemail', label: 'Messagerie' },
    { value: 'sale', label: 'Vente' },
    { value: 'no_sale', label: t('Pas de vente') },
    { value: 'wrong_number', label: t('Mauvais numéro') },
    { value: 'other', label: 'Autre' },
];

const outcome = ref('resolved');
const notes = ref('');
const saved = ref(false);
const error = ref('');

// The oldest ended call still waiting for its outcome; others queue behind it.
const wrapUp = computed(() => callState.lastEndedCall);
const pendingWrapUps = computed(() =>
    internalOnly.value
        ? 0
        : callState.endedCalls.filter((call) => !isInternalCall(call)).length,
);
// Call outcome / post-call work is call-center only: internal calls just end.
const internalOnly = computed(
    () => page.props.currentTeam?.usageMode === 'internal',
);
const showWrapUp = computed(
    () =>
        !internalOnly.value &&
        wrapUp.value !== null &&
        !isInternalCall(wrapUp.value) &&
        !saved.value,
);

/*
 * A call between colleagues (extension to extension) is not customer work:
 * no outcome, no post-call state. Recognised by the other party being one
 * of the team's extensions (shared by HandleInertiaRequests).
 */
const teamExtensions = computed(
    () =>
        new Set(
            ((page.props.teamExtensions as string[] | undefined) ?? []).map(
                String,
            ),
        ),
);

function isInternalCall(call: { caller: string }): boolean {
    // Service codes (*97 voicemail…) are never customer calls.
    if (call.caller.trim().startsWith('*')) {
        return true;
    }

    const extension = internalExtension(call.caller.trim());

    return (
        /^\d+$/.test(extension) &&
        (teamExtensions.value.has(extension) || extension !== call.caller.trim())
    );
}

const hasSip = computed(() => Boolean(page.props.sipCredentials));
const sipLabel = computed(
    () =>
        (page.props.sipCredentials as { username?: string } | null)?.username ??
        '---',
);
const isDialing = computed(
    () => callState.status === 'calling' || callState.status === 'connecting',
);
const isIdle = computed(
    () => !callState.inCall && !callState.incomingCall && !isDialing.value,
);

const keypadKeys = [
    { digit: '1', sub: '' },
    { digit: '2', sub: 'ABC' },
    { digit: '3', sub: 'DEF' },
    { digit: '4', sub: 'GHI' },
    { digit: '5', sub: 'JKL' },
    { digit: '6', sub: 'MNO' },
    { digit: '7', sub: 'PQRS' },
    { digit: '8', sub: 'TUV' },
    { digit: '9', sub: 'WXYZ' },
    { digit: '*', sub: '' },
    { digit: '0', sub: '+' },
    { digit: '#', sub: '' },
];

function pressKey(digit: string): void {
    if (callState.inCall) {
        sendDTMF(digit);

        return;
    }

    if (isIdle.value) {
        dialNumber.value += digit;
    }
}

function backspace(): void {
    if (isIdle.value) {
        dialNumber.value = dialNumber.value.slice(0, -1);
    }
}

function dial(): void {
    if (!dialNumber.value || !callState.registered || !isIdle.value) {
        return;
    }

    remember({
        number: dialNumber.value,
        name: recentMatch.value?.name ?? '',
        direction: 'outbound',
    });
    // Outside numbers leave as 00 + international (the team's outbound route).
    makeCall(
        dialTarget(dialNumber.value, teamExtensions.value, {
            countryCode: page.props.dialing?.countryCode ?? '',
            nationalLength: page.props.dialing?.nationalLength ?? 8,
        }),
    );
}

/*
 * Recent numbers for one-tap redial: kept in this browser only (a viewer
 * convenience; the call history page stays the record).
 */
type RecentCall = {
    number: string;
    name: string;
    direction: 'inbound' | 'outbound';
    at: string;
};

const RECENTS_KEY = 'softphone.recent';
const recents = ref<RecentCall[]>(readRecents());

function readRecents(): RecentCall[] {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(RECENTS_KEY) ?? '[]',
        );

        return Array.isArray(stored) ? stored.slice(0, 5) : [];
    } catch {
        return [];
    }
}

const tail = (value: string) => value.replace(/\D/g, '').slice(-8);

function remember(call: Omit<RecentCall, 'at'>): void {
    if (!call.number) {
        return;
    }

    const previous = recents.value.find(
        (item) => tail(item.number) === tail(call.number),
    );
    recents.value = [
        {
            ...call,
            name: call.name || previous?.name || '',
            at: new Date().toISOString(),
        },
        ...recents.value.filter(
            (item) => tail(item.number) !== tail(call.number),
        ),
    ].slice(0, 5);

    try {
        window.localStorage.setItem(RECENTS_KEY, JSON.stringify(recents.value));
    } catch {
        // Private mode or storage full: the list just isn't kept.
    }
}

/** A recent entry with the same number as the one typed (shows the name). */
const recentMatch = computed(() =>
    dialNumber.value.replace(/\D/g, '').length >= 6
        ? (recents.value.find(
              (item) => tail(item.number) === tail(dialNumber.value),
          ) ?? null)
        : null,
);

function callRecent(item: RecentCall): void {
    dialNumber.value = item.number;
    dial();
}

/*
 * Colleagues tab: every member with an extension, as in the internal-mode
 * directory, with the status they chose. Loaded when the tab opens.
 */
type Colleague = {
    id: number;
    name: string;
    avatar: string | null;
    jobTitle: string | null;
    department: string | null;
    extension: string;
    online: boolean;
    state: string | null;
    stateLabel: string | null;
};

/* Tell the agent why an outgoing call did not go through. */
watch(
    () => callState.callFailure,
    (failure) => {
        if (!failure) {
            return;
        }

        const messages: Record<number, string> = {
            486: t('Votre correspondant est déjà en ligne.'),
            600: t('Votre correspondant est déjà en ligne.'),
            480: t("Votre correspondant n'est pas connecté pour le moment."),
            404: t("Ce numéro n'existe pas."),
            603: t('Appel refusé.'),
        };

        toast.error(
            messages[failure.code] ??
                t("L'appel n'a pas abouti (code :p0).", { p0: failure.code }),
        );
    },
);

const listTab = ref<'recents' | 'colleagues'>('recents');
const colleagues = ref<Colleague[] | null>(null);
const colleaguesLoading = ref(false);
const colleaguesError = ref(false);
const colleagueSearch = ref('');

async function loadColleagues(): Promise<void> {
    if (!teamSlug.value || colleaguesLoading.value) {
        return;
    }

    colleaguesLoading.value = true;
    colleaguesError.value = false;

    try {
        const response = await axios.get<{ colleagues: Colleague[] }>(
            `/${teamSlug.value}/directory`,
            { headers: { Accept: 'application/json' } },
        );
        colleagues.value = response.data.colleagues;
    } catch (exception) {
        colleaguesError.value = true;
        console.warn('[Annuaire] Chargement impossible :', exception);
    } finally {
        colleaguesLoading.value = false;
    }
}

// Fresh statuses each time the tab is shown.
watch(listTab, (tab) => {
    if (tab === 'colleagues') {
        void loadColleagues();
    }
});

watch(panelOpen, (open) => {
    if (open && listTab.value === 'colleagues') {
        void loadColleagues();
    }
});

const filteredColleagues = computed(() => {
    const query = colleagueSearch.value.trim().toLowerCase();
    // Reachable colleagues first, then by name (server order).
    const list = [...(colleagues.value ?? [])].sort(
        (a, b) => Number(b.online) - Number(a.online),
    );

    if (!query) {
        return list;
    }

    return list.filter((c) =>
        [c.name, c.extension, c.jobTitle, c.department]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(query)),
    );
});

/** Dot colour of a colleague's status. */
function colleagueTone(c: Colleague): string {
    if (!c.online) {
        return 'bg-muted-foreground/40';
    }

    switch (c.state) {
        case 'ready':
            return 'bg-positive';
        case 'not_ready':
        case 'wrap_up':
            return 'bg-notice';
        case 'dnd':
            return 'bg-negative';
        default:
            return 'bg-muted-foreground/40';
    }
}

function callColleague(colleague: Colleague): void {
    if (!callState.registered || !isIdle.value) {
        return;
    }

    dialNumber.value = colleague.extension;
    remember({
        number: colleague.extension,
        name: colleague.name,
        direction: 'outbound',
    });
    makeCall(colleague.extension);
}

watch(
    () => callState.lastEndedCall,
    (call, previous) => {
        if (call && call !== previous) {
            remember({
                number: call.caller,
                name: call.name,
                direction: call.direction,
            });
        }
    },
);

// Live timer for active call
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 1000);

    if (hasSip.value) {
        loadStatus();
    }
});

onBeforeUnmount(() => clearInterval(timer));

function formatDuration(totalSeconds: number): string {
    return formatClock(totalSeconds);
}

const activeElapsed = computed(() =>
    callState.startedAt
        ? formatDuration(
              Math.max(0, Math.floor((now.value - callState.startedAt) / 1000)),
          )
        : '0:00',
);

const wrapUpDuration = computed(() =>
    wrapUp.value ? formatDuration(wrapUp.value.duration) : '',
);

/**
 * Post-call work: an answered call that ends puts a Prêt agent in
 * « Traitement post-appel » (paused in the queues) until the outcome is
 * saved or skipped. Agents in another state (Non prêt, Ne pas déranger…)
 * keep it.
 */
watch(
    () => callState.endedCalls.length,
    (count, previous) => {
        // Internal calls have no outcome: nothing waits.
        if (internalOnly.value && count > 0) {
            while (callState.endedCalls.length > 0) {
                dismissLastCall();
            }

            return;
        }

        // Same for a call with a colleague in a call-center team: it goes to
        // the recents, then leaves without asking for an outcome.
        const internalCalls = callState.endedCalls.filter(isInternalCall);

        if (internalCalls.length > 0) {
            for (const call of internalCalls) {
                remember({
                    number: call.caller,
                    name: call.name,
                    direction: call.direction,
                });
                dismissLastCall(call.id);
            }

            return;
        }

        if (
            count > (previous ?? 0) &&
            !internalOnly.value &&
            agentState.value === 'ready'
        ) {
            void setAgentState('wrap_up').catch((exception) =>
                console.warn('[Statut] Post-appel impossible :', exception),
            );
        }
    },
);

/** Back to Prêt only once every ended call has its outcome. */
function endWrapUp(): void {
    if (callState.endedCalls.length > 1) {
        return;
    }

    void finishWrapUp();
}

/** Dismiss this very call after the « saved » tick, even if others queued meanwhile. */
function dismissLater(id: number | undefined): void {
    setTimeout(() => dismiss(id), 1200);
}

/**
 * Campaign calls reach the softphone as incoming SIP calls (the dialer rings
 * the agent). When one ends, the card switches to the campaign's wrap-up
 * codes and qualifies the attempt, like the campaign workspace.
 */
type CampaignCall = {
    id: number;
    campaign: string;
    callbackList: boolean;
    contact: string;
    phone: string | null;
    dispositions: {
        id: number;
        label: string;
        outcome: DispositionOutcomeValue;
    }[];
};

const campaignCall = ref<CampaignCall | null>(null);
const lookingUp = ref(false);
const dispositionId = ref('');
const callbackAt = ref('');

const selectedDisposition = computed(() =>
    campaignCall.value?.dispositions.find(
        (d) => String(d.id) === dispositionId.value,
    ),
);

const directionLabel = computed(() => {
    if (campaignCall.value) {
        return campaignCall.value.callbackList
            ? t('Rappel · :p0', { p0: campaignCall.value.campaign })
            : t('Sortant · Campagne :p0', { p0: campaignCall.value.campaign });
    }

    return wrapUp.value?.direction === 'outbound' ? 'Sortant' : 'Entrant';
});

watch(
    () => callState.lastEndedCall,
    async (call, previous) => {
        if (!call || call === previous) {
            return;
        }

        campaignCall.value = null;
        dispositionId.value = '';
        callbackAt.value = '';

        // Internal-only organizations and calls between colleagues have no
        // campaign to match the call with.
        if (
            isInternalCall(call) ||
            call.direction !== 'inbound' ||
            !teamSlug.value ||
            page.props.currentTeam?.usageMode === 'internal'
        ) {
            return;
        }

        lookingUp.value = true;

        try {
            const response = await axios.get<{ attempt: CampaignCall | null }>(
                activeAttempt.url(teamSlug.value, {
                    query: {
                        number: call.caller,
                        since: new Date(call.startedAt).toISOString(),
                    },
                }),
                { headers: { Accept: 'application/json' } },
            );

            // Still the call on the card (not saved or skipped meanwhile).
            if (callState.lastEndedCall?.id === call.id) {
                campaignCall.value = response.data.attempt;
            }
        } catch (exception) {
            console.warn('[Campagne] Appel non identifié :', exception);
        } finally {
            lookingUp.value = false;
        }

        // The workspace has its own qualification form for its current call.
        if (campaignCall.value && page.component === 'campaigns/Workspace') {
            dismiss(call.id);
        }
    },
);

async function qualifyCampaignCall(): Promise<void> {
    const attempt = campaignCall.value;
    const current = wrapUp.value?.id;

    if (!attempt || !teamSlug.value || !selectedDisposition.value) {
        return;
    }

    error.value = '';

    try {
        await axios.post(
            dispose({ current_team: teamSlug.value, attempt: attempt.id }).url,
            {
                disposition_id: selectedDisposition.value.id,
                notes: notes.value || null,
                callback_at:
                    selectedDisposition.value.outcome === 'callback'
                        ? callbackAt.value || null
                        : null,
            },
            { headers: { Accept: 'application/json' } },
        );

        saved.value = true;
        endWrapUp();
        dismissLater(current);
    } catch (exception) {
        const errors = isAxiosError(exception)
            ? (exception.response?.data?.errors as
                  | Record<string, string[]>
                  | undefined)
            : undefined;

        error.value = errors
            ? Object.values(errors).flat()[0]
            : "Impossible de qualifier l'appel. Veuillez réessayer.";
    }
}

function skipOutcome(): void {
    endWrapUp();
    dismiss(wrapUp.value?.id);
}

function saveOutcome(): void {
    if (!wrapUp.value) return;

    const current = wrapUp.value.id;

    if (!teamSlug.value) {
        error.value = "Sélectionnez d'abord une équipe.";
        return;
    }

    error.value = '';

    router.post(
        storeOutcome.url(teamSlug.value),
        {
            caller: wrapUp.value.caller,
            direction: wrapUp.value.direction,
            started_at: new Date(wrapUp.value.startedAt).toISOString(),
            ended_at: new Date(wrapUp.value.endedAt).toISOString(),
            duration: wrapUp.value.duration,
            outcome: outcome.value,
            notes: notes.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved.value = true;
                endWrapUp();
                dismissLater(current);
            },
            onError: () => {
                error.value =
                    "Impossible d'enregistrer le résultat. Veuillez réessayer.";
            },
        },
    );
}

function dismiss(id?: number): void {
    dismissLastCall(id);
    campaignCall.value = null;
    dispositionId.value = '';
    callbackAt.value = '';
    outcome.value = 'resolved';
    notes.value = '';
    saved.value = false;
    error.value = '';
}

/*
 * One softphone card that follows the call, like the product tour:
 * ringing (Répondre / Refuser) → in call (timer, voice, controls) →
 * post-call (outcome chips) → back to the dialer. The screen pop stays
 * on the card for the whole call.
 */
type Phase = 'idle' | 'ringing' | 'dialing' | 'talking' | 'wrap' | 'saved';

const phase = computed<Phase>(() => {
    if (callState.incomingCall) {
        return 'ringing';
    }

    if (isDialing.value) {
        return 'dialing';
    }

    if (callState.inCall) {
        return 'talking';
    }

    if (saved.value) {
        return 'saved';
    }

    return showWrapUp.value ? 'wrap' : 'idle';
});

/** Minimised to the compact bar during a call (the card comes back for the next phase). */
const minimized = ref(false);
const keypadOpen = ref(false);

watch(phase, (next, previous) => {
    if (next !== previous) {
        minimized.value = false;
        keypadOpen.value = false;
    }
});

const showCard = computed(() =>
    phase.value === 'idle'
        ? panelOpen.value
        : !(phase.value === 'talking' && minimized.value),
);
const showActiveBar = computed(
    () => phase.value === 'talking' && minimized.value,
);

function collapse(): void {
    if (phase.value === 'idle') {
        closeDialer();

        return;
    }

    minimized.value = true;
}

/** The status light in the card header. */
const status = computed<{
    label: string;
    variant: 'positive' | 'notice' | 'negative' | 'informative' | 'neutral';
}>(() => {
    switch (phase.value) {
        case 'talking':
            return callState.onHold
                ? { label: t('En attente'), variant: 'notice' }
                : { label: t('En appel'), variant: 'informative' };
        case 'dialing':
            return { label: t('Appel sortant'), variant: 'informative' };
        case 'wrap':
        case 'saved':
            return { label: 'Post-appel', variant: 'notice' };
        default:
            break;
    }

    if (!callState.registered) {
        return callState.status === 'registering'
            ? { label: t('Connexion…'), variant: 'neutral' }
            : { label: t('Hors ligne'), variant: 'negative' };
    }

    if (agentState.value === 'ready') {
        return { label: 'Disponible', variant: 'positive' };
    }

    return {
        label: stateLabel.value,
        variant: agentState.value === 'dnd' ? 'negative' : 'notice',
    };
});

/** Screen pop of the current call, kept until the post-call work is done. */
const callContext = ref<InboundContext | null>(null);

watch(
    () => callState.incomingCall,
    (ringing) => {
        if (ringing) {
            callContext.value = inbound.contextFor(callState.remoteNumber);
        }
    },
);

watch(isDialing, (dialing) => {
    if (dialing) {
        callContext.value = null;
    }
});

// The pop may arrive a moment after the phone starts ringing.
watch(inbound.offer, () => {
    if (callState.incomingCall || callState.inCall) {
        callContext.value =
            inbound.contextFor(callState.remoteNumber) ?? callContext.value;
    }
});

const callerNumber = computed(() =>
    phase.value === 'wrap' || phase.value === 'saved'
        ? (campaignCall.value?.phone ?? wrapUp.value?.caller ?? '')
        : callState.remoteNumber || dialNumber.value,
);

/** Screen pop of each ended call, kept while it waits for its outcome. */
const endedContexts = reactive(new Map<number, InboundContext | null>());

watch(
    () => callState.endedCalls.length,
    (count, previous) => {
        if (count > (previous ?? 0)) {
            const latest = callState.endedCalls[count - 1];

            if (latest) {
                endedContexts.set(latest.id, callContext.value);
            }
        }

        // Forget the ones that left the queue.
        for (const id of endedContexts.keys()) {
            if (!callState.endedCalls.some((call) => call.id === id)) {
                endedContexts.delete(id);
            }
        }
    },
);

const isEnded = computed(
    () => phase.value === 'wrap' || phase.value === 'saved',
);

/** The live call's pop, or the one of the ended call on the card. */
const shownContext = computed(() =>
    isEnded.value
        ? (endedContexts.get(wrapUp.value?.id ?? -1) ?? null)
        : callContext.value,
);

/** Campaign details belong to the ended call being qualified only. */
const shownCampaign = computed(() =>
    isEnded.value ? campaignCall.value : null,
);

const callerName = computed(() => {
    const ended = isEnded.value;

    return (
        shownCampaign.value?.contact ||
        shownContext.value?.contact?.name ||
        (ended ? wrapUp.value?.name : callState.remoteName) ||
        (phase.value === 'dialing' ? 'Appel en cours…' : 'Numéro inconnu')
    );
});

const clock = computed(() => {
    switch (phase.value) {
        case 'talking':
            return activeElapsed.value;
        case 'wrap':
        case 'saved':
            return wrapUpDuration.value;
        default:
            return '';
    }
});

/** Rows for calls without a screen pop. */
const extraRows = computed(() => {
    if (shownCampaign.value) {
        return [{ label: 'Campagne', value: shownCampaign.value.campaign }];
    }

    const direction =
        phase.value === 'wrap' || phase.value === 'saved'
            ? wrapUp.value?.direction
            : callState.direction;

    return !shownContext.value && direction === 'outbound'
        ? [{ label: 'Sens', value: 'Appel sortant' }]
        : [];
});

// Live voice bars: the caller and the agent's microphone.
const voiceStreams = computed(() => [
    callState.remoteStream,
    (
        callState.session?.sessionDescriptionHandler as
            | { localMediaStream?: MediaStream }
            | undefined
    )?.localMediaStream,
]);
const voiceActive = computed(
    () => phase.value === 'talking' && !callState.onHold,
);
const { levels: voiceLevels } = useAudioLevels(voiceStreams, voiceActive);

function chooseOutcome(value: string): void {
    outcome.value = value;
}

function chooseDisposition(id: number): void {
    dispositionId.value = String(id);
}

const canSave = computed(
    () =>
        !saved.value &&
        !lookingUp.value &&
        !!teamSlug.value &&
        (campaignCall.value
            ? !!selectedDisposition.value &&
              (selectedDisposition.value.outcome !== 'callback' ||
                  !!callbackAt.value)
            : !!outcome.value),
);

function saveWrapUp(): void {
    if (campaignCall.value) {
        void qualifyCampaignCall();

        return;
    }

    saveOutcome();
}
</script>

<template>
    <Teleport to="body">
        <div
            class="pointer-events-none fixed inset-x-0 bottom-0 z-2 flex flex-col items-end gap-3 p-4 sm:p-6"
        >
            <!-- Softphone card: one card that follows the call -->
            <Transition name="toast">
                <section
                    v-if="showCard"
                    class="pointer-events-auto w-full max-w-sm overflow-hidden rounded-2xl border bg-popover text-foreground shadow-[var(--dialog-shadow)]"
                    aria-label="Softphone"
                    data-test="softphone"
                >
                    <header
                        class="flex items-center justify-between gap-2 border-b px-4 py-2.5"
                    >
                        <span class="text-xs font-bold text-heading">{{
                            t('Softphone')
                        }}</span>
                        <div class="flex items-center gap-2">
                            <StatusLight
                                size="s"
                                :variant="status.variant"
                                role="status"
                                >{{ status.label }}</StatusLight
                            >
                            <button
                                v-if="phase === 'idle' || phase === 'talking'"
                                type="button"
                                class="-mr-1 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                :title="
                                    phase === 'idle' ? 'Fermer' : t('Réduire')
                                "
                                @click="collapse"
                            >
                                <X v-if="phase === 'idle'" class="size-4" />
                                <ChevronDown v-else class="size-4" />
                                <span class="sr-only">{{
                                    phase === 'idle' ? 'Fermer' : t('Réduire')
                                }}</span>
                            </button>
                        </div>
                    </header>

                    <!-- Dialer: same layout as a call (number row, details box, round controls) -->
                    <div v-if="phase === 'idle'" class="mk-pop space-y-4 p-4">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-11 shrink-0 items-center justify-center rounded-full bg-informative-subtle text-informative-strong"
                            >
                                <UserRound
                                    v-if="recentMatch?.name"
                                    class="size-5"
                                />
                                <Phone v-else class="size-5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-bold text-heading"
                                >
                                    {{ recentMatch?.name || t('Nouvel appel') }}
                                </p>
                                <input
                                    v-model="dialNumber"
                                    v-focus
                                    type="text"
                                    inputmode="tel"
                                    autocomplete="off"
                                    :placeholder="t('Saisissez un numéro')"
                                    :aria-label="t('Numéro à appeler')"
                                    class="w-full bg-transparent text-xs text-muted-foreground tabular-nums outline-none placeholder:text-muted-foreground/70"
                                    :class="{
                                        'font-mono text-lg font-bold text-primary':
                                            dialNumber,
                                    }"
                                    @keyup.enter="dial"
                                />
                            </div>
                            <button
                                v-if="dialNumber"
                                type="button"
                                class="flex size-9 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                :title="
                                    t('Effacer (double-clic pour tout effacer)')
                                "
                                :aria-label="t('Effacer le dernier chiffre')"
                                @click="backspace"
                                @dblclick="dialNumber = ''"
                            >
                                <Delete class="size-5" />
                            </button>
                        </div>

                        <!-- Recent calls, in the screen-pop box style -->
                        <div class="rounded-lg bg-muted px-3 py-2.5 text-xs">
                            <div
                                class="mb-1.5 flex gap-3 font-semibold"
                                role="tablist"
                            >
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="listTab === 'recents'"
                                    :class="
                                        listTab === 'recents'
                                            ? 'text-foreground'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    data-test="softphone-tab-recents"
                                    @click="listTab = 'recents'"
                                >
                                    {{ t('Récents') }}
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="listTab === 'colleagues'"
                                    :class="
                                        listTab === 'colleagues'
                                            ? 'text-foreground'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    data-test="softphone-tab-colleagues"
                                    @click="listTab = 'colleagues'"
                                >
                                    {{ t('Collègues') }}
                                </button>
                            </div>

                            <!-- Colleagues: call an internal extension -->
                            <template v-if="listTab === 'colleagues'">
                                <label
                                    class="mb-1.5 flex items-center gap-1.5 rounded-md bg-background px-2 py-1"
                                >
                                    <Search
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                    <input
                                        v-model="colleagueSearch"
                                        type="search"
                                        :placeholder="t('Nom, poste, service…')"
                                        :aria-label="t('Chercher un collègue')"
                                        class="w-full bg-transparent outline-none placeholder:text-muted-foreground/70"
                                    />
                                </label>
                                <p
                                    v-if="colleaguesLoading && !colleagues"
                                    class="text-muted-foreground"
                                >
                                    {{ t('Chargement…') }}
                                </p>
                                <p
                                    v-else-if="colleaguesError"
                                    class="text-muted-foreground"
                                >
                                    {{ t("Impossible de charger l'annuaire.") }}
                                    <button
                                        type="button"
                                        class="underline"
                                        @click="loadColleagues"
                                    >
                                        {{ t('Réessayer') }}
                                    </button>
                                </p>
                                <ul
                                    v-else-if="filteredColleagues.length"
                                    class="-mx-1.5 max-h-48 space-y-0.5 overflow-y-auto"
                                    data-test="softphone-colleagues"
                                >
                                    <li
                                        v-for="c in filteredColleagues"
                                        :key="c.id"
                                        class="flex items-center gap-2 rounded-md px-1.5 py-1 hover:bg-background"
                                    >
                                        <button
                                            type="button"
                                            class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                            :title="
                                                t('Composer le :p0', {
                                                    p0: c.extension,
                                                })
                                            "
                                            @click="dialNumber = c.extension"
                                        >
                                            <span
                                                class="size-2 shrink-0 rounded-full"
                                                :class="colleagueTone(c)"
                                                :title="
                                                    c.stateLabel ??
                                                    t('Statut inconnu')
                                                "
                                            />
                                            <span
                                                class="min-w-0 flex-1 truncate"
                                            >
                                                <span
                                                    class="font-semibold text-foreground"
                                                    >{{ c.name }}</span
                                                >
                                                <span
                                                    v-if="
                                                        c.jobTitle ||
                                                        c.department
                                                    "
                                                    class="ml-1 text-muted-foreground"
                                                    >{{
                                                        c.jobTitle ||
                                                        c.department
                                                    }}</span
                                                >
                                            </span>
                                            <span
                                                class="shrink-0 font-mono text-muted-foreground tabular-nums"
                                                >{{ c.extension }}</span
                                            >
                                        </button>
                                        <button
                                            type="button"
                                            class="flex size-6 shrink-0 items-center justify-center rounded-full text-positive transition-colors hover:bg-positive-subtle disabled:opacity-40"
                                            :disabled="
                                                !callState.registered ||
                                                !c.online ||
                                                c.state === 'dnd'
                                            "
                                            :aria-label="
                                                t('Appeler :p0', {
                                                    p0: c.name,
                                                })
                                            "
                                            @click="callColleague(c)"
                                        >
                                            <Phone class="size-3.5" />
                                        </button>
                                    </li>
                                </ul>
                                <p v-else class="text-muted-foreground">
                                    {{
                                        colleagueSearch
                                            ? t('Aucun collègue trouvé.')
                                            : t(
                                                  "Aucun collègue n'a encore de poste.",
                                              )
                                    }}
                                </p>
                            </template>

                            <template v-else>
                                <ul
                                    v-if="recents.length"
                                    class="-mx-1.5 space-y-0.5"
                                >
                                    <li
                                        v-for="item in recents"
                                        :key="item.number"
                                        class="flex items-center gap-2 rounded-md px-1.5 py-1 hover:bg-background"
                                    >
                                        <button
                                            type="button"
                                            class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                            :title="`Composer ${item.number}`"
                                            @click="dialNumber = item.number"
                                        >
                                            <PhoneIncoming
                                                v-if="
                                                    item.direction === 'inbound'
                                                "
                                                class="size-3.5 shrink-0 text-positive"
                                                aria-label="Entrant"
                                            />
                                            <PhoneOutgoing
                                                v-else
                                                class="size-3.5 shrink-0 text-primary"
                                                aria-label="Sortant"
                                            />
                                            <span
                                                class="min-w-0 flex-1 truncate"
                                            >
                                                <span
                                                    class="font-semibold text-foreground"
                                                    >{{
                                                        item.name || item.number
                                                    }}</span
                                                >
                                                <span
                                                    v-if="item.name"
                                                    class="ml-1 text-muted-foreground tabular-nums"
                                                    >{{ item.number }}</span
                                                >
                                            </span>
                                            <span
                                                class="shrink-0 text-muted-foreground"
                                                >{{
                                                    formatRelative(item.at)
                                                }}</span
                                            >
                                        </button>
                                        <button
                                            type="button"
                                            class="flex size-6 shrink-0 items-center justify-center rounded-full text-positive transition-colors hover:bg-positive-subtle disabled:opacity-40"
                                            :disabled="!callState.registered"
                                            :aria-label="`Rappeler ${item.name || item.number}`"
                                            @click="callRecent(item)"
                                        >
                                            <Phone class="size-3.5" />
                                        </button>
                                    </li>
                                </ul>
                                <p v-else class="text-muted-foreground">
                                    {{
                                        t(
                                            'Vos derniers appels apparaîtront ici pour les rappeler en un clic.',
                                        )
                                    }}
                                </p>
                            </template>
                        </div>

                        <!-- Keypad: round keys like the call controls -->
                        <div class="grid grid-cols-3 gap-x-4 gap-y-2 px-3">
                            <button
                                v-for="key in keypadKeys"
                                :key="key.digit"
                                type="button"
                                class="flex h-11 flex-col items-center justify-center rounded-full border-2 border-[var(--btn-outline-border)] text-foreground transition hover:border-[var(--btn-outline-border-hover)] hover:bg-accent active:scale-95"
                                @click="pressKey(key.digit)"
                            >
                                <span
                                    class="text-base leading-none font-bold"
                                    >{{ key.digit }}</span
                                >
                                <span
                                    v-if="key.sub"
                                    class="mt-0.5 text-[8px] leading-none tracking-[0.15em] text-muted-foreground"
                                    >{{ key.sub }}</span
                                >
                            </button>
                        </div>

                        <button
                            type="button"
                            class="flex h-10 w-full items-center justify-center gap-1.5 rounded-full bg-positive text-sm font-bold text-white transition-colors hover:bg-positive-strong disabled:opacity-40"
                            :disabled="!callState.registered || !dialNumber"
                            @click="dial"
                        >
                            <Phone class="size-4" /> {{ t('Appeler') }}
                        </button>

                        <p
                            class="text-center text-[11px] text-muted-foreground"
                        >
                            {{
                                t('Poste :p0 · :p1', {
                                    p0: sipLabel,
                                    p1: !hasSip
                                        ? t('aucun poste attribué')
                                        : callState.registered
                                          ? t('connecté')
                                          : callState.status === 'registering'
                                            ? t('connexion…')
                                            : t('hors ligne'),
                                })
                            }}
                        </p>
                    </div>

                    <!-- A call: ringing, dialing, talking or post-call -->
                    <div v-else class="space-y-4 p-4">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-11 shrink-0 items-center justify-center rounded-full bg-informative-subtle text-informative-strong"
                                :class="{ 'mk-ring': phase === 'ringing' }"
                            >
                                <UserRound class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-bold text-heading"
                                >
                                    {{ callerName }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ callerNumber }}
                                </p>
                            </div>
                            <span
                                v-if="clock"
                                class="ml-auto font-mono text-lg font-bold text-primary tabular-nums"
                                :aria-label="t('Durée de l\'appel')"
                                >{{ clock }}</span
                            >
                        </div>

                        <InboundCallContext
                            :context="shownContext"
                            :extra="extraRows"
                        />

                        <!-- Earlier calls still waiting for their outcome -->
                        <p
                            v-if="
                                (phase === 'wrap' && pendingWrapUps > 1) ||
                                ((phase === 'ringing' ||
                                    phase === 'dialing' ||
                                    phase === 'talking') &&
                                    pendingWrapUps > 0)
                            "
                            class="rounded-md bg-notice-subtle px-2.5 py-1.5 text-xs text-notice-strong"
                            data-test="pending-wrap-ups"
                        >
                            {{
                                phase === 'wrap'
                                    ? t(
                                          'Appel 1 sur :n à qualifier : les suivants s’affichent après celui-ci.',
                                          { n: pendingWrapUps },
                                      )
                                    : t(
                                          ':n appel(s) précédent(s) à qualifier après celui-ci.',
                                          { n: pendingWrapUps },
                                      )
                            }}
                        </p>

                        <!-- Ringing -->
                        <div
                            v-if="phase === 'ringing'"
                            :key="'ringing'"
                            class="mk-pop space-y-2"
                        >
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="flex h-10 flex-1 items-center justify-center gap-1.5 rounded-full bg-positive text-sm font-bold text-white transition-colors hover:bg-positive-strong"
                                    @click="answerCall"
                                >
                                    <Phone class="size-4" /> {{ t('Répondre') }}
                                </button>
                                <button
                                    type="button"
                                    class="flex h-10 flex-1 items-center justify-center gap-1.5 rounded-full bg-destructive text-sm font-bold text-white transition-colors hover:bg-[var(--destructive-hover)]"
                                    @click="rejectCall"
                                >
                                    <PhoneOff class="size-4" />
                                    {{ t('Refuser') }}
                                </button>
                            </div>
                        </div>

                        <!-- Dialing -->
                        <div
                            v-else-if="phase === 'dialing'"
                            :key="'dialing'"
                            class="mk-pop space-y-3"
                        >
                            <CallWaveform class="mx-auto" :active="false" />
                            <button
                                type="button"
                                class="flex h-10 w-full items-center justify-center gap-1.5 rounded-full bg-destructive text-sm font-bold text-white transition-colors hover:bg-[var(--destructive-hover)]"
                                @click="hangup"
                            >
                                <PhoneOff class="size-4" /> {{ t('Annuler') }}
                            </button>
                        </div>

                        <!-- In call -->
                        <div
                            v-else-if="phase === 'talking'"
                            :key="'talking'"
                            class="mk-pop space-y-3"
                        >
                            <CallWaveform
                                class="mx-auto"
                                :active="!callState.onHold"
                                :levels="voiceLevels"
                            />

                            <div
                                v-if="keypadOpen"
                                class="grid grid-cols-3 gap-1.5"
                                :aria-label="t('Clavier (DTMF)')"
                            >
                                <button
                                    v-for="key in keypadKeys"
                                    :key="key.digit"
                                    type="button"
                                    class="h-9 rounded-lg bg-muted text-sm font-bold transition-transform hover:bg-accent active:scale-95"
                                    @click="pressKey(key.digit)"
                                >
                                    {{ key.digit }}
                                </button>
                            </div>

                            <div class="grid grid-cols-5 gap-1.5">
                                <button
                                    type="button"
                                    class="flex h-10 items-center justify-center rounded-full border-2 transition-colors"
                                    :class="
                                        callState.muted
                                            ? 'border-notice-border bg-notice-subtle text-notice-strong'
                                            : 'border-[var(--btn-outline-border)] text-foreground hover:bg-accent'
                                    "
                                    :title="
                                        callState.muted
                                            ? t('Réactiver le micro')
                                            : t('Couper le micro')
                                    "
                                    :aria-pressed="callState.muted"
                                    @click="toggleMute"
                                >
                                    <MicOff
                                        v-if="callState.muted"
                                        class="size-4"
                                    />
                                    <Mic v-else class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="flex h-10 items-center justify-center rounded-full border-2 transition-colors"
                                    :class="
                                        callState.onHold
                                            ? 'border-notice-border bg-notice-subtle text-notice-strong'
                                            : 'border-[var(--btn-outline-border)] text-foreground hover:bg-accent'
                                    "
                                    :title="
                                        callState.onHold
                                            ? 'Reprendre'
                                            : t('Mettre en attente')
                                    "
                                    :aria-pressed="callState.onHold"
                                    @click="toggleHold"
                                >
                                    <Play
                                        v-if="callState.onHold"
                                        class="size-4"
                                    />
                                    <Pause v-else class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="flex h-10 items-center justify-center rounded-full border-2 transition-colors"
                                    :class="
                                        keypadOpen
                                            ? 'border-primary/40 bg-informative-subtle text-informative-strong'
                                            : 'border-[var(--btn-outline-border)] text-foreground hover:bg-accent'
                                    "
                                    title="Clavier"
                                    :aria-pressed="keypadOpen"
                                    @click="keypadOpen = !keypadOpen"
                                >
                                    <Grid3x3 class="size-4" />
                                </button>
                                <TransferMenu round />
                                <button
                                    type="button"
                                    class="flex h-10 items-center justify-center rounded-full bg-destructive text-white transition-colors hover:bg-[var(--destructive-hover)]"
                                    title="Raccrocher"
                                    @click="hangup"
                                >
                                    <PhoneOff class="size-4" />
                                    <span class="sr-only">{{
                                        t('Raccrocher')
                                    }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Post-call: outcome -->
                        <div
                            v-else-if="phase === 'wrap'"
                            :key="'wrap'"
                            class="mk-pop space-y-3"
                        >
                            <div class="space-y-2">
                                <p
                                    class="text-xs font-semibold text-muted-foreground"
                                >
                                    {{
                                        campaignCall
                                            ? 'Qualification'
                                            : "Résultat de l'appel"
                                    }}
                                </p>
                                <p
                                    v-if="lookingUp"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ t('Recherche de la campagne…') }}
                                </p>
                                <div
                                    v-else
                                    class="flex flex-wrap gap-1.5"
                                    role="radiogroup"
                                    :aria-label="
                                        campaignCall
                                            ? t('Code de qualification')
                                            : t('Résultat de l\'appel')
                                    "
                                >
                                    <template v-if="campaignCall">
                                        <button
                                            v-for="d in campaignCall.dispositions"
                                            :key="d.id"
                                            type="button"
                                            role="radio"
                                            :aria-checked="
                                                dispositionId === String(d.id)
                                            "
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs transition-colors"
                                            :class="
                                                dispositionId === String(d.id)
                                                    ? 'bg-positive-subtle font-semibold text-positive-strong'
                                                    : 'bg-muted text-muted-foreground hover:bg-accent hover:text-foreground'
                                            "
                                            @click="chooseDisposition(d.id)"
                                        >
                                            <Check
                                                v-if="
                                                    dispositionId ===
                                                    String(d.id)
                                                "
                                                class="size-3"
                                            />
                                            {{ d.label }}
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button
                                            v-for="option in outcomeOptions"
                                            :key="option.value"
                                            type="button"
                                            role="radio"
                                            :aria-checked="
                                                outcome === option.value
                                            "
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs transition-colors"
                                            :class="
                                                outcome === option.value
                                                    ? 'bg-positive-subtle font-semibold text-positive-strong'
                                                    : 'bg-muted text-muted-foreground hover:bg-accent hover:text-foreground'
                                            "
                                            @click="chooseOutcome(option.value)"
                                        >
                                            <Check
                                                v-if="outcome === option.value"
                                                class="size-3"
                                            />
                                            {{ option.label }}
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <Input
                                v-if="
                                    selectedDisposition?.outcome === 'callback'
                                "
                                v-model="callbackAt"
                                type="datetime-local"
                                :aria-label="t('Rappeler le')"
                                class="h-9 bg-muted text-sm"
                            />

                            <textarea
                                v-model="notes"
                                rows="2"
                                :placeholder="t('Note (facultatif)')"
                                aria-label="Note"
                                class="w-full resize-none rounded-lg border-0 bg-muted px-3 py-2 text-sm placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            />

                            <p
                                v-if="error"
                                class="text-xs font-medium text-negative"
                            >
                                {{ error }}
                            </p>

                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="flex h-9 flex-1 items-center justify-center gap-1.5 rounded-full bg-primary text-sm font-bold text-primary-foreground transition-colors hover:bg-[var(--primary-hover)] disabled:opacity-40"
                                    :disabled="!canSave"
                                    @click="saveWrapUp"
                                >
                                    <Check class="size-4" /> {{ t('Valider') }}
                                </button>
                                <button
                                    type="button"
                                    class="h-9 rounded-full px-4 text-sm font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                                    @click="skipOutcome"
                                >
                                    {{ t('Passer') }}
                                </button>
                            </div>
                        </div>

                        <!-- Saved -->
                        <p
                            v-else
                            :key="'saved'"
                            class="mk-pop flex items-center gap-2 rounded-lg bg-positive-subtle px-3 py-2 text-sm font-medium text-positive-strong"
                            role="status"
                        >
                            <Check class="size-4" />
                            {{ t("Résultat de l'appel enregistré.") }}
                        </p>
                    </div>
                </section>
            </Transition>

            <!-- Minimised call -->
            <Transition name="toast">
                <button
                    v-if="showActiveBar"
                    type="button"
                    class="pointer-events-auto flex w-full max-w-sm items-center gap-3 rounded-2xl border bg-popover p-3 text-left text-foreground shadow-[var(--dialog-shadow)] hover:bg-accent/40"
                    :title="t('Afficher le softphone')"
                    @click="minimized = false"
                >
                    <span class="relative flex size-2.5 shrink-0">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-informative opacity-60"
                        />
                        <span
                            class="relative inline-flex size-2.5 rounded-full bg-informative"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm font-semibold text-heading"
                            >{{ callerName }}</span
                        >
                        <span class="block text-xs text-muted-foreground">
                            {{ status.label }}
                        </span>
                    </span>
                    <span
                        class="font-mono text-sm font-bold text-primary tabular-nums"
                        >{{ activeElapsed }}</span
                    >
                </button>
            </Transition>

            <!-- Launcher -->
            <button
                v-if="hasSip && !showCard && !showActiveBar"
                type="button"
                class="pointer-events-auto relative flex size-12 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-[var(--dialog-shadow)] transition-transform hover:bg-[var(--primary-hover)] active:scale-95"
                :title="t('Ouvrir le softphone')"
                data-tour="softphone"
                @click="openDialer()"
            >
                <Phone class="size-5" />
                <span
                    class="absolute top-0.5 right-0.5 size-3 rounded-full border-2 border-background"
                    :class="
                        callState.registered
                            ? 'bg-positive-border'
                            : 'bg-notice'
                    "
                />
                <span class="sr-only">{{ t('Ouvrir le softphone') }}</span>
            </button>
        </div>
    </Teleport>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition:
        opacity 0.25s ease,
        transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(14px) scale(0.97);
}

.toast-leave-active {
    position: absolute;
}
</style>
