<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SelectField from '@/components/SelectField.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { avatarTone, initials } from '@/lib/avatar';
import { LOCALES, t } from '@/lib/i18n';
import { edit, locale as localeRoute, update } from '@/routes/profile';
import {
    destroy as destroyAvatar,
    store as storeAvatar,
} from '@/routes/profile/avatar';
import { send } from '@/routes/verification';

type Details = {
    gender: string | null;
    job_title: string | null;
    department: string | null;
    phone: string | null;
    locale: string | null;
};

const props = defineProps<{
    mustVerifyEmail: boolean;
    status?: string;
    details: Details;
    genders: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Paramètres du profil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({
    name: user.value.name,
    email: user.value.email,
    gender: props.details.gender ?? '',
    job_title: props.details.job_title ?? '',
    department: props.details.department ?? '',
    phone: props.details.phone ?? '',
});

function save(): void {
    form.patch(update().url, { preserveScroll: true });
}

/* Photo */
const fileInput = ref<HTMLInputElement | null>(null);
const photo = useForm<{ avatar: File | null }>({ avatar: null });

function pickPhoto(): void {
    fileInput.value?.click();
}

function uploadPhoto(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    photo.avatar = file;
    photo.post(storeAvatar().url, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            photo.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function removePhoto(): void {
    if (confirm(t('Retirer votre photo de profil ?'))) {
        router.delete(destroyAvatar().url, { preserveScroll: true });
    }
}

/* Language: applied right away, the whole page reloads in it. */
const currentLocale = computed(
    () => props.details.locale ?? (page.props.locale as string) ?? 'fr',
);

function changeLocale(value: string): void {
    router.put(
        localeRoute().url,
        { locale: value },
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <Head :title="t('Paramètres du profil')" />

    <h1 class="sr-only">{{ t('Paramètres du profil') }}</h1>

    <section class="space-y-6">
        <Heading
            variant="small"
            :title="t('Photo de profil')"
            :description="
                t(
                    'Elle apparaît dans l\'annuaire, les réunions et la liste des membres.',
                )
            "
        />

        <div class="flex flex-wrap items-center gap-4">
            <Avatar class="size-20 rounded-full">
                <AvatarImage
                    v-if="user.avatar"
                    :src="user.avatar"
                    :alt="user.name"
                />
                <AvatarFallback
                    class="rounded-full text-xl font-medium"
                    :class="avatarTone(user.name)"
                    >{{ initials(user.name) }}</AvatarFallback
                >
            </Avatar>
            <div class="space-y-2">
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="photo.processing"
                        @click="pickPhoto"
                        ><Camera />
                        {{
                            user.avatar
                                ? t('Changer la photo')
                                : t('Ajouter une photo')
                        }}</Button
                    >
                    <Button
                        v-if="user.avatar"
                        type="button"
                        variant="ghost"
                        @click="removePhoto"
                        ><Trash2 /> {{ t('Retirer') }}</Button
                    >
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('JPG, PNG ou WebP, 4 Mo au plus. Recadrée en carré.')
                    }}
                </p>
                <InputError :message="photo.errors.avatar" />
            </div>
            <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="hidden"
                @change="uploadPhoto"
            />
        </div>
    </section>

    <section class="space-y-6">
        <Heading
            variant="small"
            :title="t('Profil')"
            :description="t('Vos informations visibles par vos collègues')"
        />

        <form class="space-y-6" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="name">{{ t('Nom') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        required
                        autocomplete="name"
                        :placeholder="t('Nom complet')"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="email">{{ t('Adresse e-mail') }}</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="username"
                        :placeholder="t('Adresse e-mail')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div
                    v-if="mustVerifyEmail && !user.email_verified_at"
                    class="sm:col-span-2"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ t("Votre adresse e-mail n'est pas vérifiée.") }}
                        <Link
                            :href="send()"
                            as="button"
                            class="text-foreground underline decoration-border underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                        >
                            {{
                                t(
                                    "Cliquez ici pour renvoyer l'e-mail de vérification.",
                                )
                            }}
                        </Link>
                    </p>
                    <div
                        v-if="status === 'verification-link-sent'"
                        class="mt-2 text-sm font-medium text-positive"
                    >
                        {{
                            t(
                                'Un nouveau lien de vérification a été envoyé à votre adresse e-mail.',
                            )
                        }}
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="gender">{{ t('Genre') }}</Label>
                    <SelectField
                        id="gender"
                        v-model="form.gender"
                        :options="genders"
                        :placeholder="t('Non renseigné')"
                    />
                    <InputError :message="form.errors.gender" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">{{ t('Téléphone') }}</Label>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        type="tel"
                        autocomplete="tel"
                        :placeholder="t('+223 70 00 00 00')"
                    />
                    <InputError :message="form.errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="job_title">{{ t('Fonction') }}</Label>
                    <Input
                        id="job_title"
                        v-model="form.job_title"
                        autocomplete="organization-title"
                        :placeholder="t('ex. Responsable commercial')"
                    />
                    <InputError :message="form.errors.job_title" />
                </div>

                <div class="grid gap-2">
                    <Label for="department">{{ t('Service') }}</Label>
                    <Input
                        id="department"
                        v-model="form.department"
                        :placeholder="t('ex. Ventes')"
                    />
                    <InputError :message="form.errors.department" />
                </div>
            </div>

            <Button
                :disabled="form.processing"
                data-test="update-profile-button"
                >{{ t('Enregistrer') }}</Button
            >
        </form>
    </section>

    <section class="space-y-4">
        <Heading
            variant="small"
            :title="t('Langue')"
            :description="
                t('Langue de l\'interface et des e-mails que vous recevez.')
            "
        />
        <div class="max-w-xs">
            <SelectField
                id="locale"
                :model-value="currentLocale"
                :options="LOCALES"
                :aria-label="t('Langue')"
                @update:model-value="changeLocale"
            />
        </div>
    </section>

    <DeleteUser />
</template>
