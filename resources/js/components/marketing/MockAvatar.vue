<script setup lang="ts">
import { toneClasses } from '@/data/marketing';

/** Initials avatar for mockups, tinted with a Spectrum categorical color. */
withDefaults(
    defineProps<{
        initials: string;
        tone?: 'blue' | 'seafoam' | 'orange' | 'purple' | 'fuchsia';
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { tone: 'blue', size: 'md' },
);

const tones = toneClasses;

const sizes = {
    sm: 'size-7 text-[10px]',
    md: 'size-9 text-xs',
    lg: 'size-14 text-base',
};
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center rounded-full font-bold"
        :class="[tones[tone], sizes[size]]"
        >{{ initials }}</span
    >
</template>
