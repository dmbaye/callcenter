import { useIntervalFn } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { useStoryboard } from './useStoryboard';

export type CallPhase = 'ringing' | 'talking' | 'wrap';

/**
 * Storyboard for a call illustration: 0 ringing, 1–2 talking, 3 wrap-up,
 * with a call timer that runs while talking.
 */
export function useCallScene(target: Ref<HTMLElement | null>): {
    step: Ref<number>;
    tick: Ref<number>;
    phase: Ref<CallPhase>;
    seconds: Ref<number>;
} {
    const { step, tick, animated } = useStoryboard(target, 4, 2800);

    const phase = computed<CallPhase>(() =>
        step.value === 0 ? 'ringing' : step.value === 3 ? 'wrap' : 'talking',
    );

    const seconds = ref(animated.value ? 0 : 47);
    const timer = useIntervalFn(() => (seconds.value += 1), 1000, {
        immediate: false,
    });

    watch(
        phase,
        (value) => {
            if (value === 'ringing') {
                seconds.value = 0;
            }

            if (value === 'talking' && animated.value) {
                timer.resume();
            } else {
                timer.pause();
            }
        },
        { immediate: true },
    );

    return { step, tick, phase, seconds };
}
