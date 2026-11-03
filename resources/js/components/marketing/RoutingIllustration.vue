<script setup lang="ts">
import {
    CalendarClock,
    Check,
    Hash,
    ListOrdered,
    PhoneIncoming,
    PhoneMissed,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useStoryboard } from '@/composables/useStoryboard';
import MockAvatar from './MockAvatar.vue';

/**
 * Inbound call flow: number → opening hours → IVR → queue → agent.
 * The caller alternates between "1 Ventes" and "2 Support".
 */
const root = ref<HTMLElement | null>(null);
const { step, animated } = useStoryboard(root, 4, 2400);

// 0–1: caller picks 1 (sales), 2–3: caller picks 2 (support).
const choice = computed(() => (step.value < 2 ? 1 : 2));
const answered = computed(() => step.value % 2 === 1);

const queues = [
    { key: 1, label: 'Ventes', agent: 'MK', tone: 'seafoam' as const },
    { key: 2, label: 'Support', agent: 'AD', tone: 'blue' as const },
];
</script>

<template>
    <div
        ref="root"
        class="mk-stage rounded-[28px] p-5 sm:p-8"
        aria-hidden="true"
    >
        <div class="mx-auto flex max-w-sm flex-col items-stretch text-left">
            <!-- Incoming -->
            <div class="mk-mock flex items-center gap-3 px-4 py-3">
                <span
                    class="flex size-9 items-center justify-center rounded-full bg-positive text-white"
                    :class="{ 'mk-ring': animated }"
                >
                    <PhoneIncoming class="size-4" />
                </span>
                <div>
                    <p class="text-xs font-bold text-heading">Appel entrant</p>
                    <p class="text-[11px] text-muted-foreground tabular-nums">
                        +223 76 12 34 56 → 20 22 45 00
                    </p>
                </div>
            </div>

            <div class="relative mx-auto h-8 w-0.5 bg-border">
                <span
                    v-if="animated"
                    class="absolute left-1/2 size-2 -translate-x-1/2 [animation:mk-travel-y_1.2s_linear_infinite] rounded-full bg-primary"
                />
            </div>

            <!-- Opening hours -->
            <div class="mk-mock flex items-center gap-3 px-4 py-3">
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-informative-subtle text-informative-strong"
                >
                    <CalendarClock class="size-4" />
                </span>
                <div class="flex-1">
                    <p class="text-xs font-bold text-heading">Horaires</p>
                    <p class="text-[11px] text-muted-foreground">
                        Lun – sam · 08:00 – 18:00
                    </p>
                </div>
                <span
                    class="inline-flex items-center gap-1 rounded-md bg-positive-subtle px-2 py-0.5 text-[11px] font-semibold text-positive-strong"
                >
                    <Check class="size-3" /> Ouvert
                </span>
            </div>

            <div class="relative mx-auto h-8 w-0.5 bg-border">
                <span
                    v-if="animated"
                    class="absolute left-1/2 size-2 -translate-x-1/2 [animation:mk-travel-y_1.2s_0.4s_linear_infinite] rounded-full bg-primary"
                />
            </div>

            <!-- IVR -->
            <div class="mk-mock px-4 py-3">
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-9 items-center justify-center rounded-lg bg-[rgb(244_234_255)] text-[rgb(125_58_198)] dark:bg-[rgb(60_20_100)] dark:text-[rgb(214_183_250)]"
                    >
                        <Hash class="size-4" />
                    </span>
                    <div>
                        <p class="text-xs font-bold text-heading">
                            Menu vocal (SVI)
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            « Tapez 1 pour les ventes, 2 pour le support »
                        </p>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <span
                        v-for="queue in queues"
                        :key="queue.key"
                        class="flex items-center justify-center gap-2 rounded-lg border-2 py-1.5 text-xs font-bold transition-colors duration-300"
                        :class="
                            choice === queue.key
                                ? 'border-primary bg-informative-subtle text-informative-strong'
                                : 'border-transparent bg-muted text-muted-foreground'
                        "
                    >
                        <span
                            class="flex size-5 items-center justify-center rounded bg-background text-[11px] text-heading"
                            >{{ queue.key }}</span
                        >
                        {{ queue.label }}
                    </span>
                </div>
            </div>

            <!-- Branches -->
            <div class="grid grid-cols-2">
                <div
                    v-for="queue in queues"
                    :key="queue.key"
                    class="relative mx-auto h-8 w-0.5 transition-colors duration-300"
                    :class="choice === queue.key ? 'bg-primary' : 'bg-border'"
                />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div
                    v-for="queue in queues"
                    :key="queue.key"
                    class="mk-mock px-3 py-3 transition-opacity duration-300"
                    :class="choice === queue.key ? 'opacity-100' : 'opacity-50'"
                >
                    <div class="flex items-center gap-2">
                        <ListOrdered class="size-4 text-muted-foreground" />
                        <p class="text-xs font-bold text-heading">
                            File {{ queue.label }}
                        </p>
                    </div>
                    <div class="mt-2.5 flex items-center gap-2">
                        <MockAvatar
                            :initials="queue.agent"
                            :tone="queue.tone"
                            size="sm"
                            :class="{
                                'mk-ring':
                                    choice === queue.key &&
                                    !answered &&
                                    animated,
                            }"
                        />
                        <span
                            v-if="choice === queue.key"
                            :key="String(answered)"
                            class="mk-pop text-[11px] font-semibold"
                            :class="
                                answered
                                    ? 'text-informative-strong'
                                    : 'text-positive-strong'
                            "
                            >{{ answered ? 'Décroché' : 'Sonne…' }}</span
                        >
                        <span v-else class="text-[11px] text-muted-foreground"
                            >Libre</span
                        >
                    </div>
                </div>
            </div>

            <!-- Overflow rule -->
            <div
                class="mt-3 flex items-center gap-2 rounded-xl border border-dashed bg-background/70 px-3 py-2 text-[11px] text-muted-foreground"
            >
                <PhoneMissed class="size-3.5 shrink-0 text-notice" />
                Après 3 min d'attente : rappel proposé, puis file de secours.
            </div>
        </div>
    </div>
</template>
