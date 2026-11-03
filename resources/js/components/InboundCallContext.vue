<script setup lang="ts">
import { t } from '@/lib/i18n';
import { computed } from 'vue';
import type { InboundContext } from '@/composables/useInboundContext';
import { formatDuration, formatRelative } from '@/lib/format';

/**
 * Screen pop of the softphone: who is calling and why, as a short list of
 * label / value rows (queue, IVR choice, wait, last call, known client…).
 * Shown while the phone rings, during the call and in post-call work.
 */
const props = defineProps<{
    context: InboundContext | null;
    /** Rows for calls without a screen pop (outbound, campaign). */
    extra?: { label: string; value: string }[];
}>();

const outcomeLabels: Record<string, string> = {
    resolved: 'Résolu',
    callback: 'Rappel planifié',
    voicemail: 'Messagerie',
    sale: 'Vente',
    no_sale: 'Pas de vente',
    wrong_number: 'Mauvais numéro',
    other: 'Autre',
};

const rows = computed(() => {
    const context = props.context;
    const list: { label: string; value: string }[] = [...(props.extra ?? [])];

    if (!context) {
        return list;
    }

    // The name is already the card title; the company is not.
    if (context.contact?.company) {
        list.push({ label: 'Entreprise', value: context.contact.company });
    }

    list.push({ label: 'File', value: context.queue });

    if (context.choice) {
        list.push({ label: t('Choix SVI'), value: context.choice });
    }

    if (context.waitSeconds !== null) {
        list.push({
            label: 'Attente',
            value: formatDuration(context.waitSeconds),
        });
    }

    list.push({
        label: t('Dernier appel'),
        value: context.history.lastCallAt
            ? formatRelative(context.history.lastCallAt)
            : 'Premier appel',
    });

    if (context.history.missed90d > 0) {
        list.push({
            label: t('Manqués (90 j)'),
            value: String(context.history.missed90d),
        });
    }

    if (context.history.lastOutcome) {
        list.push({
            label: t('Dernier résultat'),
            value:
                outcomeLabels[context.history.lastOutcome] ??
                context.history.lastOutcome,
        });
    }

    return list;
});
</script>

<template>
    <div v-if="rows.length" class="space-y-1.5">
        <dl
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 rounded-lg bg-muted px-3 py-2.5 text-xs"
        >
            <template v-for="row in rows" :key="row.label">
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd class="truncate text-right font-semibold tabular-nums">
                    {{ row.value }}
                </dd>
            </template>
        </dl>
        <p
            v-if="context?.history.lastNotes"
            class="line-clamp-2 px-1 text-xs text-muted-foreground"
        >
            « {{ context.history.lastNotes }} »
        </p>
    </div>
</template>
