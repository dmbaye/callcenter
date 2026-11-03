<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SelectField from '@/components/SelectField.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { destroy, index, store, update } from '@/routes/asterisk/pjsip';
import type { PjsipEndpoint } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'PJSIP',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    endpoints: PjsipEndpoint[];
    members: { id: number; name: string }[];
    teamContext: string;
    accountcodeSupported: boolean;
}>();

// A Select item cannot have an empty value: "none" = not assigned.
const memberOptions = computed(() => [
    { value: 'none', label: t('Non attribué') },
    ...props.members.map((member) => ({
        value: String(member.id),
        label: member.name,
    })),
]);

const emptyForm = {
    extension: '',
    user_id: 'none',
    transport: 'transport-wss',
    allow: 'opus,ulaw,alaw',
    password: '',
    max_contacts: '1',
};

const form = useForm({ ...emptyForm });
const editingId = ref<string | null>(null);
const editingRow = computed(
    () => props.endpoints.find((e) => e.id === editingId.value) ?? null,
);

function editRow(endpoint: PjsipEndpoint): void {
    editingId.value = endpoint.id;
    form.extension = endpoint.extension;
    form.user_id =
        endpoint.user_id !== null ? String(endpoint.user_id) : 'none';
    form.transport = endpoint.transport ?? '';
    form.allow = endpoint.allow ?? '';
    form.password = '';
    form.max_contacts = String(endpoint.max_contacts ?? 1);
}

function cancelEdit(): void {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submit(): void {
    const payload = (data: typeof emptyForm) => ({
        ...data,
        user_id: data.user_id === 'none' ? null : Number(data.user_id),
    });

    if (editingRow.value) {
        form.transform(payload).put(update.url(), {
            preserveScroll: true,
            onSuccess: cancelEdit,
        });

        return;
    }

    form.transform(payload).post(store.url(), {
        preserveScroll: true,
        onSuccess: cancelEdit,
    });
}

function deleteRow(endpoint: PjsipEndpoint): void {
    if (
        !confirm(`Supprimer le poste ${endpoint.extension} (${endpoint.id}) ?`)
    ) {
        return;
    }

    router.delete(destroy.url(), {
        data: { extension: endpoint.extension },
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('Paramètres PJSIP')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Postes et points de terminaison PJSIP')"
            :description="
                t(
                    'Chaque poste de l\'équipe a un point de terminaison Asterisk {clé}_{poste} dans le contexte de l\'équipe. Attribuez-le à un membre pour qu\'il puisse utiliser le softphone.',
                )
            "
        />

        <Alert v-if="!accountcodeSupported" variant="destructive">
            <AlertDescription>
                {{
                    t(
                        "La table ps_endpoints n'a pas de colonne accountcode : les appels ne peuvent pas être rattachés à l'équipe dans le CDR (statistiques et historique vides). Ajoutez la colonne (schéma realtime Asterisk récent) puis enregistrez de nouveau les postes.",
                    )
                }}
            </AlertDescription>
        </Alert>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="extension">{{ t('Poste') }}</Label>
                    <Input
                        id="extension"
                        v-model="form.extension"
                        inputmode="numeric"
                        pattern="[0-9]{2,20}"
                        :disabled="editingRow !== null"
                        required
                    />
                    <InputError :message="form.errors.extension" />
                </div>
                <div class="grid gap-2">
                    <Label for="user_id">{{ t('Membre') }}</Label>
                    <SelectField
                        id="user_id"
                        v-model="form.user_id"
                        :options="memberOptions"
                    />
                    <InputError :message="form.errors.user_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="context">{{ t('Contexte') }}</Label>
                    <Input id="context" :model-value="teamContext" disabled />
                </div>
                <div class="grid gap-2">
                    <Label for="transport">{{ t('Transport') }}</Label>
                    <Input
                        id="transport"
                        v-model="form.transport"
                        :required="editingRow === null"
                    />
                    <InputError :message="form.errors.transport" />
                </div>
                <div class="grid gap-2">
                    <Label for="allow">{{ t('Codecs (autorisés)') }}</Label>
                    <Input
                        id="allow"
                        v-model="form.allow"
                        :required="editingRow === null"
                    />
                    <InputError :message="form.errors.allow" />
                </div>
                <div class="grid gap-2">
                    <Label for="password">{{ t('Mot de passe SIP') }}</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        minlength="8"
                        :placeholder="
                            editingRow ? t('Laisser vide pour conserver') : ''
                        "
                        :required="editingRow === null"
                    />
                    <InputError :message="form.errors.password" />
                </div>
                <div class="grid gap-2">
                    <Label for="max_contacts">{{ t('Contacts max.') }}</Label>
                    <Input
                        id="max_contacts"
                        v-model="form.max_contacts"
                        type="number"
                        min="1"
                        max="10"
                        required
                    />
                    <InputError :message="form.errors.max_contacts" />
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Button :disabled="form.processing">
                    {{ editingRow ? 'Enregistrer' : t('Ajouter le poste') }}
                </Button>
                <Button
                    v-if="editingRow"
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
                        <TableHead>{{ t('Poste') }}</TableHead>
                        <TableHead>{{ t('Membre') }}</TableHead>
                        <TableHead>{{ t('Transport') }}</TableHead>
                        <TableHead>{{ t('Contexte') }}</TableHead>
                        <TableHead>{{ t('Codecs') }}</TableHead>
                        <TableHead>{{ t('Contacts max.') }}</TableHead>
                        <TableHead class="w-32 text-right">{{
                            t('Actions')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="endpoint in endpoints" :key="endpoint.id">
                        <TableCell class="text-xs font-semibold">
                            {{ endpoint.extension }}
                            <div
                                class="font-mono font-normal text-muted-foreground"
                            >
                                {{ endpoint.id }}
                            </div>
                            <div
                                v-if="endpoint.missing"
                                class="font-normal text-destructive"
                            >
                                {{
                                    t(
                                        "Absent d'Asterisk — enregistrez pour le créer",
                                    )
                                }}
                            </div>
                            <div
                                v-else-if="!endpoint.linked"
                                class="font-normal text-muted-foreground"
                            >
                                {{ t('Créé hors application') }}
                            </div>
                        </TableCell>
                        <TableCell class="text-xs">{{
                            endpoint.user_name ?? '–'
                        }}</TableCell>
                        <TableCell class="font-mono text-xs">{{
                            endpoint.transport ?? '–'
                        }}</TableCell>
                        <TableCell class="font-mono text-xs">{{
                            endpoint.context ?? '–'
                        }}</TableCell>
                        <TableCell class="font-mono text-xs">{{
                            endpoint.allow ?? '–'
                        }}</TableCell>
                        <TableCell class="font-mono text-xs">{{
                            endpoint.max_contacts ?? '–'
                        }}</TableCell>
                        <TableCell class="space-x-2 text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="editRow(endpoint)"
                                >{{ t('Modifier') }}</Button
                            >
                            <Button
                                size="sm"
                                variant="destructive"
                                @click="deleteRow(endpoint)"
                                >{{ t('Supprimer') }}</Button
                            >
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
