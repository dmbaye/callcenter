<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Download, Play } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { formatDuration } from '@/lib/format';

/**
 * Call recording in the call history: a "Écouter" button that turns into
 * an audio player (the file is only fetched once asked for).
 */
defineProps<{
    url: string;
    duration: number | null;
    open: boolean;
}>();

defineEmits<{ play: [] }>();
</script>

<template>
    <div class="flex items-center gap-1.5">
        <audio
            v-if="open"
            :src="url"
            controls
            autoplay
            preload="none"
            class="h-8 w-56 max-w-full"
        />
        <Button
            v-else
            size="sm"
            variant="outline"
            :aria-label="`Écouter l'enregistrement${duration ? ` (${formatDuration(duration)})` : ''}`"
            @click="$emit('play')"
        >
            <Play />
            {{ duration ? formatDuration(duration) : t('Écouter') }}
        </Button>
        <Button
            as="a"
            :href="`${url}?download=1`"
            size="icon-sm"
            variant="ghost"
            :aria-label="t('Télécharger l\'enregistrement')"
        >
            <Download />
        </Button>
    </div>
</template>
