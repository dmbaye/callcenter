<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

import { useAgentQueue } from '@/composables/useAgentQueue';
import { useWebRTC } from '@/composables/useWebRTC';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import GlobalIncomingCall from '@/components/GlobalIncomingCall.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import CampaignCallListener from '@/components/campaigns/CampaignCallListener.vue';
import AppTour from '@/components/AppTour.vue';
import { useAppTour } from '@/composables/useAppTour';

import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const { callState, disconnect, initSip, rejectCall } = useWebRTC();
const { agentState, joinQueues, leaveQueues } = useAgentQueue();
const page = usePage();

/**
 * Global remote audio element.
 *
 * This must live in this layout rather than Dialer.vue so that
 * remote audio continues working when the user navigates to another page.
 */
const remoteAudio = ref<HTMLAudioElement | null>(null);

/**
 * Global incoming-call ringtone.
 */
const ringtone = new Audio('/sounds/ringtone.mp3');

ringtone.loop = true;
ringtone.preload = 'auto';
ringtone.volume = 0.8;

/**
 * Attach the WebRTC remote stream to the global audio element.
 */
watch(
    () => callState.remoteStream,
    async (stream) => {
        if (!remoteAudio.value) {
            return;
        }

        if (!stream) {
            remoteAudio.value.srcObject = null;
            return;
        }

        remoteAudio.value.srcObject = stream;

        try {
            await remoteAudio.value.play();
        } catch (error) {
            console.warn('[SIP] Remote audio playback failed:', error);
        }
    },
);

/**
 * Start/stop the ringtone when an incoming call arrives.
 */
watch(
    () => callState.incomingCall,
    async (incoming) => {
        // Ne pas déranger: queue calls already skip the agent (paused); direct calls are declined.
        if (incoming && agentState.value === 'dnd') {
            void rejectCall();

            return;
        }

        if (incoming) {
            try {
                ringtone.currentTime = 0;
                await ringtone.play();
            } catch (error) {
                console.warn('[SIP] Ringtone playback failed:', error);
            }

            return;
        }

        ringtone.pause();
        ringtone.currentTime = 0;
    },
);

/**
 * SIP credentials are shared on every page. Re-initialize only when the
 * credential set really changes (team switch, new secret) — comparing by value,
 * because Inertia hands us a fresh object on each navigation and reconnecting
 * would drop an active call.
 */
const credentialsKey = computed(() => {
    const c = page.props.sipCredentials;

    return c ? [c.username, c.password, c.domain, c.wsServer].join('|') : '';
});

watch(
    credentialsKey,
    async (key) => {
        await disconnect();

        const credentials = page.props.sipCredentials;

        if (key && credentials) {
            initSip(credentials);
        }
    },
    { immediate: true },
);

/**
 * Queue presence follows the softphone: join the team queues once SIP is
 * registered (the server keeps an existing pause), leave when it is not.
 */
watch(
    () => callState.registered,
    (registered) => {
        const slug = page.props.currentTeam?.slug;

        if (registered && slug) {
            void joinQueues(slug, page.props.presenceHeartbeat ?? 60);

            return;
        }

        leaveQueues();
    },
);

// Closing or reloading the tab takes the agent out of the queues right away
// (agents:prune-presence is the safety net when this request is lost).
const onPageHide = () => leaveQueues();

/**
 * First visit: the guided tour starts by itself once the page has settled
 * (sidebar and softphone rendered). Closing or finishing it is saved, so it
 * never starts again; the help center can replay it.
 */
const tour = useAppTour();

const startTourIfNew = () => {
    const user = page.props.auth?.user;

    if (user && !user.tour_completed_at && !tour.active.value) {
        tour.start();
    }
};

onMounted(() => {
    window.addEventListener('pagehide', onPageHide);
    window.setTimeout(startTourIfNew, 900);
});

onUnmounted(() => {
    window.removeEventListener('pagehide', onPageHide);
    leaveQueues();
    disconnect();
});
</script>

<template>
    <AppSidebarLayout :breadcrumbs="props.breadcrumbs">
        <SubscriptionBanner />
        <CampaignCallListener />

        <!-- Global incoming-call popup -->
        <GlobalIncomingCall />

        <!-- Current page -->
        <slot />

        <AppTour />

        <!-- Global WebRTC remote audio -->
        <audio ref="remoteAudio" autoplay playsinline class="hidden" />
    </AppSidebarLayout>
</template>
