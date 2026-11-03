<script setup lang="ts">
import { t } from '@/lib/i18n';
import { markRaw } from 'vue';
import { KeyRound } from '@lucide/vue';
import { Form, Head } from '@inertiajs/vue3';
import AuthStatus from '@/components/AuthStatus.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        icon: markRaw(KeyRound),
        title: 'Mot de passe oublié',
        description:
            'Saisissez votre e-mail pour recevoir un lien de réinitialisation du mot de passe',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('Mot de passe oublié')" />

    <AuthStatus v-if="status">
        {{ status }}
    </AuthStatus>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">{{ t('Adresse e-mail') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    v-focus
                    :placeholder="t('email@example.com')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('Envoyer le lien de réinitialisation') }}
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>{{ t('Ou retourner à') }}</span>
            <TextLink :href="login()">{{ t('se connecter') }}</TextLink>
        </div>
    </div>
</template>
