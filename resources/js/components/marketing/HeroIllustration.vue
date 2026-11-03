<script setup lang="ts">
import { PhoneIncoming } from '@lucide/vue';
import { ref } from 'vue';
import { useCallScene } from '@/composables/useCallScene';
import BrowserFrame from './BrowserFrame.vue';
import DashboardMock from './DashboardMock.vue';
import SoftphoneMock from './SoftphoneMock.vue';

/**
 * Hero scene: the supervisor dashboard, with the agent's softphone taking a
 * call in front of it. Steps: 0 ringing, 1–2 talking, 3 wrap-up.
 */
const root = ref<HTMLElement | null>(null);
const { step, tick, phase, seconds } = useCallScene(root);
</script>

<template>
    <div ref="root" class="relative" aria-hidden="true">
        <div
            class="mk-stage rounded-[28px] p-3 sm:p-6 lg:px-16 lg:pt-12 lg:pb-20"
        >
            <div class="relative mx-auto max-w-5xl lg:pl-40 xl:pl-32">
                <BrowserFrame>
                    <DashboardMock :step="step" :tick="tick" />
                </BrowserFrame>

                <!-- Incoming call toast -->
                <div
                    v-if="phase === 'ringing'"
                    class="mk-pop mk-mock absolute -top-4 right-3 hidden items-center gap-3 px-4 py-3 sm:flex lg:-right-6"
                >
                    <span
                        class="mk-ring flex size-8 items-center justify-center rounded-full bg-positive text-white"
                    >
                        <PhoneIncoming class="size-4" />
                    </span>
                    <div>
                        <p class="text-xs font-bold text-heading">
                            Appel entrant · Support
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            3 appelants en file
                        </p>
                    </div>
                </div>

                <!-- Agent softphone floating over the dashboard -->
                <div
                    class="mk-float relative mx-auto mt-4 w-full max-w-72 lg:absolute lg:-bottom-14 lg:-left-10 lg:mt-0 lg:w-64 xl:-left-12"
                >
                    <SoftphoneMock :phase="phase" :seconds="seconds" />
                </div>
            </div>
        </div>
    </div>
</template>
