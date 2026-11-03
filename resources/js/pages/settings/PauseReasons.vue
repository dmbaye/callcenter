<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Check,
    CirclePause,
    Pencil,
    Plus,
    Trash2,
    X,
} from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { destroy, index, reorder, store, update } from '@/routes/pause-reasons';

type Reason = { id: number; name: string; inUse: number };

const props = defineProps<{
    reasons: Reason[];
    max: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Motifs de pause', href: index() }],
    },
});

const addForm = useForm({ name: '' });

const add = () =>
    addForm.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });

const editing = ref<number | null>(null);
const editForm = useForm({ name: '' });

const startEdit = (reason: Reason) => {
    editing.value = reason.id;
    editForm.clearErrors();
    editForm.name = reason.name;
};

const saveEdit = (reason: Reason) =>
    editForm.put(update.url(reason.id), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });

const move = (from: number, to: number) => {
    if (to < 0 || to >= props.reasons.length) {
        return;
    }

    const ids = props.reasons.map((reason) => reason.id);
    const [moved] = ids.splice(from, 1);
    ids.splice(to, 0, moved);

    router.put(reorder.url(), { ids }, { preserveScroll: true });
};

const remove = (reason: Reason) => {
    const warning =
        reason.inUse > 0
            ? `\n${reason.inUse} agent(s) l'utilisent en ce moment : ils resteront en « Non prêt – ${reason.name} » jusqu'à leur prochain changement.`
            : '';

    if (!window.confirm(`Supprimer le motif « ${reason.name} » ?${warning}`)) {
        return;
    }

    router.delete(destroy.url(reason.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="t('Motifs de pause')" />

    <h1 class="sr-only">{{ t('Motifs de pause') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Motifs de pause')"
            :description="
                t(
                    'Les motifs proposés aux agents sous « Non prêt » dans leur menu de statut. Ils apparaissent dans la supervision et dans les rapports de temps de pause.',
                )
            "
        />

        <form class="flex items-start gap-2" @submit.prevent="add">
            <div class="flex-1">
                <Input
                    v-model="addForm.name"
                    maxlength="60"
                    :placeholder="t('Nouveau motif, ex. Rappel client')"
                    :aria-label="t('Nouveau motif')"
                    :disabled="reasons.length >= max"
                />
                <InputError class="mt-1" :message="addForm.errors.name" />
            </div>
            <Button
                type="submit"
                :disabled="
                    addForm.processing ||
                    !addForm.name.trim() ||
                    reasons.length >= max
                "
            >
                <Plus class="size-4" />
                {{ t('Ajouter') }}
            </Button>
        </form>

        <ul class="divide-y rounded-md border">
            <li
                v-for="(reason, position) in reasons"
                :key="reason.id"
                class="flex items-center gap-3 px-3 py-2.5"
            >
                <CirclePause class="size-4 shrink-0 text-notice" />

                <form
                    v-if="editing === reason.id"
                    class="flex flex-1 items-start gap-2"
                    @submit.prevent="saveEdit(reason)"
                >
                    <div class="flex-1">
                        <Input
                            v-model="editForm.name"
                            maxlength="60"
                            :aria-label="t('Nom du motif')"
                            autofocus
                        />
                        <InputError
                            class="mt-1"
                            :message="editForm.errors.name"
                        />
                    </div>
                    <Button
                        type="submit"
                        size="icon"
                        variant="ghost"
                        title="Enregistrer"
                        :disabled="editForm.processing || !editForm.name.trim()"
                    >
                        <Check class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        title="Annuler"
                        @click="editing = null"
                    >
                        <X class="size-4" />
                    </Button>
                </form>

                <template v-else>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ t('Non prêt – :p0', { p0: reason.name }) }}
                        </p>
                        <p
                            v-if="reason.inUse > 0"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(':p0 agent(s) en ce moment', {
                                    p0: reason.inUse,
                                })
                            }}
                        </p>
                    </div>

                    <div class="flex items-center gap-0.5">
                        <Button
                            size="icon"
                            variant="ghost"
                            title="Monter"
                            :disabled="position === 0"
                            @click="move(position, position - 1)"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            title="Descendre"
                            :disabled="position === reasons.length - 1"
                            @click="move(position, position + 1)"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            title="Renommer"
                            @click="startEdit(reason)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            title="Supprimer"
                            class="text-negative hover:text-negative"
                            @click="remove(reason)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </template>
            </li>

            <li
                v-if="reasons.length === 0"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                {{ t('Aucun motif : les agents ne verront que « Non prêt ».') }}
            </li>
        </ul>

        <p class="text-xs text-muted-foreground">
            {{
                t(
                    '« Prêt », « Non prêt », « Traitement post-appel », « Ne pas déranger » et « Déconnexion » sont toujours proposés. Raccourcis agents : Ctrl+Alt+R (Prêt), Ctrl+Alt+N (Non prêt), Ctrl+Alt+Z (Post-appel).',
                )
            }}
        </p>
    </div>
</template>
