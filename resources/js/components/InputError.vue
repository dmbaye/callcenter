<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="text-sm text-negative">
            {{ message }}
        </p>
    </div>
</template>
