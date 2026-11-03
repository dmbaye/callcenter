<script setup lang="ts">
import { CircleCheck } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
</script>

<template>
    <Alert
        class="mb-6 border-positive-border bg-positive-subtle text-positive-strong"
        role="status"
    >
        <CircleCheck />
        <AlertDescription class="text-positive-strong">
            <slot />
        </AlertDescription>
    </Alert>
</template>
