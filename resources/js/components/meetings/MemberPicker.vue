<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Check, Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { avatarTone, initials } from '@/lib/avatar';

type Member = { id: number; name: string; role?: string };

const props = defineProps<{
    members: Member[];
}>();

const selected = defineModel<number[]>({ default: () => [] });
const search = ref('');

const filtered = computed(() => {
    const term = search.value
        .trim()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

    return term
        ? props.members.filter((m) =>
              m.name
                  .normalize('NFD')
                  .replace(/[̀-ͯ]/g, '')
                  .toLowerCase()
                  .includes(term),
          )
        : props.members;
});

const chosen = computed(() =>
    props.members.filter((member) => selected.value.includes(member.id)),
);

function toggle(id: number) {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((value) => value !== id)
        : [...selected.value, id];
}

function selectAll() {
    selected.value = props.members.map((member) => member.id);
}
</script>

<template>
    <div class="space-y-2">
        <div v-if="chosen.length" class="flex flex-wrap gap-1.5">
            <span
                v-for="member in chosen"
                :key="member.id"
                class="inline-flex items-center gap-1.5 rounded-full border bg-background py-0.5 pr-1 pl-0.5 text-xs font-medium"
            >
                <span
                    class="flex size-5 items-center justify-center rounded-full text-[9px] font-bold"
                    :class="avatarTone(member.name)"
                    >{{ initials(member.name) }}</span
                >
                {{ member.name }}
                <button
                    type="button"
                    class="rounded-full p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                    :aria-label="`Retirer ${member.name}`"
                    @click="toggle(member.id)"
                >
                    <X class="size-3" />
                </button>
            </span>
        </div>

        <div class="overflow-hidden rounded-[var(--control-radius)] border">
            <div class="flex items-center gap-2 border-b px-3">
                <Search class="size-4 shrink-0 text-muted-foreground" />
                <input
                    v-model="search"
                    type="search"
                    class="h-9 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    :placeholder="t('Rechercher un membre de l’équipe')"
                    :aria-label="t('Rechercher un membre')"
                />
                <button
                    v-if="
                        members.length > 1 && selected.length < members.length
                    "
                    type="button"
                    class="shrink-0 text-xs font-medium text-primary hover:underline"
                    @click="selectAll"
                >
                    {{ t('Tout le monde') }}
                </button>
            </div>
            <ul
                class="max-h-52 overflow-y-auto py-1"
                role="listbox"
                aria-multiselectable="true"
            >
                <li v-for="member in filtered" :key="member.id">
                    <button
                        type="button"
                        role="option"
                        :aria-selected="selected.includes(member.id)"
                        class="flex w-full items-center gap-3 px-3 py-1.5 text-left text-sm hover:bg-accent"
                        @click="toggle(member.id)"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full text-[10px] font-bold"
                            :class="avatarTone(member.name)"
                            >{{ initials(member.name) }}</span
                        >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                member.name
                            }}</span>
                            <span
                                v-if="member.role"
                                class="block text-xs text-muted-foreground"
                                >{{ member.role }}</span
                            >
                        </span>
                        <span
                            class="flex size-5 shrink-0 items-center justify-center rounded border"
                            :class="
                                selected.includes(member.id)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-input'
                            "
                        >
                            <Check
                                v-if="selected.includes(member.id)"
                                class="size-3.5"
                            />
                        </span>
                    </button>
                </li>
                <li
                    v-if="filtered.length === 0"
                    class="px-3 py-4 text-center text-sm text-muted-foreground"
                >
                    {{
                        members.length === 0
                            ? t('Aucun autre membre dans l’équipe.')
                            : t('Aucun membre ne correspond.')
                    }}
                </li>
            </ul>
        </div>
    </div>
</template>
