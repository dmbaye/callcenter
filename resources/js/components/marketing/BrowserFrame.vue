<script setup lang="ts">
/** Window chrome around an app mockup (traffic lights + address bar). */
withDefaults(defineProps<{ url?: string }>(), {
    url: 'app.callcenter.ml',
});
</script>

<template>
    <div class="mk-mock overflow-hidden">
        <div
            class="flex items-center gap-3 border-b bg-[var(--mk-chrome)] px-4 py-2.5"
        >
            <div class="flex gap-1.5">
                <span class="size-2.5 rounded-full bg-[rgb(255_95_87)]" />
                <span class="size-2.5 rounded-full bg-[rgb(254_188_46)]" />
                <span class="size-2.5 rounded-full bg-[rgb(40_200_64)]" />
            </div>
            <div
                class="mx-auto flex h-6 w-full max-w-xs items-center justify-center rounded-md bg-background px-3 text-[11px] text-muted-foreground"
            >
                {{ url }}
            </div>
            <span class="w-10" />
        </div>
        <slot />
    </div>
</template>
