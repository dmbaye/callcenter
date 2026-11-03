<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { index as campaigns } from '@/routes/campaigns';
import { destroy, index, store } from '@/routes/campaigns/dnc';
import type { Paginated, Team } from '@/types';

type DncRow = {
    id: number;
    phone: string;
    reason: string | null;
    addedBy: string | null;
    createdAt: string | null;
};

defineProps<{
    numbers: Paginated<DncRow>;
    search: string;
    canManage: boolean;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Campagnes',
                href: props.currentTeam
                    ? campaigns(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const query = ref('');

const form = useForm({ numbers: '', reason: '' });

function add() {
    form.post(store(slug.value).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function find(pageNumber?: number) {
    router.get(
        index(slug.value).url,
        { search: query.value || undefined, page: pageNumber },
        { preserveState: true, only: ['numbers', 'search'] },
    );
}

function remove(row: DncRow) {
    router.delete(destroy({ current_team: slug.value, number: row.id }).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('Ne pas appeler')" />

    <div class="flex max-w-4xl flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="t('Liste « Ne pas appeler »')"
            :description="
                t(
                    'Ces numéros ne sont jamais composés, quelle que soit la campagne. Les contacts qui demandent à ne plus être appelés y sont ajoutés automatiquement.',
                )
            "
        />

        <Card v-if="canManage">
            <CardHeader
                ><CardTitle>{{
                    t('Ajouter des numéros')
                }}</CardTitle></CardHeader
            >
            <CardContent>
                <form class="grid gap-3" @submit.prevent="add">
                    <div class="grid gap-2">
                        <Label for="numbers">{{
                            t('Un numéro par ligne')
                        }}</Label>
                        <Textarea
                            id="numbers"
                            v-model="form.numbers"
                            rows="4"
                            placeholder="76 12 34 56&#10;+223 66 00 11 22"
                        />
                        <InputError :message="form.errors.numbers" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="reason">{{
                            t('Motif (facultatif)')
                        }}</Label>
                        <Input
                            id="reason"
                            v-model="form.reason"
                            maxlength="160"
                        />
                    </div>
                    <Button
                        type="submit"
                        class="justify-self-start"
                        :disabled="form.processing || !form.numbers.trim()"
                        >{{ t('Ajouter') }}</Button
                    >
                </form>
            </CardContent>
        </Card>

        <div class="flex gap-2">
            <Input
                v-model="query"
                type="search"
                :placeholder="t('Rechercher un numéro')"
                class="max-w-xs"
                :aria-label="t('Rechercher un numéro')"
                @keyup.enter="find()"
            />
            <Button variant="outline" @click="find()">{{
                t('Rechercher')
            }}</Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Numéro') }}</TableHead>
                        <TableHead>{{ t('Motif') }}</TableHead>
                        <TableHead>{{ t('Ajouté par') }}</TableHead>
                        <TableHead>{{ t('Date') }}</TableHead>
                        <TableHead v-if="canManage" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in numbers.data" :key="row.id">
                        <TableCell class="font-mono text-sm">{{
                            row.phone
                        }}</TableCell>
                        <TableCell class="text-sm">{{
                            row.reason ?? '—'
                        }}</TableCell>
                        <TableCell class="text-sm">{{
                            row.addedBy ?? '—'
                        }}</TableCell>
                        <TableCell class="text-sm">{{
                            formatDate(row.createdAt)
                        }}</TableCell>
                        <TableCell v-if="canManage" class="text-right">
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Retirer ${row.phone}`"
                                @click="remove(row)"
                                ><Trash2
                            /></Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <p
                v-if="numbers.data.length === 0"
                class="p-4 text-center text-sm text-muted-foreground"
            >
                {{ t('Aucun numéro.') }}
            </p>
        </div>
        <div v-if="numbers.last_page > 1" class="flex gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="numbers.current_page <= 1"
                @click="find(numbers.current_page - 1)"
                >{{ t('Précédent') }}</Button
            >
            <Button
                variant="outline"
                size="sm"
                :disabled="numbers.current_page >= numbers.last_page"
                @click="find(numbers.current_page + 1)"
                >{{ t('Suivant') }}</Button
            >
        </div>
    </div>
</template>
