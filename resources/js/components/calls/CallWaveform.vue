<script setup lang="ts">
import { computed } from 'vue';

/**
 * Voice bars of the softphone. With `levels` (live audio, 0–1 per bar) the
 * bars follow the conversation; without, they animate on their own while
 * `active`, and lie flat otherwise (on hold, dialing).
 */
const props = withDefaults(
    defineProps<{ active?: boolean; levels?: number[]; bars?: number }>(),
    { active: true, levels: () => [], bars: 22 },
);

// Fixed pseudo-random heights so the idle animation looks like speech.
const shape = [
    40, 70, 55, 90, 65, 35, 80, 100, 60, 45, 75, 50, 85, 40, 65, 95, 55, 30, 70,
    45, 80, 60,
];

const live = computed(() => props.active && props.levels.length > 0);

function height(index: number): string {
    if (!props.active) {
        return '12%';
    }

    if (live.value) {
        return `${Math.max(12, Math.round((props.levels[index] ?? 0) * 100))}%`;
    }

    return `${shape[index % shape.length]}%`;
}
</script>

<template>
    <div
        class="flex h-8 items-center gap-[3px]"
        :class="{ 'mk-wave': active && !live }"
        aria-hidden="true"
    >
        <span
            v-for="(_, index) in bars"
            :key="index"
            class="w-[3px] rounded-full bg-primary"
            :class="
                live
                    ? 'transition-[height] duration-75'
                    : 'transition-[height] duration-500'
            "
            :style="{
                height: height(index),
                animationDelay: `${(index % 7) * 0.11}s`,
                animationDuration: `${0.8 + (index % 5) * 0.12}s`,
            }"
        />
    </div>
</template>
