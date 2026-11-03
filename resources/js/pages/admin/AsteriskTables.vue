<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Download, Plus, Search, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { platform } from '@/routes/admin';
import {
    binary,
    destroy,
    index,
    store,
    update,
} from '@/routes/admin/asterisk-tables';

type Column = {
    name: string;
    type: string;
    nullable: boolean;
    binary: boolean;
    auto: boolean;
    key: boolean;
};
type Cell = string | number | boolean | null;
type Row = Record<string, Cell>;
type Page = {
    data: Row[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    error: string | null;
    tables: { name: string; group: string; log: boolean }[];
    table?: string | null;
    search?: string;
    isLog?: boolean;
    columns?: Column[];
    primaryKey?: string[];
    rows?: Page | null;
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Plateforme', href: platform().url },
            { title: 'Tables Asterisk', href: index().url },
        ],
    }),
});

const groups = computed(() => {
    const byGroup: Record<string, { name: string; log: boolean }[]> = {};

    for (const table of props.tables) {
        (byGroup[table.group] ??= []).push(table);
    }

    return byGroup;
});

const plainColumns = computed(() =>
    (props.columns ?? []).filter((c) => !c.binary),
);
const binaryColumns = computed(() =>
    (props.columns ?? []).filter((c) => c.binary),
);

/* --- Navigation ------------------------------------------------------ */

const searchText = ref(props.search ?? '');

function go(params: Record<string, string | number | undefined>): void {
    router.get(index().url, params, { preserveState: false });
}

function openTable(name: string): void {
    go({ table: name });
}

function runSearch(): void {
    go({ table: props.table ?? undefined, search: searchText.value || undefined });
}

function goPage(url: string | null): void {
    if (url) {
        router.get(url, {}, { preserveScroll: true });
    }
}

watch(
    () => props.search,
    (value) => (searchText.value = value ?? ''),
);

/* --- Editing --------------------------------------------------------- */

/** The row as shown: identifies it on save/delete (key, or every value). */
const editing = ref<Row | null>(null);
const creating = ref(false);
const open = ref(false);

const form = useForm<{ values: Record<string, string>; row: Row }>({
    values: {},
    row: {},
});

function display(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

function identityOf(row: Row): Row {
    const keys = props.primaryKey?.length
        ? props.primaryKey
        : plainColumns.value.map((c) => c.name);

    return Object.fromEntries(keys.map((k) => [k, row[k] ?? null]));
}

function startCreate(): void {
    creating.value = true;
    editing.value = null;
    form.clearErrors();
    form.values = Object.fromEntries(plainColumns.value.map((c) => [c.name, '']));
    form.row = {};
    open.value = true;
}

function startEdit(row: Row): void {
    creating.value = false;
    editing.value = row;
    form.clearErrors();
    form.values = Object.fromEntries(
        plainColumns.value.map((c) => [c.name, display(row[c.name])]),
    );
    form.row = identityOf(row);
    open.value = true;
}

function confirmLog(): boolean {
    return (
        !props.isLog ||
        window.confirm(
            t(
                'Cette table est un journal écrit par Asterisk. La modifier change l’historique des appels et la facturation des appels sortants. Continuer ?',
            ),
        )
    );
}

function save(): void {
    if (!props.table || !confirmLog()) {
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (creating.value) {
        form.transform((data) => ({ values: data.values })).post(
            store(props.table).url,
            options,
        );
    } else {
        form.put(update(props.table).url, options);
    }
}

function remove(): void {
    if (!props.table || !editing.value) {
        return;
    }

    if (!window.confirm(t('Supprimer cette ligne ?')) || !confirmLog()) {
        return;
    }

    router.delete(destroy(props.table).url, {
        data: { row: identityOf(editing.value) },
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

function binaryUrl(row: Row, column: string): string {
    return binary(props.table ?? '', {
        query: { row: JSON.stringify(identityOf(row)), column },
    }).url;
}

function size(row: Row, column: string): number {
    return Number(row[`__size_${column}`] ?? 0);
}

const rowErrors = computed(
    () =>
        (form.errors as Record<string, string | undefined>).row ??
        (form.errors as Record<string, string | undefined>).values,
);
</script>

<template>
    <Head :title="t('Tables Asterisk')" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-8">
        <Heading
            :title="t('Tables Asterisk')"
            :description="
                t(
                    'Lecture et modification directes de la base temps réel d’Asterisk. Réservé aux administrateurs de la plateforme ; chaque modification est journalisée.',
                )
            "
        />

        <p
            v-if="error"
            class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-destructive" />
            {{ t('La base Asterisk est injoignable :') }} {{ error }}
        </p>

        <div v-else class="grid gap-6 lg:grid-cols-[14rem_1fr]">
            <!-- Tables, by group -->
            <nav class="space-y-4" :aria-label="t('Tables')">
                <select
                    class="h-9 w-full rounded-md border bg-background px-2 text-sm lg:hidden"
                    :value="table ?? ''"
                    :aria-label="t('Table')"
                    @change="
                        openTable(($event.target as HTMLSelectElement).value)
                    "
                >
                    <optgroup
                        v-for="(items, group) in groups"
                        :key="group"
                        :label="t(String(group))"
                    >
                        <option v-for="i in items" :key="i.name" :value="i.name">
                            {{ i.name }}
                        </option>
                    </optgroup>
                </select>

                <div
                    v-for="(items, group) in groups"
                    :key="group"
                    class="hidden space-y-1 lg:block"
                >
                    <p
                        class="px-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ t(String(group)) }}
                    </p>
                    <button
                        v-for="i in items"
                        :key="i.name"
                        type="button"
                        class="block w-full rounded-md px-2 py-1.5 text-left font-mono text-sm hover:bg-muted"
                        :class="i.name === table ? 'bg-muted font-semibold' : ''"
                        @click="openTable(i.name)"
                    >
                        {{ i.name }}
                    </button>
                </div>
            </nav>

            <section v-if="table && rows" class="min-w-0 space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto font-mono text-lg font-semibold">
                        {{ table }}
                        <span class="text-sm font-normal text-muted-foreground"
                            >· {{ rows.total }} {{ t('lignes') }}</span
                        >
                    </h2>
                    <form class="flex gap-2" @submit.prevent="runSearch">
                        <Input
                            v-model="searchText"
                            :placeholder="t('Rechercher dans toutes les colonnes')"
                            class="w-64"
                        />
                        <Button type="submit" variant="outline" size="icon">
                            <Search class="size-4" />
                            <span class="sr-only">{{ t('Rechercher') }}</span>
                        </Button>
                    </form>
                    <Button @click="startCreate">
                        <Plus class="size-4" /> {{ t('Nouvelle ligne') }}
                    </Button>
                </div>

                <p
                    v-if="isLog"
                    class="flex items-start gap-2 rounded-md border border-notice/50 bg-notice/10 p-3 text-sm"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{
                        t(
                            'Journal écrit par Asterisk : les modifications changent l’historique des appels, les statistiques et la facturation des appels sortants.',
                        )
                    }}
                </p>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-xs">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th
                                    v-for="c in columns"
                                    :key="c.name"
                                    class="px-2 py-2 font-mono font-medium whitespace-nowrap"
                                    :title="c.type"
                                >
                                    {{ c.name }}
                                    <span v-if="c.key" class="text-primary">*</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, i) in rows.data"
                                :key="i"
                                class="cursor-pointer border-t hover:bg-muted/40"
                                @click="startEdit(row)"
                            >
                                <td
                                    v-for="c in columns"
                                    :key="c.name"
                                    class="max-w-56 truncate px-2 py-1.5 font-mono"
                                    :title="c.binary ? '' : display(row[c.name])"
                                >
                                    <template v-if="c.binary">
                                        <a
                                            v-if="size(row, c.name) > 0"
                                            :href="binaryUrl(row, c.name)"
                                            class="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline"
                                            @click.stop
                                        >
                                            <Download class="size-3" />
                                            {{ Math.ceil(size(row, c.name) / 1024) }} Ko
                                        </a>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </template>
                                    <span
                                        v-else-if="row[c.name] === null"
                                        class="text-muted-foreground"
                                        >NULL</span
                                    >
                                    <template v-else>{{ display(row[c.name]) }}</template>
                                </td>
                            </tr>
                            <tr v-if="rows.data.length === 0">
                                <td
                                    :colspan="columns?.length ?? 1"
                                    class="px-2 py-6 text-center text-sm text-muted-foreground"
                                >
                                    {{ t('Aucune ligne.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="rows.last_page > 1"
                    class="flex items-center justify-between text-sm"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!rows.prev_page_url"
                        @click="goPage(rows.prev_page_url)"
                        >{{ t('Précédent') }}</Button
                    >
                    <span class="text-muted-foreground">
                        {{
                            t('Page :p0 sur :p1', {
                                p0: rows.current_page,
                                p1: rows.last_page,
                            })
                        }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!rows.next_page_url"
                        @click="goPage(rows.next_page_url)"
                        >{{ t('Suivant') }}</Button
                    >
                </div>
            </section>

            <p v-else class="text-sm text-muted-foreground">
                {{
                    t(
                        'Aucune des tables gérées n’existe dans la base Asterisk.',
                    )
                }}
            </p>
        </div>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="font-mono">
                    {{ creating ? t('Nouvelle ligne') : t('Modifier la ligne') }}
                    · {{ table }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'Vide = NULL quand la colonne l’accepte. * = clé primaire.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <div v-for="c in plainColumns" :key="c.name" class="grid gap-1">
                    <Label :for="`col-${c.name}`" class="font-mono text-xs">
                        {{ c.name }}<span v-if="c.key" class="text-primary">*</span>
                        <span class="font-sans font-normal text-muted-foreground">
                            {{ c.type }}{{ c.nullable ? '' : ' · requis' }}{{
                                c.auto ? ' · auto' : ''
                            }}
                        </span>
                    </Label>
                    <Input
                        :id="`col-${c.name}`"
                        v-model="form.values[c.name]"
                        class="font-mono text-xs"
                        :placeholder="c.nullable ? 'NULL' : ''"
                    />
                </div>

                <div
                    v-for="c in editing ? binaryColumns : []"
                    :key="c.name"
                    class="grid gap-1 sm:col-span-2"
                >
                    <Label class="font-mono text-xs">{{ c.name }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Contenu binaire : téléchargeable, non modifiable ici.',
                            )
                        }}
                        <a
                            v-if="editing && size(editing, c.name) > 0"
                            :href="binaryUrl(editing, c.name)"
                            class="text-primary underline"
                            >{{ t('Télécharger') }}</a
                        >
                    </p>
                </div>

                <InputError :message="rowErrors" class="sm:col-span-2" />

                <DialogFooter class="gap-2 sm:col-span-2">
                    <Button
                        v-if="!creating"
                        type="button"
                        variant="destructive"
                        class="mr-auto"
                        @click="remove"
                    >
                        <Trash2 class="size-4" /> {{ t('Supprimer') }}
                    </Button>
                    <Button type="button" variant="outline" @click="open = false">
                        {{ t('Annuler') }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ t('Enregistrer') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
