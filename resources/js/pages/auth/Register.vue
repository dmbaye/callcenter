<script setup lang="ts">
import { t } from '@/lib/i18n';
import { markRaw } from 'vue';
import { UserPlus } from '@lucide/vue';
import { Form, Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { TeamInvitationContext } from '@/types';

defineProps<{
    passwordRules: string;
    teamInvitation?: TeamInvitationContext | null;
}>();

defineOptions({
    layout: {
        icon: markRaw(UserPlus),
        title: 'Créer un compte',
        description:
            'Renseignez vos informations ci-dessous pour créer votre compte',
    },
});
</script>

<template>
    <Head title="Inscription" />

    <TeamInvitationAlert
        v-if="teamInvitation"
        :invitation="teamInvitation"
        action="Inscrivez-vous"
    />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">{{ t('Nom') }}</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    :placeholder="t('Nom complet')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('Adresse e-mail') }}</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    :placeholder="t('email@example.com')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="company">{{ t('Société') }}</Label>
                <Input
                    id="company"
                    type="text"
                    required
                    :tabindex="2"
                    autocomplete="company"
                    name="company"
                    :placeholder="t('Orange Mali')"
                />
                <InputError :message="errors.company" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ t('Mot de passe') }}</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    :placeholder="t('Mot de passe')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    t('Confirmer le mot de passe')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    :placeholder="t('Confirmer le mot de passe')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                {{ t('Créer un compte') }}
            </Button>
            <p class="text-center text-xs text-muted-foreground">
                {{ t('En créant un compte, vous acceptez notre') }}
                <a
                    href="/confidentialite"
                    target="_blank"
                    class="underline underline-offset-4"
                    >{{ t('politique de confidentialité') }}</a
                >.
            </p>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            {{ t('Vous avez déjà un compte ?') }}
            <TextLink
                :href="
                    teamInvitation
                        ? login.url({
                              query: {
                                  invitation: teamInvitation.code,
                              },
                          })
                        : login()
                "
                class="underline underline-offset-4"
                :tabindex="6"
                data-test="team-invitation-login-link"
            >
                {{ t('Se connecter') }}
            </TextLink>
        </div>
    </Form>
</template>
