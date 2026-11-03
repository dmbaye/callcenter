<script setup lang="ts">
import { t } from '@/lib/i18n';
import { markRaw } from 'vue';
import { ShieldCheck } from '@lucide/vue';
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import PasskeyVerify from '@/components/PasskeyVerify.vue';

defineOptions({
    layout: {
        icon: markRaw(ShieldCheck),
        title: 'Confirmer le mot de passe',
        description:
            "Ceci est une zone sécurisée de l'application. Veuillez confirmer votre mot de passe avant de continuer.",
    },
});
</script>

<template>
    <Head :title="t('Confirmer le mot de passe')" />

    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        :label="t('Confirmer avec une clé d\'accès')"
        loading-label="Confirmation…"
        separator="Or confirm with password"
    />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label htmlFor="password">{{ t('Mot de passe') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('Confirmer le mot de passe') }}
                </Button>
            </div>
        </div>
    </Form>
</template>
