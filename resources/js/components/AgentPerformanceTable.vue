<script setup lang="ts">
import { t } from '@/lib/i18n';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import StatusLight from '@/components/StatusLight.vue';
import { formatDuration } from '@/lib/format';
import type { RosterAgent } from '@/types';

defineProps<{ agents: RosterAgent[] }>();

const presenceLabel = {
    available: 'Connecté',
    paused: 'En pause',
    offline: 'Déconnecté',
};
const presenceVariant = {
    available: 'positive',
    paused: 'notice',
    offline: 'neutral',
} as const;
</script>

<template>
    <div class="overflow-x-auto rounded-[var(--card-radius)] border">
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{{ t('Agent') }}</TableHead>
                    <TableHead>{{ t('État de la file') }}</TableHead>
                    <TableHead class="text-right">{{
                        t('Répondus')
                    }}</TableHead>
                    <TableHead class="text-right">{{ t('Manqués') }}</TableHead>
                    <TableHead class="text-right">{{
                        t('Durée de conversation')
                    }}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="agent in agents" :key="agent.id">
                    <TableCell>
                        <div class="font-medium">{{ agent.name }}</div>
                        <div class="font-mono text-xs text-muted-foreground">
                            {{ agent.extension }}
                        </div>
                    </TableCell>
                    <TableCell>
                        <StatusLight :variant="presenceVariant[agent.presence]">
                            {{ presenceLabel[agent.presence] }}
                        </StatusLight>
                    </TableCell>
                    <TableCell class="text-right tabular-nums">{{
                        agent.answered
                    }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{
                        agent.missed
                    }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{
                        formatDuration(agent.talk_seconds)
                    }}</TableCell>
                </TableRow>
                <TableRow v-if="agents.length === 0">
                    <TableCell
                        colspan="5"
                        class="h-24 text-center text-muted-foreground"
                    >
                        {{ t("Aucun agent n'a encore de poste SIP.") }}
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
