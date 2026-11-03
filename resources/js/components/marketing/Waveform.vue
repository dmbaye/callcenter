<script setup lang="ts">
/** Animated voice bars; flat when `active` is false. */
withDefaults(
    defineProps<{ active?: boolean; bars?: number; tone?: string }>(),
    {
        active: true,
        bars: 18,
        tone: 'bg-primary',
    },
);

// Fixed pseudo-random heights so the shape looks like speech, not a meter.
const heights = [
    40, 70, 55, 90, 65, 35, 80, 100, 60, 45, 75, 50, 85, 40, 65, 95, 55, 30, 70,
    45, 80, 60,
];
</script>

<template>
    <div class="flex h-8 items-center gap-[3px]" :class="{ 'mk-wave': active }">
        <span
            v-for="index in bars"
            :key="index"
            class="w-[3px] rounded-full transition-[height] duration-500"
            :class="tone"
            :style="{
                height: active ? `${heights[index % heights.length]}%` : '12%',
                animationDelay: `${(index % 7) * 0.11}s`,
                animationDuration: `${0.8 + (index % 5) * 0.12}s`,
            }"
        />
    </div>
</template>
