<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { CampaignStatus } from '@/types';

defineProps<{ status: CampaignStatus; label: string }>();

const tones: Record<CampaignStatus, string> = {
    draft: 'bg-muted text-foreground',
    running: 'bg-positive text-white',
    paused: 'bg-notice text-foreground',
    completed: 'bg-informative text-white',
};
</script>

<template>
    <Badge class="border-transparent" :class="tones[status]">{{ label }}</Badge>
</template>
