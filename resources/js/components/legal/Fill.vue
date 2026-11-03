<script setup lang="ts">
import { TODO } from '@/components/legal/types';

/** A config/legal.php value, or a highlighted « [à compléter] ». */
defineProps<{ value?: string | number | null }>();
</script>

<template>
    <template
        v-if="
            value !== null && value !== undefined && String(value).trim() !== ''
        "
        >{{ value }}</template
    >
    <span v-else class="legal-todo">{{ TODO }}</span>
</template>
