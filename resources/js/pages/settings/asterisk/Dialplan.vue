<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CallFlowNode from '@/components/routing/CallFlowNode.vue';
import { index as ivr } from '@/routes/asterisk/ivr';
import { destroy, index, store, update } from '@/routes/asterisk/dialplan';
import type { CallFlowNode as FlowNode, DialplanRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: "Plan d'appel",
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    flow: { numbers: FlowNode[]; unreached: FlowNode[] };
    rows: DialplanRow[];
    teamContext: string;
}>();

const customCount = props.rows.filter((row) => !row.managed).length;
const expertOpen = ref(customCount > 0);

type RowKey = { context: string; exten: string; priority: number };

const emptyForm = {
    context: props.teamContext,
    exten: '',
    priority: '',
    app: '',
    appdata: '',
};

const form = useForm({ ...emptyForm });
const editing = ref<RowKey | null>(null);

function editRow(row: DialplanRow): void {
    editing.value = {
        context: row.context,
        exten: row.exten,
        priority: row.priority,
    };
    form.context = row.context;
    form.exten = row.exten;
    form.priority = String(row.priority);
    form.app = row.app;
    form.appdata = row.appdata ?? '';
}

function cancelEdit(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function submit(): void {
    if (editing.value) {
        form.transform((data) => ({
            ...data,
            original_context: editing.value?.context,
            original_exten: editing.value?.exten,
            original_priority: editing.value?.priority,
        }));
        form.put(update.url(), {
            preserveScroll: true,
            onSuccess: cancelEdit,
        });

        return;
    }

    form.post(store.url(), { preserveScroll: true, onSuccess: cancelEdit });
}

function deleteRow(row: DialplanRow): void {
    if (
        !confirm(
            `Supprimer la ligne ${row.context} / ${row.exten} priorité ${row.priority} ?`,
        )
    ) {
        return;
    }

    router.delete(destroy.url(), {
        data: {
            context: row.context,
            exten: row.exten,
            priority: row.priority,
        },
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('Plan d\'appel')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Plan d\'appel')"
            :description="
                t(
                    'Ce qui arrive à un appel, du numéro composé jusqu\'à l\'agent. Généré à partir des pages Files d\'attente et Numéros et SVI : rien à écrire ici.',
                )
            "
        />

        <section class="space-y-3">
            <div
                v-if="flow.numbers.length === 0"
                class="rounded-md border border-dashed p-4 text-sm text-muted-foreground"
            >
                {{ t("Aucun numéro entrant n'est configuré.") }}
                <Link
                    :href="ivr().url"
                    class="text-primary underline-offset-2 hover:underline"
                    >{{ t('Ajouter un numéro') }}</Link
                >
                {{
                    t(
                        'pour que les appels de votre opérateur arrivent dans vos files.',
                    )
                }}
            </div>
            <div
                v-for="(node, i) in flow.numbers"
                :key="i"
                class="rounded-lg border p-2"
            >
                <CallFlowNode :node="node" />
            </div>
        </section>

        <section v-if="flow.unreached.length" class="space-y-3">
            <h3 class="text-sm font-semibold">
                {{ t('Non reliés à un numéro') }}
            </h3>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Joignables uniquement par transfert ou depuis un autre menu.',
                    )
                }}
            </p>
            <div
                v-for="(node, i) in flow.unreached"
                :key="i"
                class="rounded-lg border p-2"
            >
                <CallFlowNode :node="node" />
            </div>
        </section>

        <details class="rounded-lg border p-4" :open="expertOpen">
            <summary class="cursor-pointer text-sm font-semibold">
                {{ t('Mode expert : lignes de dialplan') }}
                <span class="font-normal text-muted-foreground">{{
                    t('(:p0 ligne(s) personnalisée(s))', { p0: customCount })
                }}</span>
            </summary>
            <p class="mt-2 text-xs text-muted-foreground">
                {{
                    t(
                        "Pour les besoins non couverts par les pages ci-dessus. Les lignes grisées sont écrites par l'application (appels internes, files, menus) et ne se modifient pas ici. Contexte :",
                    )
                }}
                <span class="font-mono">{{ teamContext }}</span
                >.
            </p>

            <form @submit.prevent="submit" class="mt-4 space-y-4">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                    <div class="grid gap-2">
                        <Label for="context">{{ t('Contexte') }}</Label>
                        <Input
                            id="context"
                            v-model="form.context"
                            disabled
                            required
                        />
                        <InputError :message="form.errors.context" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="exten">{{ t('Poste') }}</Label>
                        <Input id="exten" v-model="form.exten" required />
                        <InputError :message="form.errors.exten" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="priority">{{ t('Priorité') }}</Label>
                        <Input
                            id="priority"
                            v-model="form.priority"
                            type="number"
                            min="1"
                            max="999"
                            required
                        />
                        <InputError :message="form.errors.priority" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="app">{{ t('Application') }}</Label>
                        <Input
                            id="app"
                            v-model="form.app"
                            placeholder="File"
                            required
                        />
                        <InputError :message="form.errors.app" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="appdata">{{ t('Arguments') }}</Label>
                        <Input
                            id="appdata"
                            v-model="form.appdata"
                            :placeholder="t('support,Tr')"
                        />
                        <InputError :message="form.errors.appdata" />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button :disabled="form.processing">{{
                        editing ? 'Enregistrer' : t('Ajouter la ligne')
                    }}</Button>
                    <Button
                        v-if="editing"
                        type="button"
                        variant="outline"
                        @click="cancelEdit"
                        >{{ t('Annuler') }}</Button
                    >
                </div>
            </form>

            <div class="overflow-x-auto rounded-[var(--card-radius)] border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Contexte') }}</TableHead>
                            <TableHead>{{ t('Poste') }}</TableHead>
                            <TableHead>{{ t('Priorité') }}</TableHead>
                            <TableHead>{{ t('Application') }}</TableHead>
                            <TableHead>{{ t('Arguments') }}</TableHead>
                            <TableHead class="w-32 text-right">{{
                                t('Actions')
                            }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="row in rows"
                            :key="`${row.context}-${row.exten}-${row.priority}`"
                            :class="row.managed ? 'opacity-60' : ''"
                        >
                            <TableCell class="font-mono text-xs">{{
                                row.context
                            }}</TableCell>
                            <TableCell class="font-mono text-xs">{{
                                row.exten
                            }}</TableCell>
                            <TableCell class="font-mono text-xs">{{
                                row.priority
                            }}</TableCell>
                            <TableCell class="font-mono text-xs">{{
                                row.app
                            }}</TableCell>
                            <TableCell
                                class="max-w-56 truncate font-mono text-xs"
                                >{{ row.appdata }}</TableCell
                            >
                            <TableCell
                                v-if="row.managed"
                                class="text-right text-xs text-muted-foreground"
                            >
                                {{ t("Géré par l'application") }}
                            </TableCell>
                            <TableCell v-else class="space-x-2 text-right">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="editRow(row)"
                                    >{{ t('Modifier') }}</Button
                                >
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    @click="deleteRow(row)"
                                    >{{ t('Supprimer') }}</Button
                                >
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </details>
    </div>
</template>
