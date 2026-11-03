<script setup lang="ts">
import { t } from '@/lib/i18n';
import { markRaw } from 'vue';
import { ShieldCheck } from '@lucide/vue';
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

const showRecoveryInput = ref<boolean>(false);
const code = ref<string>('');

const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: t('Code de récupération'),
            description:
                "Veuillez confirmer l'accès à votre compte en saisissant l'un de vos codes de récupération d'urgence.",
            buttonText: "utiliser un code d'authentification",
        };
    }

    return {
        title: t("Code d'authentification"),
        description:
            "Saisissez le code d'authentification fourni par votre application d'authentification.",
        buttonText: 'utiliser un code de récupération',
    };
});

watchEffect(() => {
    setLayoutProps({
        icon: markRaw(ShieldCheck),
        title: authConfigContent.value.title,
        description: authConfigContent.value.description,
    });
});

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};
</script>

<template>
    <Head :title="t('Authentification à deux facteurs')" />

    <div class="space-y-6">
        <template v-if="!showRecoveryInput">
            <Form
                v-bind="store.form()"
                class="space-y-4"
                reset-on-error
                @error="code = ''"
                #default="{ errors, processing, clearErrors }"
            >
                <input type="hidden" name="code" :value="code" />
                <div
                    class="flex flex-col items-center justify-center space-y-3 text-center"
                >
                    <div class="flex w-full items-center justify-center">
                        <InputOTP
                            id="otp"
                            v-model="code"
                            :maxlength="6"
                            :disabled="processing"
                            autofocus
                        >
                            <InputOTPGroup>
                                <InputOTPSlot
                                    v-for="index in 6"
                                    :key="index"
                                    :index="index - 1"
                                    class="h-12 w-12 text-lg font-medium"
                                />
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                    <InputError :message="errors.code" />
                </div>
                <Button type="submit" class="w-full" :disabled="processing">{{
                    t('Continuer')
                }}</Button>
                <div class="text-center text-sm text-muted-foreground">
                    <span>{{ t('ou') }} </span>
                    <Button
                        type="button"
                        variant="link"
                        class="h-auto p-0"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </Button>
                </div>
            </Form>
        </template>

        <template v-else>
            <Form
                v-bind="store.form()"
                class="space-y-4"
                reset-on-error
                #default="{ errors, processing, clearErrors }"
            >
                <Input
                    name="recovery_code"
                    type="text"
                    :placeholder="t('Saisissez le code de récupération')"
                    v-focus
                    required
                />
                <InputError :message="errors.recovery_code" />
                <Button type="submit" class="w-full" :disabled="processing">{{
                    t('Continuer')
                }}</Button>

                <div class="text-center text-sm text-muted-foreground">
                    <span>{{ t('ou') }} </span>
                    <Button
                        type="button"
                        variant="link"
                        class="h-auto p-0"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </Button>
                </div>
            </Form>
        </template>
    </div>
</template>
