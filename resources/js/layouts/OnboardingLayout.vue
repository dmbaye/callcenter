<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import AppLogo from '@/components/AppLogo.vue';

/**
 * Focused layout of the welcome wizard: no sidebar, no softphone, just the
 * steps. The app opens (with its guided tour) once the wizard is done.
 */
</script>

<template>
    <div class="min-h-svh bg-muted/60">
        <header
            class="mx-auto flex h-16 max-w-3xl items-center px-4 sm:px-6"
        >
            <div class="flex items-center">
                <AppLogo />
            </div>
        </header>
        <main class="mx-auto max-w-3xl px-4 pb-16 sm:px-6">
            <slot />
        </main>
        <Toaster />
    </div>
</template>
