<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bell, Mail } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { t } from '@/lib/i18n';
import { edit, update } from '@/routes/notification-preferences';

type Channel = 'in_app' | 'email';

const props = defineProps<{
    categories: { key: string; label: string; description: string }[];
    preferences: Record<string, Record<Channel, boolean>>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notifications', href: edit() }],
    },
});

const form = useForm({
    preferences: JSON.parse(JSON.stringify(props.preferences)) as Record<
        string,
        Record<Channel, boolean>
    >,
});

const channels: { key: Channel; label: string; icon: typeof Bell }[] = [
    { key: 'in_app', label: t("Dans l'application"), icon: Bell },
    { key: 'email', label: t('Par e-mail'), icon: Mail },
];

function save(): void {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('Notifications')" />

    <section class="space-y-6">
        <Heading
            variant="small"
            :title="t('Notifications')"
            :description="
                t(
                    'Choisissez ce que vous recevez dans l\'application (cloche et alertes) et par e-mail.',
                )
            "
        />

        <form class="space-y-6" @submit.prevent="save">
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">
                                {{ t('Catégorie') }}
                            </th>
                            <th
                                v-for="channel in channels"
                                :key="channel.key"
                                class="w-36 px-4 py-3 text-center font-medium"
                            >
                                <span class="inline-flex items-center gap-1.5"
                                    ><component
                                        :is="channel.icon"
                                        class="size-4"
                                    />{{ t(channel.label) }}</span
                                >
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="category in categories" :key="category.key">
                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ category.label }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ category.description }}
                                </div>
                            </td>
                            <td
                                v-for="channel in channels"
                                :key="channel.key"
                                class="px-4 py-3 text-center"
                            >
                                <Checkbox
                                    v-model="
                                        form.preferences[category.key][
                                            channel.key
                                        ]
                                    "
                                    :aria-label="`${category.label} — ${t(channel.label)}`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Les invitations à rejoindre une organisation et les e-mails de sécurité (mot de passe, vérification) sont toujours envoyés.',
                    )
                }}
            </p>

            <Button :disabled="form.processing || !form.isDirty">{{
                t('Enregistrer')
            }}</Button>
        </form>
    </section>
</template>
