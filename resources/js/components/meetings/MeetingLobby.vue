<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import {
    CalendarClock,
    Hourglass,
    Mic,
    MicOff,
    Users,
    Video,
    VideoOff,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ParticipantAvatars from '@/components/meetings/ParticipantAvatars.vue';
import { Button } from '@/components/ui/button';
import type { JoinMedia } from '@/composables/useMeetingRoom';
import { meetingDurationText } from '@/data/plans';
import { avatarTone, initials } from '@/lib/avatar';
import type { MeetingRow } from '@/types';

/**
 * Waiting room before joining: camera preview, microphone level, device
 * choice and who is already in. Nothing is sent to the meeting until
 * "Rejoindre" is pressed.
 */
const props = defineProps<{
    meeting: MeetingRow;
    me: { id: number; name: string };
    joinEarlyMinutes?: number;
    planName?: string;
    meetingMinutes?: number | null;
}>();

const emit = defineEmits<{ join: [media: JoinMedia] }>();

const preview = ref<HTMLVideoElement | null>(null);
const stream = ref<MediaStream | null>(null);
const audioOn = ref(true);
const videoOn = ref(true);
const cameras = ref<MediaDeviceInfo[]>([]);
const microphones = ref<MediaDeviceInfo[]>([]);
const cameraId = ref('');
const microphoneId = ref('');
const permissionError = ref('');
const level = ref(0);

let audioContext: AudioContext | null = null;
let frame = 0;

function stopStream() {
    stream.value?.getTracks().forEach((track) => track.stop());
    stream.value = null;
    cancelAnimationFrame(frame);
    void audioContext?.close().catch(() => undefined);
    audioContext = null;
    level.value = 0;
}

function meter(source: MediaStream) {
    if (source.getAudioTracks().length === 0) {
        return;
    }

    try {
        audioContext = new AudioContext();
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 256;
        audioContext.createMediaStreamSource(source).connect(analyser);
        const data = new Uint8Array(analyser.frequencyBinCount);

        const tick = () => {
            analyser.getByteFrequencyData(data);
            const average =
                data.reduce((sum, value) => sum + value, 0) / data.length;
            level.value = audioOn.value ? Math.min(1, average / 60) : 0;
            frame = requestAnimationFrame(tick);
        };

        tick();
    } catch {
        // No meter: the preview still works.
    }
}

async function start() {
    stopStream();
    permissionError.value = '';

    if (!navigator.mediaDevices?.getUserMedia) {
        permissionError.value =
            'Ce navigateur ne permet pas la vidéo. Utilisez Chrome, Edge, Firefox ou Safari récent, en https.';

        return;
    }

    try {
        stream.value = await navigator.mediaDevices.getUserMedia({
            video: videoOn.value
                ? cameraId.value
                    ? { deviceId: { exact: cameraId.value } }
                    : { width: 640, height: 360 }
                : false,
            audio: microphoneId.value
                ? { deviceId: { exact: microphoneId.value } }
                : true,
        });
    } catch {
        // Camera refused or missing: try the microphone alone.
        try {
            stream.value = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            videoOn.value = false;
            permissionError.value =
                'Caméra indisponible ou refusée : vous pouvez rejoindre avec le micro seul.';
        } catch {
            permissionError.value =
                'Accès au micro et à la caméra refusé. Autorisez-les dans la barre d’adresse du navigateur, puis réessayez.';

            return;
        }
    }

    stream.value
        .getAudioTracks()
        .forEach((track) => (track.enabled = audioOn.value));

    if (preview.value) {
        preview.value.srcObject = stream.value;
    }

    meter(stream.value);

    const devices = await navigator.mediaDevices.enumerateDevices();
    cameras.value = devices.filter(
        (device) => device.kind === 'videoinput' && device.deviceId,
    );
    microphones.value = devices.filter(
        (device) => device.kind === 'audioinput' && device.deviceId,
    );
    cameraId.value ||=
        stream.value.getVideoTracks()[0]?.getSettings().deviceId ?? '';
    microphoneId.value ||=
        stream.value.getAudioTracks()[0]?.getSettings().deviceId ?? '';
}

function toggleAudio() {
    audioOn.value = !audioOn.value;
    stream.value
        ?.getAudioTracks()
        .forEach((track) => (track.enabled = audioOn.value));
}

function toggleVideo() {
    videoOn.value = !videoOn.value;
    void start();
}

watch([cameraId, microphoneId], (next, previous) => {
    if (previous.some(Boolean) && next.join() !== previous.join()) {
        void start();
    }
});

function join() {
    const media: JoinMedia = {
        audio: audioOn.value,
        video: videoOn.value,
        audioDeviceId: microphoneId.value || undefined,
        videoDeviceId: videoOn.value ? cameraId.value || undefined : undefined,
    };

    // Release the devices: the meeting opens its own tracks.
    stopStream();
    emit('join', media);
}

// My own earlier visit does not count as "already here".
const inRoom = computed(() =>
    props.meeting.participants.filter((p) => p.joined && p.id !== props.me.id),
);

const schedule = computed(() => {
    const start = props.meeting.scheduledAt
        ? new Date(props.meeting.scheduledAt)
        : null;

    if (!start || props.meeting.status !== 'upcoming') {
        return null;
    }

    const format = new Intl.DateTimeFormat(intlLocale(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        hour: '2-digit',
        minute: '2-digit',
    });
    const early =
        start.getTime() - Date.now() > (props.joinEarlyMinutes ?? 10) * 60_000;

    return { label: format.format(start), early };
});

onMounted(start);
onBeforeUnmount(stopStream);
</script>

<template>
    <div
        class="mx-auto grid w-full max-w-5xl items-center gap-8 p-4 md:p-8 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]"
    >
        <!-- Preview -->
        <div class="space-y-3">
            <div
                class="relative aspect-video overflow-hidden rounded-2xl bg-neutral-900 shadow-lg"
            >
                <video
                    v-show="videoOn && stream"
                    ref="preview"
                    autoplay
                    muted
                    playsinline
                    class="size-full -scale-x-100 object-cover"
                />
                <div
                    v-if="!videoOn || !stream"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-white/80"
                >
                    <span
                        class="flex size-20 items-center justify-center rounded-full text-2xl font-bold"
                        :class="avatarTone(me.name)"
                        >{{ initials(me.name) }}</span
                    >
                    <span class="text-sm">{{
                        stream
                            ? t('Caméra désactivée')
                            : t('Démarrage de la caméra…')
                    }}</span>
                </div>

                <div
                    class="absolute inset-x-0 bottom-0 flex items-center justify-center gap-3 p-4"
                >
                    <button
                        type="button"
                        class="flex size-12 items-center justify-center rounded-full transition-colors"
                        :class="
                            audioOn
                                ? 'bg-white/20 text-white backdrop-blur hover:bg-white/30'
                                : 'bg-destructive text-white'
                        "
                        :aria-pressed="!audioOn"
                        :title="
                            audioOn
                                ? t('Couper le micro')
                                : t('Activer le micro')
                        "
                        @click="toggleAudio"
                    >
                        <Mic v-if="audioOn" class="size-5" />
                        <MicOff v-else class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="flex size-12 items-center justify-center rounded-full transition-colors"
                        :class="
                            videoOn
                                ? 'bg-white/20 text-white backdrop-blur hover:bg-white/30'
                                : 'bg-destructive text-white'
                        "
                        :aria-pressed="!videoOn"
                        :title="
                            videoOn
                                ? t('Couper la caméra')
                                : t('Activer la caméra')
                        "
                        @click="toggleVideo"
                    >
                        <Video v-if="videoOn" class="size-5" />
                        <VideoOff v-else class="size-5" />
                    </button>
                </div>

                <!-- Microphone level -->
                <div
                    v-if="audioOn && stream"
                    class="absolute top-3 left-3 flex h-6 items-end gap-0.5 rounded-full bg-black/40 px-2 py-1"
                    aria-hidden="true"
                >
                    <span
                        v-for="bar in 5"
                        :key="bar"
                        class="w-1 rounded-full bg-white transition-[height] duration-75"
                        :style="{
                            height: `${Math.max(15, Math.min(100, level * 100 * (1.4 - bar * 0.15)))}%`,
                            opacity: level * 5 >= bar - 0.5 ? 1 : 0.35,
                        }"
                    />
                </div>
            </div>

            <p
                v-if="permissionError"
                class="rounded-lg border border-notice-border bg-notice-subtle px-3 py-2 text-sm text-notice-strong"
                role="alert"
            >
                {{ permissionError }}
                <button
                    type="button"
                    class="ml-1 font-semibold underline"
                    @click="start"
                >
                    {{ t('Réessayer') }}
                </button>
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-xs text-muted-foreground">
                    {{ t('Micro') }}
                    <select
                        v-model="microphoneId"
                        class="h-9 rounded-[var(--control-radius)] border border-input bg-[var(--field-bg)] px-2 text-sm text-foreground"
                        :disabled="microphones.length === 0"
                    >
                        <option v-if="microphones.length === 0" value="">
                            {{ t('Micro par défaut') }}
                        </option>
                        <option
                            v-for="(device, index) in microphones"
                            :key="device.deviceId"
                            :value="device.deviceId"
                        >
                            {{ device.label || `Micro ${index + 1}` }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs text-muted-foreground">
                    {{ t('Caméra') }}
                    <select
                        v-model="cameraId"
                        class="h-9 rounded-[var(--control-radius)] border border-input bg-[var(--field-bg)] px-2 text-sm text-foreground"
                        :disabled="cameras.length === 0 || !videoOn"
                    >
                        <option v-if="cameras.length === 0" value="">
                            {{ t('Caméra par défaut') }}
                        </option>
                        <option
                            v-for="(device, index) in cameras"
                            :key="device.deviceId"
                            :value="device.deviceId"
                        >
                            {{ device.label || `Caméra ${index + 1}` }}
                        </option>
                    </select>
                </label>
            </div>
        </div>

        <!-- Meeting -->
        <div class="space-y-6">
            <div class="space-y-2">
                <p class="text-sm font-medium text-primary">
                    {{
                        meeting.status === 'live'
                            ? t('Réunion en cours')
                            : t('Prêt à rejoindre ?')
                    }}
                </p>
                <h1 class="text-2xl font-bold tracking-tight text-heading">
                    {{ meeting.title }}
                </h1>
                <p
                    v-if="schedule"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <CalendarClock class="size-4" />
                    {{
                        schedule.label.charAt(0).toUpperCase() +
                        schedule.label.slice(1)
                    }}
                </p>
                <p
                    v-if="meeting.description"
                    class="text-sm whitespace-pre-line text-muted-foreground"
                >
                    {{ meeting.description }}
                </p>
            </div>

            <div class="flex items-center gap-3 rounded-xl bg-muted px-4 py-3">
                <Users class="size-4 shrink-0 text-muted-foreground" />
                <template v-if="inRoom.length">
                    <ParticipantAvatars :people="inRoom" />
                    <p class="text-sm">
                        <span class="font-semibold">{{ inRoom[0].name }}</span>
                        <template v-if="inRoom.length > 1">
                            {{
                                t('et :p0 autre(s)', { p0: inRoom.length - 1 })
                            }}</template
                        >
                        {{
                            inRoom.length > 1
                                ? t('ont rejoint la réunion')
                                : t('a rejoint la réunion')
                        }}
                    </p>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('Personne n’est encore dans la réunion.') }}
                </p>
            </div>

            <p v-if="schedule?.early" class="text-sm text-muted-foreground">
                {{
                    t(
                        'La réunion n’a pas encore commencé : vous pouvez entrer en avance, les participants seront prévenus de votre arrivée.',
                    )
                }}
            </p>

            <p
                v-if="meetingMinutes"
                class="flex items-center gap-2 text-xs text-muted-foreground"
            >
                <Hourglass class="size-3.5" />
                Durée maximale :
                {{ meetingDurationText(meetingMinutes) }} (offre
                {{ planName }}). Un compte à rebours s’affiche avant la fin.
            </p>

            <Button
                size="lg"
                class="w-full sm:w-auto"
                data-test="lobby-join"
                @click="join"
            >
                <Video /> {{ t('Rejoindre la réunion') }}
            </Button>
        </div>
    </div>
</template>
