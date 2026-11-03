<script setup lang="ts">
import { t } from '@/lib/i18n';
import { markRaw } from 'vue';
import { MailCheck } from '@lucide/vue';
import { Form, Head } from '@inertiajs/vue3';
import AuthStatus from '@/components/AuthStatus.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        icon: markRaw(MailCheck),
        title: "Vérification de l'e-mail",
        description:
            'Veuillez vérifier votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('Vérification de l\'e-mail')" />

    <AuthStatus v-if="status === 'verification-link-sent'">
        {{
            t(
                "Un nouveau lien de vérification a été envoyé à l'adresse e-mail indiquée lors de l'inscription.",
            )
        }}
    </AuthStatus>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            {{ t("Renvoyer l'e-mail de vérification") }}
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            {{ t('Se déconnecter') }}
        </TextLink>
    </Form>
</template>
