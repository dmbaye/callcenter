<script setup lang="ts">
/**
 * Spectrum status light: a colored dot followed by a label. Used for call
 * dispositions and agent presence instead of raw values or color-only cues.
 */
withDefaults(
    defineProps<{
        variant?:
            | 'positive'
            | 'notice'
            | 'negative'
            | 'informative'
            | 'neutral';
        size?: 's' | 'm';
    }>(),
    { variant: 'neutral', size: 'm' },
);

const dots = {
    positive: 'bg-positive',
    notice: 'bg-notice',
    negative: 'bg-negative',
    informative: 'bg-informative',
    neutral: 'bg-neutral-visual',
};
</script>

<template>
    <span
        class="inline-flex items-center whitespace-nowrap text-foreground"
        :class="size === 's' ? 'gap-[5px] text-xs' : 'gap-1.5 text-sm'"
    >
        <span
            aria-hidden="true"
            class="shrink-0 rounded-full"
            :class="[dots[variant], size === 's' ? 'size-2' : 'size-2.5']"
        />
        <slot />
    </span>
</template>
