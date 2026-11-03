<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Input } from '@/components/ui/input';

type Option = { value: string; label: string };

defineProps<{
    id: string;
    destinations: Option[];
    queues: { id: number; name: string }[];
    ivrMenus: { id: number; name: string }[];
    allowEmpty?: boolean;
    emptyLabel?: string;
}>();

const type = defineModel<string | null>('type', { default: null });
const target = defineModel<string | null>('target', { default: null });

function onType(event: Event) {
    type.value = (event.target as HTMLSelectElement).value || null;
    target.value = null;
}
</script>

<template>
    <div class="grid gap-2 sm:grid-cols-2">
        <select
            :id="id"
            class="h-9 rounded-md border bg-background px-3 text-sm"
            :value="type ?? ''"
            aria-label="Destination"
            @change="onType"
        >
            <option v-if="allowEmpty" value="">
                {{ emptyLabel ?? 'Aucune (raccrocher)' }}
            </option>
            <option v-for="d in destinations" :key="d.value" :value="d.value">
                {{ d.label }}
            </option>
        </select>

        <select
            v-if="type === 'queue' || type === 'callback'"
            v-model="target"
            class="h-9 rounded-md border bg-background px-3 text-sm"
            aria-label="File"
        >
            <option :value="null" disabled>{{ t('Choisir une file') }}</option>
            <option v-for="q in queues" :key="q.id" :value="String(q.id)">
                {{ q.name }}
            </option>
        </select>
        <select
            v-else-if="type === 'ivr'"
            v-model="target"
            class="h-9 rounded-md border bg-background px-3 text-sm"
            :aria-label="t('Menu vocal')"
        >
            <option :value="null" disabled>{{ t('Choisir un menu') }}</option>
            <option v-for="m in ivrMenus" :key="m.id" :value="String(m.id)">
                {{ m.name }}
            </option>
        </select>
        <Input
            v-else-if="type === 'extension'"
            :model-value="target ?? ''"
            :placeholder="t('Poste (ex. 7001)')"
            aria-label="Poste"
            @update:model-value="target = String($event) || null"
        />
        <Input
            v-else-if="type === 'message'"
            :model-value="target ?? ''"
            :placeholder="t('Son Asterisk (ex. custom/fermeture)')"
            aria-label="Message"
            @update:model-value="target = String($event) || null"
        />
    </div>
</template>
