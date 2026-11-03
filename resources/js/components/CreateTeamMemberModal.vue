<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { store as storeMember } from '@/routes/teams/members';
import type { RoleOption, Team } from '@/types';

type Props = {
    team: Team;
    queueOptions: string[];
    availableRoles: RoleOption[];
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const role = ref('member');
const queue = ref();
const formKey = ref(0);

/* Agents always get a phone; a planner or an administrator usually does not. */
const withPhone = ref(true);
const needsPhone = computed(() => role.value === 'member' || withPhone.value);

watch(role, (value) => {
    withPhone.value = value === 'member' || value === 'supervisor';
});

function handleOpenChange(value: boolean) {
    emit('update:open', value);

    if (!value) {
        role.value = 'member';
        withPhone.value = true;
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <Form
                :key="formKey"
                v-bind="storeMember.form(props.team.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="emit('update:open', false)"
            >
                <DialogHeader>
                    <DialogTitle>{{ t('Créer un membre') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'Créez le compte directement et, s’il prend des appels, attribuez-lui ses identifiants SIP.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="create-member-name">{{ t('Nom') }}</Label>
                        <Input
                            id="create-member-name"
                            name="name"
                            data-test="create-member-name"
                            :placeholder="t('Awa Diarra')"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-member-email">{{
                            t('Adresse e-mail')
                        }}</Label>
                        <Input
                            id="create-member-email"
                            name="email"
                            data-test="create-member-email"
                            type="email"
                            :placeholder="t('colleague@example.com')"
                            required
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="create-member-password">{{
                                t('Mot de passe')
                            }}</Label>
                            <Input
                                id="create-member-password"
                                name="password"
                                data-test="create-member-password"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="create-member-password-confirmation">
                                {{ t('Confirmer le mot de passe') }}
                            </Label>
                            <Input
                                id="create-member-password-confirmation"
                                name="password_confirmation"
                                data-test="create-member-password-confirmation"
                                type="password"
                                autocomplete="new-password"
                                required
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-member-role">{{ t('Rôle') }}</Label>
                        <Select
                            v-model="role"
                            name="role"
                            data-test="create-member-role"
                        >
                            <SelectTrigger
                                id="create-member-role"
                                class="w-full"
                            >
                                <SelectValue
                                    :placeholder="t('Sélectionner un rôle')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in props.availableRoles"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.role" />
                    </div>

                    <input
                        type="hidden"
                        name="with_phone"
                        :value="needsPhone ? '1' : '0'"
                    />
                    <label
                        v-if="role !== 'member'"
                        class="flex items-start gap-2 text-sm"
                        data-test="create-member-with-phone"
                    >
                        <input
                            v-model="withPhone"
                            type="checkbox"
                            class="mt-0.5 size-4"
                        />
                        <span>
                            <span class="font-medium">{{
                                t('Prend aussi des appels')
                            }}</span>
                            <span class="block text-muted-foreground">{{
                                t(
                                    'Décochez pour un compte sans poste ni file (supervision, planning, administration). Un poste pourra être ajouté plus tard dans Postes SIP.',
                                )
                            }}</span>
                        </span>
                    </label>

                    <div
                        v-if="needsPhone && queueOptions.length > 0"
                        class="grid gap-2"
                    >
                        <Label for="queue">{{ t("File d'appels") }}</Label>
                        <Select
                            v-model="queue"
                            id="queue"
                            name="queue"
                            class="rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            <SelectTrigger
                                id="create-member-queue"
                                class="w-full"
                            >
                                <SelectValue
                                    :placeholder="t('Sélectionner une file')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="queue in queueOptions"
                                    :key="queue"
                                    :value="queue"
                                >
                                    {{ queue }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.queue" />
                    </div>

                    <div v-if="needsPhone" class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="create-member-sip-extension">
                                {{ t('Poste SIP') }}
                            </Label>
                            <Input
                                id="create-member-sip-extension"
                                name="sip_extension"
                                data-test="create-member-sip-extension"
                                inputmode="numeric"
                                pattern="[0-9]{2,20}"
                                placeholder="7001"
                                required
                            />
                            <InputError :message="errors.sip_extension" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="create-member-sip-secret">{{
                                t('Secret SIP')
                            }}</Label>
                            <Input
                                id="create-member-sip-secret"
                                name="sip_secret"
                                data-test="create-member-sip-secret"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="t('Mot de passe SIP')"
                                required
                            />
                            <InputError :message="errors.sip_secret" />
                        </div>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{ t('Annuler') }}</Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="create-member-submit"
                        :disabled="processing"
                    >
                        {{ t('Créer un utilisateur') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
