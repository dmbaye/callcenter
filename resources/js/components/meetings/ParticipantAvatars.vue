<script setup lang="ts">
import { computed } from 'vue';
import { avatarTone, initials } from '@/lib/avatar';

/** Overlapping initials, "+3" beyond `max`. Colours are stable per person. */
const props = withDefaults(
    defineProps<{
        people: { id?: number; name: string }[];
        max?: number;
        size?: 'sm' | 'md';
    }>(),
    { max: 4, size: 'sm' },
);

const shown = computed(() => props.people.slice(0, props.max));
const rest = computed(() => props.people.length - shown.value.length);
const sizeClass = computed(() =>
    props.size === 'md' ? 'size-8 text-xs' : 'size-7 text-[10px]',
);
</script>

<template>
    <div class="flex items-center">
        <span
            v-for="person in shown"
            :key="person.id ?? person.name"
            class="-ml-1 flex items-center justify-center rounded-full font-bold tracking-tight ring-2 ring-background first:ml-0"
            :class="[sizeClass, avatarTone(person.name)]"
            :title="person.name"
            >{{ initials(person.name) }}</span
        >
        <span
            v-if="rest > 0"
            class="-ml-1 flex items-center justify-center rounded-full bg-muted font-semibold text-muted-foreground ring-2 ring-background"
            :class="sizeClass"
            :title="`${rest} autre(s)`"
            >+{{ rest }}</span
        >
    </div>
</template>
