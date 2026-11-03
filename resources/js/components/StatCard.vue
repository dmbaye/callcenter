<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

defineProps<{
    label: string;
    value: string | number;
    hint?: string;
    tone?: 'default' | 'good' | 'warn' | 'bad' | 'info';
}>();

const tones = {
    default: 'text-[var(--heading)]',
    good: 'text-positive-strong',
    warn: 'text-notice-strong',
    bad: 'text-negative-strong',
    info: 'text-informative-strong',
};

const accents = {
    default: 'bg-transparent',
    good: 'bg-positive',
    warn: 'bg-notice',
    bad: 'bg-negative',
    info: 'bg-informative',
};
</script>

<template>
    <Card class="relative gap-0 overflow-hidden py-0">
        <span
            aria-hidden="true"
            class="absolute inset-x-0 top-0 h-1"
            :class="accents[tone ?? 'default']"
        />
        <CardContent class="flex flex-col gap-1 p-4">
            <div class="text-sm text-muted-foreground">{{ label }}</div>
            <div
                class="text-[28px] leading-tight font-extrabold tabular-nums"
                :class="tones[tone ?? 'default']"
            >
                {{ value }}
            </div>
            <div v-if="hint" class="text-xs text-muted-foreground">
                {{ hint }}
            </div>
        </CardContent>
    </Card>
</template>
