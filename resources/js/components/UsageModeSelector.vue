<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Headphones, PhoneCall } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { update } from '@/routes/teams/usage';

/**
 * How the organization uses the app: a full call center, or only internal
 * calls between colleagues plus video meetings.
 */
const props = defineProps<{
    teamSlug: string;
    mode: 'call_center' | 'internal';
    canUpdate: boolean;
}>();

const form = useForm({ usage_mode: props.mode });

const choices = [
    {
        value: 'call_center' as const,
        icon: Headphones,
        title: t("Centre d'appels"),
        text: t(
            "Files d'attente, numéros entrants, SVI, campagnes, supervision et statuts des agents, plus les appels internes et les réunions.",
        ),
    },
    {
        value: 'internal' as const,
        icon: PhoneCall,
        title: t('Communication interne'),
        text: t(
            "Appels entre collègues (poste à poste) et réunions vidéo uniquement. Les écrans du centre d'appels sont masqués.",
        ),
    },
];

function save(): void {
    form.patch(update.url(props.teamSlug), { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-4">
        <Heading
            variant="small"
            :title="t('Utilisation')"
            :description="
                t(
                    'Vous pouvez changer à tout moment : files, numéros et historique sont conservés.',
                )
            "
        />

        <form class="space-y-4" @submit.prevent="save">
            <fieldset
                :disabled="!canUpdate"
                class="grid gap-3 md:grid-cols-2"
                role="radiogroup"
                :aria-label="t('Utilisation')"
            >
                <label
                    v-for="choice in choices"
                    :key="choice.value"
                    class="flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:disabled]:cursor-default"
                >
                    <input
                        v-model="form.usage_mode"
                        type="radio"
                        name="usage_mode"
                        :value="choice.value"
                        class="sr-only"
                    />
                    <component
                        :is="choice.icon"
                        class="mt-0.5 size-5 shrink-0 text-primary"
                    />
                    <span>
                        <span class="block font-medium">{{
                            t(choice.title)
                        }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            t(choice.text)
                        }}</span>
                    </span>
                </label>
            </fieldset>
            <InputError :message="form.errors.usage_mode" />
            <p
                v-if="form.usage_mode === 'internal' && mode !== 'internal'"
                class="text-sm text-notice-strong"
            >
                {{
                    t(
                        "Les campagnes en cours seront mises en pause. Les appels vers vos numéros continuent d'arriver dans vos files.",
                    )
                }}
            </p>
            <Button
                v-if="canUpdate"
                type="submit"
                :disabled="form.processing || form.usage_mode === mode"
                >{{ t('Enregistrer') }}</Button
            >
        </form>
    </section>
</template>
