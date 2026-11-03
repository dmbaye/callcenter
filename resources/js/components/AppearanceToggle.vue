<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

/**
 * Theme switch of the top bar: each click moves to the next theme,
 * Clair → Sombre → Système → Clair. The icon shows the current choice.
 */
const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', Icon: Sun, label: 'Clair' },
    { value: 'dark', Icon: Moon, label: 'Sombre' },
    { value: 'system', Icon: Monitor, label: 'Système' },
] as const;

const label = (option: (typeof options)[number]) => t(option.label);

const index = computed(() =>
    Math.max(
        0,
        options.findIndex((option) => option.value === appearance.value),
    ),
);
const current = computed(() => options[index.value]);
const next = computed(() => options[(index.value + 1) % options.length]);

function cycle(): void {
    updateAppearance(next.value.value);
}
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        :aria-label="
            t('Thème : :current. Passer au thème :next', {
                current: label(current),
                next: label(next),
            })
        "
        :title="
            t('Thème : :current (cliquer pour :next)', {
                current: label(current),
                next: label(next),
            })
        "
        data-test="appearance-toggle"
        @click="cycle"
    >
        <component :is="current.Icon" class="size-5" />
    </Button>
</template>
