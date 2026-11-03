<script setup lang="ts">
import { Check, Lock, Smartphone } from '@lucide/vue';
import { ref } from 'vue';
import { useStoryboard } from '@/composables/useStoryboard';

/**
 * Paying a subscription by mobile money on a phone:
 * 0 choose the method, 1 confirm on the phone, 2–3 paid and active.
 */
const root = ref<HTMLElement | null>(null);
const { step } = useStoryboard(root, 4, 2200, 2);

const methods = ['Orange Money', 'Moov Money', 'Carte bancaire'];
</script>

<template>
    <div
        ref="root"
        class="mk-stage flex justify-center rounded-[28px] px-5 py-8"
        aria-hidden="true"
    >
        <div
            class="mk-mock w-60 rounded-[2rem] border-[6px] border-[var(--mk-chrome)] p-4 text-left"
        >
            <div
                class="mx-auto mb-4 h-1.5 w-16 rounded-full bg-[var(--mk-chrome)]"
            />

            <p class="text-[11px] text-muted-foreground">
                Abonnement Pro · 1 mois
            </p>
            <p class="text-2xl font-extrabold text-heading tabular-nums">
                150 000 F
            </p>

            <div v-if="step === 0" key="choose" class="mk-pop mt-4 space-y-1.5">
                <p class="text-[11px] font-semibold text-muted-foreground">
                    Moyen de paiement
                </p>
                <span
                    v-for="(method, index) in methods"
                    :key="method"
                    class="flex items-center gap-2 rounded-lg border-2 px-2.5 py-1.5 text-xs font-semibold"
                    :class="
                        index === 0
                            ? 'border-primary bg-informative-subtle text-informative-strong'
                            : 'border-transparent bg-muted'
                    "
                >
                    <span
                        class="size-3 rounded-full border-2"
                        :class="
                            index === 0
                                ? 'border-primary bg-primary'
                                : 'border-[var(--input)]'
                        "
                    />
                    {{ method }}
                </span>
            </div>

            <div
                v-else-if="step === 1"
                key="confirm"
                class="mk-pop mt-4 space-y-3 text-center"
            >
                <span
                    class="mx-auto flex size-12 items-center justify-center rounded-full bg-informative-subtle text-informative-strong"
                >
                    <Smartphone class="size-6" />
                </span>
                <p class="text-xs font-semibold text-heading">
                    Confirmez avec votre code secret
                </p>
                <div class="flex justify-center gap-2">
                    <span
                        v-for="index in 4"
                        :key="index"
                        class="size-2.5 rounded-full bg-primary"
                    />
                </div>
                <p
                    class="flex items-center justify-center gap-1 text-[10px] text-muted-foreground"
                >
                    <Lock class="size-3" /> Paiement sécurisé
                </p>
            </div>

            <div v-else key="done" class="mk-pop mt-4 space-y-3 text-center">
                <span
                    class="mx-auto flex size-12 items-center justify-center rounded-full bg-positive text-white"
                >
                    <Check class="size-6" />
                </span>
                <p class="text-sm font-extrabold text-heading">
                    Paiement confirmé
                </p>
                <p class="text-[11px] text-muted-foreground">
                    Votre offre Pro est active pour 30 jours. Reçu envoyé par
                    e-mail.
                </p>
            </div>
        </div>
    </div>
</template>
