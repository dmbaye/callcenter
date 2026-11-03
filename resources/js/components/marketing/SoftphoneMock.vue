<script setup lang="ts">
import {
    Check,
    Grid3x3,
    Mic,
    Pause,
    Phone,
    PhoneForwarded,
    PhoneOff,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import StatusLight from '@/components/StatusLight.vue';
import Waveform from './Waveform.vue';

/** The agent's browser softphone with the caller's screen pop. */
const props = withDefaults(
    defineProps<{
        phase: 'ringing' | 'talking' | 'wrap';
        seconds?: number;
    }>(),
    { seconds: 0 },
);

const clock = computed(() => {
    const minutes = Math.floor(props.seconds / 60);
    const rest = String(props.seconds % 60).padStart(2, '0');

    return `${minutes}:${rest}`;
});
</script>

<template>
    <div class="mk-mock w-full overflow-hidden text-left">
        <div class="flex items-center justify-between border-b px-4 py-2.5">
            <span class="text-xs font-bold text-heading">Softphone</span>
            <StatusLight
                size="s"
                :variant="
                    phase === 'talking'
                        ? 'informative'
                        : phase === 'wrap'
                          ? 'notice'
                          : 'positive'
                "
            >
                {{
                    phase === 'talking'
                        ? 'En appel'
                        : phase === 'wrap'
                          ? 'Post-appel'
                          : 'Disponible'
                }}
            </StatusLight>
        </div>

        <div class="space-y-4 p-4">
            <div class="flex items-center gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-full bg-informative-subtle text-informative-strong"
                    :class="{ 'mk-ring': phase === 'ringing' }"
                >
                    <UserRound class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold text-heading">
                        Aminata Coulibaly
                    </p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        +223 76 12 34 56
                    </p>
                </div>
                <span
                    class="ml-auto font-mono text-lg font-bold text-primary tabular-nums"
                    >{{ phase === 'ringing' ? '' : clock }}</span
                >
            </div>

            <!-- Screen pop: who is calling and why -->
            <dl
                class="grid grid-cols-2 gap-x-3 gap-y-1.5 rounded-lg bg-muted px-3 py-2.5 text-[11px]"
            >
                <dt class="text-muted-foreground">File</dt>
                <dd class="text-right font-semibold">Support</dd>
                <dt class="text-muted-foreground">Choix SVI</dt>
                <dd class="text-right font-semibold">2 · Sinistre</dd>
                <dt class="text-muted-foreground">Attente</dt>
                <dd class="text-right font-semibold tabular-nums">0:38</dd>
                <dt class="text-muted-foreground">Dernier appel</dt>
                <dd class="text-right font-semibold">il y a 3 jours</dd>
            </dl>

            <div v-if="phase === 'ringing'" class="mk-pop flex gap-2">
                <span
                    class="flex h-9 flex-1 items-center justify-center gap-1.5 rounded-full bg-positive text-xs font-bold text-white"
                >
                    <Phone class="size-3.5" /> Répondre
                </span>
                <span
                    class="flex h-9 flex-1 items-center justify-center gap-1.5 rounded-full bg-destructive text-xs font-bold text-white"
                >
                    <PhoneOff class="size-3.5" /> Refuser
                </span>
            </div>

            <div v-else-if="phase === 'talking'" class="mk-pop space-y-3">
                <Waveform class="mx-auto" :bars="22" />
                <div class="grid grid-cols-5 gap-1.5">
                    <span
                        v-for="(icon, index) in [
                            Mic,
                            Pause,
                            Grid3x3,
                            PhoneForwarded,
                        ]"
                        :key="index"
                        class="flex h-8 items-center justify-center rounded-full border-2 border-[var(--btn-outline-border)] text-foreground"
                    >
                        <component :is="icon" class="size-3.5" />
                    </span>
                    <span
                        class="flex h-8 items-center justify-center rounded-full bg-destructive text-white"
                    >
                        <PhoneOff class="size-3.5" />
                    </span>
                </div>
            </div>

            <div v-else class="mk-pop space-y-2">
                <p class="text-[11px] font-semibold text-muted-foreground">
                    Résultat de l'appel
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        class="inline-flex items-center gap-1 rounded-md bg-positive-subtle px-2 py-1 text-[11px] font-semibold text-positive-strong"
                    >
                        <Check class="size-3" /> Résolu
                    </span>
                    <span
                        class="rounded-md bg-muted px-2 py-1 text-[11px] text-muted-foreground"
                        >Rappel</span
                    >
                    <span
                        class="rounded-md bg-muted px-2 py-1 text-[11px] text-muted-foreground"
                        >Escalade</span
                    >
                </div>
            </div>
        </div>
    </div>
</template>
