import axios from 'axios';
import {
    ConnectionState,
    type LocalParticipant,
    type Participant,
    type RemoteTrack,
    Room,
    RoomEvent,
    Track,
    type TrackPublication,
    ScreenSharePresets,
    type VideoPreset,
    VideoPresets,
} from 'livekit-client';
import { onUnmounted, ref, shallowRef } from 'vue';

import type { StatePayload } from '@/composables/useAgentQueue';

export type VideoQuality = 'sd' | 'hd' | 'full_hd';

type VideoProfile = {
    camera: VideoPreset;
    /** Lower simulcast layers sent next to the camera's own resolution. */
    layers: VideoPreset[];
    screen: VideoPreset;
};

/**
 * Meeting video per plan (config/billing.php hd_video / full_hd_video):
 * standard 360p camera and 720p screen at 5 frames/s; HD 720p camera and
 * screen; Full HD 1080p camera and screen.
 */
const VIDEO_PROFILES: Record<VideoQuality, VideoProfile> = {
    sd: {
        camera: VideoPresets.h360,
        layers: [VideoPresets.h180],
        screen: ScreenSharePresets.h720fps5,
    },
    hd: {
        camera: VideoPresets.h720,
        layers: [VideoPresets.h180, VideoPresets.h360],
        screen: ScreenSharePresets.h720fps15,
    },
    full_hd: {
        camera: VideoPresets.h1080,
        layers: [VideoPresets.h360, VideoPresets.h720],
        screen: ScreenSharePresets.h1080fps15,
    },
};

export type MeetingTile = {
    key: string;
    identity: string;
    name: string;
    isLocal: boolean;
    isSpeaking: boolean;
    micEnabled: boolean;
    /** Camera or screen share shown in this tile (null = avatar). */
    video: TrackPublication | null;
    isScreen: boolean;
};

/** Choices made in the lobby before joining. */
export type JoinMedia = {
    audio?: boolean;
    video?: boolean;
    audioDeviceId?: string;
    videoDeviceId?: string;
};

/**
 * One LiveKit room for the meeting page.
 *
 * Bandwidth matters (mobile data, shared office links): video is captured
 * at 360p, published with simulcast (180p/360p layers), and remote video is
 * only received at the size it is displayed (adaptiveStream) and paused
 * when nobody watches it (dynacast).
 */
export function useMeetingRoom() {
    const room = shallowRef<Room | null>(null);
    const tiles = shallowRef<MeetingTile[]>([]);
    const state = ref<ConnectionState>(ConnectionState.Disconnected);
    const error = ref('');
    /** Capture and publish settings of the team's plan (set when joining). */
    const videoProfile = shallowRef<VideoProfile>(VIDEO_PROFILES.sd);
    const micEnabled = ref(false);
    const cameraEnabled = ref(false);
    const screenEnabled = ref(false);
    const audioBlocked = ref(false);
    /** The agent's status after joining (Non prêt – Réunion vidéo), null when the user takes no calls. */
    const agentState = shallowRef<StatePayload | null>(null);
    const audioElements = new Map<string, HTMLMediaElement[]>();
    let audioHost: HTMLElement | null = null;

    function tilesFor(
        participant: Participant,
        isLocal: boolean,
    ): MeetingTile[] {
        const base = {
            identity: participant.identity,
            name: participant.name || participant.identity,
            isLocal,
            isSpeaking: participant.isSpeaking,
            micEnabled: participant.isMicrophoneEnabled,
        };
        const camera = participant.getTrackPublication(Track.Source.Camera);
        const screen = participant.getTrackPublication(
            Track.Source.ScreenShare,
        );
        const result: MeetingTile[] = [
            {
                ...base,
                key: `${participant.identity}:camera`,
                video:
                    camera && !camera.isMuted && camera.track ? camera : null,
                isScreen: false,
            },
        ];

        if (screen?.track) {
            result.unshift({
                ...base,
                key: `${participant.identity}:screen`,
                video: screen,
                isScreen: true,
            });
        }

        return result;
    }

    function refresh() {
        const current = room.value;

        if (!current) {
            tiles.value = [];

            return;
        }

        const local = current.localParticipant as LocalParticipant;

        micEnabled.value = local.isMicrophoneEnabled;
        cameraEnabled.value = local.isCameraEnabled;
        screenEnabled.value = local.isScreenShareEnabled;

        tiles.value = [
            ...tilesFor(local, true),
            ...Array.from(current.remoteParticipants.values()).flatMap((p) =>
                tilesFor(p, false),
            ),
        ];
    }

    function attachAudio(track: RemoteTrack, participant: Participant) {
        if (track.kind !== Track.Kind.Audio || !audioHost) {
            return;
        }

        const element = track.attach();
        element.dataset.participant = participant.identity;
        audioHost.appendChild(element);

        const key = track.sid ?? participant.identity;
        audioElements.set(key, [...(audioElements.get(key) ?? []), element]);
    }

    function detachAudio(track: RemoteTrack) {
        if (track.kind !== Track.Kind.Audio) {
            return;
        }

        track.detach().forEach((element) => element.remove());

        if (track.sid) {
            audioElements.delete(track.sid);
        }
    }

    /**
     * Why the browser cannot reach the video server, in words an admin can
     * act on. A secure page (https) may only open wss://, and a private
     * address (192.168.x, 10.x…) is unreachable from a phone on mobile data.
     */
    function serverUrlProblem(url: string): string | null {
        let parsed: URL;

        try {
            parsed = new URL(url);
        } catch {
            return 'Adresse du serveur vidéo invalide (LIVEKIT_URL).';
        }

        if (
            window.location.protocol === 'https:' &&
            parsed.protocol === 'wss:'
        ) {
            return 'Le serveur vidéo est configuré en ws:// alors que l’application est en https : le navigateur bloque la connexion. LIVEKIT_URL doit être une adresse wss:// publique.';
        }

        const isPrivate = (hostname: string) =>
            /^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|localhost$)/.test(
                hostname,
            );

        if (
            isPrivate(parsed.hostname) &&
            !isPrivate(window.location.hostname)
        ) {
            return `Le serveur vidéo (${parsed.hostname}) n’est joignable que sur le réseau local. LIVEKIT_URL doit être une adresse wss:// publique.`;
        }

        return null;
    }

    async function connect(
        tokenUrl: string,
        host: HTMLElement,
        joinedUrl?: string,
        media: JoinMedia = {},
    ) {
        error.value = '';
        audioHost = host;

        let credentials: {
            url: string;
            token: string;
            /** Video quality of the plan: sd, hd (720p) or full_hd (1080p). */
            quality?: VideoQuality;
        };

        try {
            credentials = (await axios.post(tokenUrl)).data;
        } catch (exception) {
            const message = axios.isAxiosError(exception)
                ? (exception.response?.data?.message as string | undefined)
                : undefined;
            error.value =
                message || "Impossible d'obtenir l'accès à la réunion.";

            return;
        }

        const problem = serverUrlProblem(credentials.url);

        if (problem) {
            error.value = problem;
            console.warn('[Réunion]', problem, credentials.url);

            return;
        }

        // Viewers still get the simulcast layer that fits their tile and bandwidth.
        const profile =
            VIDEO_PROFILES[credentials.quality ?? 'sd'] ?? VIDEO_PROFILES.sd;
        videoProfile.value = profile;

        const current = new Room({
            adaptiveStream: true,
            dynacast: true,
            videoCaptureDefaults: {
                resolution: profile.camera.resolution,
            },
            publishDefaults: {
                simulcast: true,
                videoEncoding: profile.camera.encoding,
                videoSimulcastLayers: profile.layers,
                screenShareEncoding: profile.screen.encoding,
            },
        });

        current
            .on(RoomEvent.ConnectionStateChanged, (next) => {
                state.value = next;
            })
            .on(RoomEvent.ParticipantConnected, refresh)
            .on(RoomEvent.ParticipantDisconnected, refresh)
            .on(RoomEvent.TrackSubscribed, (track, _pub, participant) => {
                attachAudio(track, participant);
                refresh();
            })
            .on(RoomEvent.TrackUnsubscribed, (track) => {
                detachAudio(track);
                refresh();
            })
            .on(RoomEvent.TrackMuted, refresh)
            .on(RoomEvent.TrackUnmuted, refresh)
            .on(RoomEvent.LocalTrackPublished, refresh)
            .on(RoomEvent.LocalTrackUnpublished, refresh)
            .on(RoomEvent.ActiveSpeakersChanged, refresh)
            .on(RoomEvent.AudioPlaybackStatusChanged, () => {
                audioBlocked.value = !current.canPlaybackAudio;
            })
            .on(RoomEvent.Disconnected, () => {
                state.value = ConnectionState.Disconnected;
                refresh();
            });

        room.value = current;

        try {
            await current.connect(credentials.url, credentials.token);
        } catch {
            error.value =
                'Connexion au serveur vidéo impossible. Vérifiez votre connexion internet.';
            room.value = null;

            return;
        }

        // What the lobby chose. A missing or refused camera never blocks the microphone.
        const {
            audio = true,
            video = true,
            audioDeviceId,
            videoDeviceId,
        } = media;

        if (video) {
            try {
                await current.localParticipant.setCameraEnabled(
                    true,
                    videoDeviceId ? { deviceId: videoDeviceId } : undefined,
                );
            } catch {
                error.value =
                    'Caméra indisponible : vous participez avec le micro seul.';
            }
        }

        try {
            await current.localParticipant.setMicrophoneEnabled(
                audio,
                audioDeviceId ? { deviceId: audioDeviceId } : undefined,
            );
        } catch {
            error.value =
                "Micro indisponible : autorisez l'accès dans votre navigateur.";
        }

        audioBlocked.value = !current.canPlaybackAudio;
        refresh();

        // Connected: now the agent goes Non prêt – Réunion vidéo (not before).
        if (joinedUrl) {
            try {
                agentState.value =
                    (
                        await axios.post<{ agentState: StatePayload | null }>(
                            joinedUrl,
                        )
                    ).data.agentState ?? null;
            } catch (exception) {
                console.warn(
                    '[Réunion] Statut agent non mis à jour :',
                    exception,
                );
            }
        }
    }

    async function toggleMic() {
        await room.value?.localParticipant.setMicrophoneEnabled(
            !micEnabled.value,
        );
        refresh();
    }

    async function toggleCamera() {
        try {
            await room.value?.localParticipant.setCameraEnabled(
                !cameraEnabled.value,
            );
        } catch {
            error.value = 'Caméra indisponible.';
        }
        refresh();
    }

    async function toggleScreen() {
        try {
            await room.value?.localParticipant.setScreenShareEnabled(
                !screenEnabled.value,
                {
                    audio: false,
                    resolution: videoProfile.value.screen.resolution,
                },
            );
        } catch {
            // The user cancelled the screen picker.
        }
        refresh();
    }

    async function startAudio() {
        await room.value?.startAudio();
        audioBlocked.value = !(room.value?.canPlaybackAudio ?? true);
    }

    async function disconnect() {
        await room.value?.disconnect();
        audioElements.forEach((elements) =>
            elements.forEach((element) => element.remove()),
        );
        audioElements.clear();
        room.value = null;
        refresh();
    }

    onUnmounted(() => {
        void disconnect();
    });

    return {
        room,
        tiles,
        state,
        error,
        videoProfile,
        micEnabled,
        cameraEnabled,
        screenEnabled,
        audioBlocked,
        agentState,
        connect,
        disconnect,
        toggleMic,
        toggleCamera,
        toggleScreen,
        startAudio,
    };
}
