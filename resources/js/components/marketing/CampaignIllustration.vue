<script setup lang="ts">
import { CalendarCheck, LoaderCircle, Megaphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusLight from '@/components/StatusLight.vue';
import { useStoryboard } from '@/composables/useStoryboard';

/**
 * Outbound campaign in progress: each dialer line cycles through dialing,
 * ringing, answering machine or a connected agent; progress keeps growing.
 */
const root = ref<HTMLElement | null>(null);
const { step, tick } = useStoryboard(root, 4, 1800, 3);

type Light = 'neutral' | 'informative' | 'notice' | 'positive';

const states: { light: Light; label: string }[] = [
    { light: 'neutral', label: 'Numérotation…' },
    { light: 'informative', label: 'Sonnerie' },
    { light: 'notice', label: 'Répondeur détecté' },
    { light: 'positive', label: 'Connecté' },
];

const lines = [
    { number: '+223 66 41 20 18', agent: 'Awa' },
    { number: '+223 79 05 33 62', agent: 'Moussa' },
    { number: '+223 70 88 14 07', agent: 'Ibrahim' },
    { number: '+223 65 27 90 41', agent: 'Fatou' },
];

const dialer = computed(() =>
    lines.map((line, index) => {
        const state = states[(step.value + index * 3) % states.length];

        return {
            ...line,
            ...state,
            label:
                state.light === 'positive'
                    ? `${state.label} · ${line.agent}`
                    : state.label,
        };
    }),
);

const total = 1200;
const called = computed(() => Math.min(total, 742 + tick.value * 3));
const reached = computed(() => Math.round(called.value * 0.58));
const progress = computed(() => Math.round((called.value / total) * 100));
</script>

<template>
    <div
        ref="root"
        class="mk-stage rounded-[28px] p-5 sm:p-8"
        aria-hidden="true"
    >
        <div class="relative mx-auto max-w-md text-left">
            <div class="mk-mock p-4 sm:p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-[rgb(255_236_207)] text-[rgb(167_62_0)] dark:bg-[rgb(80_27_0)] dark:text-[rgb(255_193_94)]"
                        >
                            <Megaphone class="size-4" />
                        </span>
                        <div>
                            <p class="text-sm font-extrabold text-heading">
                                Relance renouvellement
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                Mode progressif · 1,5 ligne par agent
                            </p>
                        </div>
                    </div>
                    <StatusLight size="s" variant="positive"
                        >En cours</StatusLight
                    >
                </div>

                <div class="mt-4">
                    <div class="flex justify-between text-[11px]">
                        <span class="text-muted-foreground"
                            >{{ called }} / {{ total }} contacts</span
                        >
                        <span class="font-bold text-heading tabular-nums"
                            >{{ progress }} %</span
                        >
                    </div>
                    <div
                        class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full bg-primary transition-[width] duration-700"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                </div>

                <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-muted py-2">
                        <dd
                            class="text-base font-extrabold text-heading tabular-nums"
                        >
                            {{ reached }}
                        </dd>
                        <dt class="text-[10px] text-muted-foreground">
                            Joints
                        </dt>
                    </div>
                    <div class="rounded-lg bg-muted py-2">
                        <dd
                            class="text-base font-extrabold text-heading tabular-nums"
                        >
                            58 %
                        </dd>
                        <dt class="text-[10px] text-muted-foreground">
                            Taux de contact
                        </dt>
                    </div>
                    <div class="rounded-lg bg-muted py-2">
                        <dd
                            class="text-base font-extrabold text-heading tabular-nums"
                        >
                            1,8 %
                        </dd>
                        <dt class="text-[10px] text-muted-foreground">
                            Abandon
                        </dt>
                    </div>
                </dl>

                <ul class="mt-4 divide-y rounded-lg border">
                    <li
                        v-for="line in dialer"
                        :key="line.number"
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        <span
                            class="flex items-center gap-2 text-xs tabular-nums"
                        >
                            <LoaderCircle
                                v-if="line.light === 'neutral'"
                                class="size-3.5 animate-spin text-muted-foreground"
                            />
                            {{ line.number }}
                        </span>
                        <StatusLight
                            :key="line.label"
                            size="s"
                            :variant="line.light"
                            class="mk-pop text-[11px]"
                            >{{ line.label }}</StatusLight
                        >
                    </li>
                </ul>
            </div>

            <!-- Disposition just saved by an agent -->
            <div
                :key="step"
                class="mk-pop mk-mock absolute -right-2 -bottom-5 flex items-center gap-2 px-3 py-2 sm:-right-8"
            >
                <CalendarCheck class="size-4 text-positive" />
                <span class="text-[11px] font-semibold text-heading">{{
                    [
                        'Rendez-vous pris',
                        'Rappel demain 10:00',
                        'Vente conclue',
                        'Pas intéressé',
                    ][step]
                }}</span>
            </div>
        </div>
    </div>
</template>
