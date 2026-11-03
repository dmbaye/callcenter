import {
    useElementVisibility,
    useIntervalFn,
    usePreferredReducedMotion,
} from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';

/**
 * Drives a looping illustration: `step` advances every `interval` ms while
 * the element is on screen. With reduced motion the scene holds `stillStep`.
 */
export function useStoryboard(
    target: Ref<HTMLElement | null>,
    steps: number,
    interval = 2200,
    stillStep = 1,
): { step: Ref<number>; animated: Ref<boolean>; tick: Ref<number> } {
    const visible = useElementVisibility(target);
    const motion = usePreferredReducedMotion();
    const animated = computed(() => motion.value !== 'reduce');
    const step = ref(animated.value ? 0 : stillStep);
    // Total number of advances, for counters that keep growing.
    const tick = ref(0);

    const { pause, resume } = useIntervalFn(
        () => {
            step.value = (step.value + 1) % steps;
            tick.value += 1;
        },
        interval,
        { immediate: false },
    );

    watch(
        [visible, animated],
        ([isVisible, isAnimated]) => {
            if (isVisible && isAnimated) {
                resume();
            } else {
                pause();

                if (!isAnimated) {
                    step.value = stillStep;
                }
            }
        },
        { immediate: true },
    );

    return { step, animated, tick };
}
