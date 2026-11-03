<script setup lang="ts">
import { STATUS_TONES } from '@/lib/workforce';

defineProps<{ status: string; label: string }>();
</script>

<template>
    <span
        class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="STATUS_TONES[status] ?? 'bg-muted text-muted-foreground'"
    >
        {{ label }}
    </span>
</template>
