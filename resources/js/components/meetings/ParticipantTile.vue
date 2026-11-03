<script setup lang="ts">
import { t } from '@/lib/i18n';
import { MicOff, MonitorUp } from '@lucide/vue';
import type { Track } from 'livekit-client';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { MeetingTile } from '@/composables/useMeetingRoom';
import { avatarTone, initials } from '@/lib/avatar';

const props = defineProps<{
    tile: MeetingTile;
    large?: boolean;
}>();

const videoEl = ref<HTMLVideoElement | null>(null);
let attached: Track | null = null;

function detach() {
    if (attached && videoEl.value) {
        attached.detach(videoEl.value);
    }

    attached = null;
}

watch(
    [() => props.tile.video?.track ?? null, videoEl],
    ([track, element]) => {
        if (attached === track) {
            return;
        }

        detach();

        if (track && element) {
            track.attach(element);
            attached = track;
        }
    },
    { immediate: true },
);

onBeforeUnmount(detach);
</script>

<template>
    <div
        class="relative flex aspect-video items-center justify-center overflow-hidden rounded-2xl bg-neutral-800 text-white shadow-sm ring-[3px] transition"
        :class="
            tile.isSpeaking && !tile.isScreen
                ? 'ring-primary'
                : 'ring-transparent'
        "
    >
        <video
            v-show="tile.video"
            ref="videoEl"
            autoplay
            playsinline
            muted
            class="size-full"
            :class="[
                tile.isScreen ? 'object-contain' : 'object-cover',
                tile.isLocal && !tile.isScreen ? '-scale-x-100' : '',
            ]"
        />
        <div
            v-if="!tile.video"
            class="flex items-center justify-center rounded-full font-bold"
            :class="[
                large ? 'size-24 text-3xl' : 'size-16 text-xl',
                avatarTone(tile.name),
            ]"
            aria-hidden="true"
        >
            {{ initials(tile.name) }}
        </div>
        <div
            class="absolute bottom-2.5 left-2.5 flex max-w-[85%] items-center gap-1.5 rounded-full bg-black/55 px-2.5 py-1 text-xs font-medium backdrop-blur"
        >
            <MonitorUp v-if="tile.isScreen" class="size-3.5" />
            <MicOff
                v-else-if="!tile.micEnabled"
                class="size-3.5 text-red-300"
                :aria-label="t('Micro coupé')"
            />
            <span class="truncate">
                {{ tile.name }}{{ tile.isLocal ? ' (vous)' : '' }}
                <template v-if="tile.isScreen"> {{ t('— écran') }}</template>
            </span>
        </div>
    </div>
</template>
