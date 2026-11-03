<script setup lang="ts">
import { ref } from 'vue';
import { useStoryboard } from '@/composables/useStoryboard';
import BrowserFrame from './BrowserFrame.vue';
import DashboardMock from './DashboardMock.vue';

/** The live dashboard on its own, figures updating while visible. */
const root = ref<HTMLElement | null>(null);
const { step, tick } = useStoryboard(root, 4, 2600);
</script>

<template>
    <div
        ref="root"
        class="mk-stage rounded-[28px] p-4 sm:p-8"
        aria-hidden="true"
    >
        <BrowserFrame>
            <DashboardMock :step="step" :tick="tick" />
        </BrowserFrame>
    </div>
</template>
