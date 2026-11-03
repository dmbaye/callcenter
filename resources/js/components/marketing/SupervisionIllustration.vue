<script setup lang="ts">
import { Ear, MessageSquareQuote, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusLight from '@/components/StatusLight.vue';
import { useStoryboard } from '@/composables/useStoryboard';
import MockAvatar from './MockAvatar.vue';
import Waveform from './Waveform.vue';

/**
 * Supervisor wallboard: live queue figures, agent tiles, and a whisper sent
 * to one agent that the customer does not hear.
 */
const root = ref<HTMLElement | null>(null);
const { step } = useStoryboard(root, 4, 2400, 2);

const mode = computed(() =>
    step.value === 0 ? 'idle' : step.value === 1 ? 'listen' : 'whisper',
);

const tiles = [
    {
        name: 'Awa',
        initials: 'AD',
        tone: 'blue' as const,
        light: 'informative' as const,
        label: 'En appel',
        time: '3:12',
        focus: true,
    },
    {
        name: 'Moussa',
        initials: 'MK',
        tone: 'seafoam' as const,
        light: 'informative' as const,
        label: 'En appel',
        time: '0:48',
    },
    {
        name: 'Fatou',
        initials: 'FS',
        tone: 'orange' as const,
        light: 'notice' as const,
        label: 'Pause',
        time: '6:02',
    },
    {
        name: 'Ibrahim',
        initials: 'IT',
        tone: 'purple' as const,
        light: 'positive' as const,
        label: 'Disponible',
        time: '—',
    },
];
</script>

<template>
    <div
        ref="root"
        class="mk-stage rounded-[28px] p-5 sm:p-8"
        aria-hidden="true"
    >
        <div class="mx-auto max-w-md space-y-3 text-left">
            <div class="mk-mock grid grid-cols-3 divide-x py-3 text-center">
                <div>
                    <p class="text-lg font-extrabold text-heading tabular-nums">
                        3
                    </p>
                    <p class="text-[10px] text-muted-foreground">En attente</p>
                </div>
                <div>
                    <p class="text-lg font-extrabold text-heading tabular-nums">
                        0:42
                    </p>
                    <p class="text-[10px] text-muted-foreground">
                        Plus longue attente
                    </p>
                </div>
                <div>
                    <p
                        class="text-lg font-extrabold text-positive tabular-nums"
                    >
                        87 %
                    </p>
                    <p class="text-[10px] text-muted-foreground">
                        Niveau de service
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div
                    v-for="tile in tiles"
                    :key="tile.name"
                    class="mk-mock p-3 transition-shadow duration-300"
                    :class="
                        tile.focus && mode !== 'idle'
                            ? 'ring-2 ring-primary ring-offset-2 ring-offset-[var(--mk-layer)]'
                            : ''
                    "
                >
                    <div class="flex items-center gap-2">
                        <MockAvatar
                            :initials="tile.initials"
                            :tone="tile.tone"
                            size="sm"
                        />
                        <div class="min-w-0">
                            <p class="truncate text-xs font-bold text-heading">
                                {{ tile.name }}
                            </p>
                            <StatusLight
                                size="s"
                                :variant="tile.light"
                                class="text-[10px]"
                            >
                                {{ tile.label }}
                            </StatusLight>
                        </div>
                        <span
                            class="ml-auto text-[11px] text-muted-foreground tabular-nums"
                            >{{ tile.time }}</span
                        >
                    </div>
                    <div v-if="tile.focus" class="mt-2.5 space-y-2">
                        <Waveform
                            :active="mode !== 'idle'"
                            :bars="14"
                            tone="bg-informative"
                            class="!h-5"
                        />
                        <div class="grid grid-cols-2 gap-1.5">
                            <span
                                class="flex h-6 items-center justify-center gap-1 rounded-full text-[10px] font-bold transition-colors"
                                :class="
                                    mode === 'listen'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'border-2 border-[var(--btn-outline-border)]'
                                "
                            >
                                <Ear class="size-3" /> Écouter
                            </span>
                            <span
                                class="flex h-6 items-center justify-center gap-1 rounded-full text-[10px] font-bold transition-colors"
                                :class="
                                    mode === 'whisper'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'border-2 border-[var(--btn-outline-border)]'
                                "
                            >
                                <MessageSquareQuote class="size-3" /> Chuchoter
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="mode === 'whisper'"
                :key="step"
                class="mk-pop mk-mock flex items-start gap-3 p-3"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
                >
                    <Users class="size-4" />
                </span>
                <div>
                    <p class="text-xs font-bold text-heading">
                        Superviseur → Awa
                    </p>
                    <p class="text-xs text-foreground">
                        « Propose-lui le paiement en deux fois. »
                    </p>
                    <p class="mt-1 text-[10px] text-muted-foreground">
                        Le client n'entend pas le chuchotement.
                    </p>
                </div>
            </div>
            <div
                v-else
                class="flex h-[74px] items-center justify-center rounded-2xl border border-dashed text-[11px] text-muted-foreground"
            >
                {{
                    mode === 'listen'
                        ? 'Écoute silencieuse en cours…'
                        : 'Sélectionnez un agent'
                }}
            </div>
        </div>
    </div>
</template>
