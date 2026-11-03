<script setup lang="ts">
import { Mic, MicOff, MonitorUp, PhoneOff, Video } from '@lucide/vue';
import { ref } from 'vue';
import { useStoryboard } from '@/composables/useStoryboard';
import MockAvatar from './MockAvatar.vue';
import Waveform from './Waveform.vue';

/** Video meeting grid; the active speaker ring moves between participants. */
const root = ref<HTMLElement | null>(null);
const { step } = useStoryboard(root, 4, 2000, 0);

const people = [
    {
        name: 'Awa Diarra',
        initials: 'AD',
        tone: 'blue' as const,
        bg: 'bg-[rgb(59_99_251)]',
        muted: false,
    },
    {
        name: 'Moussa Keïta',
        initials: 'MK',
        tone: 'seafoam' as const,
        bg: 'bg-[rgb(9_144_120)]',
        muted: false,
    },
    {
        name: 'Fatou Sow',
        initials: 'FS',
        tone: 'orange' as const,
        bg: 'bg-[rgb(225_110_0)]',
        muted: true,
    },
    {
        name: 'Ibrahim Traoré',
        initials: 'IT',
        tone: 'purple' as const,
        bg: 'bg-[rgb(113_85_250)]',
        muted: false,
    },
];
</script>

<template>
    <div
        ref="root"
        class="mk-stage rounded-[28px] p-5 sm:p-8"
        aria-hidden="true"
    >
        <div class="mk-mock mx-auto max-w-md overflow-hidden text-left">
            <div class="flex items-center justify-between border-b px-4 py-2.5">
                <p class="text-xs font-bold text-heading">
                    Point d'équipe du lundi
                </p>
                <span
                    class="inline-flex items-center gap-1.5 text-[11px] text-negative"
                >
                    <span
                        class="size-1.5 animate-pulse rounded-full bg-negative"
                    />
                    12:48
                </span>
            </div>
            <div class="grid grid-cols-2 gap-2 p-2">
                <div
                    v-for="(person, index) in people"
                    :key="person.name"
                    class="relative flex aspect-video items-center justify-center overflow-hidden rounded-xl transition-shadow duration-300"
                    :class="[
                        person.bg,
                        step === index && !person.muted
                            ? 'ring-[3px] ring-positive ring-offset-2 ring-offset-[var(--card)]'
                            : '',
                    ]"
                >
                    <MockAvatar
                        :initials="person.initials"
                        :tone="person.tone"
                        size="lg"
                        class="relative shadow-lg"
                    />
                    <span
                        class="absolute bottom-1.5 left-1.5 inline-flex items-center gap-1 rounded-md bg-black/45 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                    >
                        <MicOff v-if="person.muted" class="size-3" />
                        <Waveform
                            v-else-if="step === index"
                            :bars="5"
                            tone="bg-white"
                            class="!h-3"
                        />
                        {{ person.name }}
                    </span>
                </div>
            </div>
            <div
                class="flex items-center justify-center gap-2 border-t px-4 py-2.5"
            >
                <span
                    v-for="(icon, index) in [Mic, Video, MonitorUp]"
                    :key="index"
                    class="flex size-8 items-center justify-center rounded-full bg-secondary text-secondary-foreground"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <span
                    class="ml-2 flex h-8 items-center gap-1.5 rounded-full bg-destructive px-3 text-[11px] font-bold text-white"
                >
                    <PhoneOff class="size-3.5" /> Quitter
                </span>
            </div>
        </div>
    </div>
</template>
