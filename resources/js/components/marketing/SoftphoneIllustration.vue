<script setup lang="ts">
import { ref } from 'vue';
import { useCallScene } from '@/composables/useCallScene';
import SoftphoneMock from './SoftphoneMock.vue';

/** The softphone alone, ringing, answered, then wrapped up. */
const root = ref<HTMLElement | null>(null);
const { phase, seconds } = useCallScene(root);
</script>

<template>
    <div
        ref="root"
        class="mk-stage flex justify-center rounded-[28px] px-5 py-8 sm:py-10"
        aria-hidden="true"
    >
        <div class="mk-float w-full max-w-72">
            <SoftphoneMock :phase="phase" :seconds="seconds" />
        </div>
    </div>
</template>
