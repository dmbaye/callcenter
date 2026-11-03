<script setup lang="ts">
import { TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { shiftColor } from '@/lib/workforce';
import type { ShiftCard } from '@/types';

const props = defineProps<{
    shift: ShiftCard;
    removable?: boolean;
    compact?: boolean;
}>();

defineEmits<{ remove: [shift: ShiftCard] }>();

const tone = computed(() => shiftColor(props.shift.color).chip);
const title = computed(() =>
    [
        `${props.shift.name ?? props.shift.code} · ${props.shift.window}`,
        ...props.shift.warnings,
        props.shift.note,
    ]
        .filter(Boolean)
        .join('\n'),
);
</script>

<template>
    <span
        class="group inline-flex max-w-full items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-medium"
        :class="tone"
        :title="title"
    >
        <TriangleAlert
            v-if="shift.warnings.length"
            class="size-3 shrink-0 text-notice-strong"
            :aria-label="t('Règle dépassée')"
        />
        <span class="font-semibold">{{ shift.code }}</span>
        <span v-if="!compact" class="truncate tabular-nums opacity-75">{{
            shift.window
        }}</span>
        <button
            v-if="removable"
            type="button"
            class="-mr-0.5 rounded opacity-60 hover:bg-black/10 hover:opacity-100 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-60"
            :aria-label="t('Retirer ce poste')"
            @click.stop="$emit('remove', shift)"
        >
            <X class="size-3" />
        </button>
    </span>
</template>
