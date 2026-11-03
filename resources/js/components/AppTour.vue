<script setup lang="ts">
import { t } from '@/lib/i18n';
import { ArrowLeft, ArrowRight, X } from '@lucide/vue';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { tourTarget, useAppTour } from '@/composables/useAppTour';

/**
 * Spotlight and popover of the guided tour. The page stays visible under
 * a dimmed layer; the highlighted element is cut out of it, and the card
 * sits next to it (or centered for steps without a target).
 */
const { active, index, steps, current, stop, next, previous } = useAppTour();

const PADDING = 6;
const GAP = 12;
const CARD_WIDTH = 340;

const rect = ref<DOMRect | null>(null);
const card = ref<HTMLElement | null>(null);
const cardHeight = ref(180);

const isLast = computed(() => index.value === steps.value.length - 1);

function measure() {
    const element = tourTarget(current.value);

    rect.value = element ? element.getBoundingClientRect() : null;
    cardHeight.value = card.value?.offsetHeight ?? cardHeight.value;
}

async function focusStep() {
    const element = tourTarget(current.value);

    element?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    await nextTick();
    measure();
    card.value?.focus({ preventScroll: true });
}

const spotlight = computed(() => {
    if (!rect.value) {
        return null;
    }

    return {
        top: `${rect.value.top - PADDING}px`,
        left: `${rect.value.left - PADDING}px`,
        width: `${rect.value.width + PADDING * 2}px`,
        height: `${rect.value.height + PADDING * 2}px`,
    };
});

/** Right of the target if it fits (sidebar), else below, else above; always on screen. */
const cardStyle = computed(() => {
    const width = Math.min(CARD_WIDTH, window.innerWidth - 32);

    if (!rect.value) {
        return {
            width: `${width}px`,
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
        };
    }

    const r = rect.value;
    const height = cardHeight.value;
    const clampTop = (top: number) =>
        Math.max(16, Math.min(top, window.innerHeight - height - 16));
    const clampLeft = (left: number) =>
        Math.max(16, Math.min(left, window.innerWidth - width - 16));

    if (r.right + GAP + width + 16 <= window.innerWidth && r.width < 320) {
        return {
            width: `${width}px`,
            top: `${clampTop(r.top + r.height / 2 - height / 2)}px`,
            left: `${r.right + GAP + PADDING}px`,
        };
    }

    const below = r.bottom + GAP + PADDING;
    const top =
        below + height + 16 <= window.innerHeight
            ? below
            : r.top - GAP - PADDING - height;

    return {
        width: `${width}px`,
        top: `${clampTop(top)}px`,
        left: `${clampLeft(r.left + r.width / 2 - width / 2)}px`,
    };
});

function onKeydown(event: KeyboardEvent) {
    if (!active.value) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        stop();
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        next();
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        previous();
    }
}

function listen(on: boolean) {
    if (on) {
        window.addEventListener('resize', measure);
        window.addEventListener('scroll', measure, true);
        window.addEventListener('keydown', onKeydown);

        return;
    }

    window.removeEventListener('resize', measure);
    window.removeEventListener('scroll', measure, true);
    window.removeEventListener('keydown', onKeydown);
}

watch(active, (isActive) => listen(isActive));
watch([active, index], () => active.value && void focusStep(), {
    flush: 'post',
});

onUnmounted(() => listen(false));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="active && current"
            class="fixed inset-0 z-[60]"
            data-test="app-tour"
        >
            <!-- Click outside the card does nothing: the tour only closes on purpose. -->
            <div
                v-if="!spotlight"
                class="absolute inset-0 bg-black/45"
                aria-hidden="true"
            />
            <div
                v-else
                class="pointer-events-none absolute rounded-[var(--control-radius)] ring-2 ring-primary transition-all duration-200 ease-out motion-reduce:transition-none"
                :style="{
                    ...spotlight,
                    boxShadow: '0 0 0 9999px rgb(0 0 0 / 0.45)',
                }"
                aria-hidden="true"
            />

            <div
                ref="card"
                role="dialog"
                aria-modal="true"
                :aria-label="t(current.title)"
                aria-describedby="app-tour-body"
                tabindex="-1"
                class="absolute rounded-[var(--card-radius)] border bg-popover p-5 text-popover-foreground shadow-[var(--dialog-shadow)] outline-none"
                :style="cardStyle"
            >
                <div class="mb-2 flex items-start justify-between gap-3">
                    <h2 class="text-base font-bold text-heading">
                        {{ t(current.title) }}
                    </h2>
                    <button
                        type="button"
                        class="-mt-1 -mr-1 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                        :title="t('Quitter la visite')"
                        @click="stop"
                    >
                        <X class="size-4" />
                        <span class="sr-only">{{
                            t('Quitter la visite')
                        }}</span>
                    </button>
                </div>

                <p
                    id="app-tour-body"
                    class="text-sm leading-relaxed text-muted-foreground"
                >
                    {{ t(current.body) }}
                </p>

                <div class="mt-5 flex items-center justify-between gap-3">
                    <span
                        class="text-xs text-muted-foreground tabular-nums"
                        :aria-label="`Étape ${index + 1} sur ${steps.length}`"
                        >{{ index + 1 }} / {{ steps.length }}</span
                    >

                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            v-if="index > 0"
                            variant="ghost"
                            size="sm"
                            @click="previous"
                        >
                            <ArrowLeft /> {{ t('Précédent') }}
                        </Button>
                        <Button
                            v-else
                            variant="ghost"
                            size="sm"
                            @click="stop"
                            >{{ t('Passer') }}</Button
                        >
                        <Button size="sm" @click="next">
                            {{ isLast ? 'Terminer' : 'Suivant' }}
                            <ArrowRight v-if="!isLast" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
