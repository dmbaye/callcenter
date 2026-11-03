<script setup lang="ts">
import { t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { update } from '@/routes/teams/profile';

/**
 * Company profile of the organization: activity, contacts, registry
 * numbers and how it found us. Used internally for segmentation and
 * marketing; editable by the owner and admins.
 */
export type OrganizationProfile = {
    legal_name: string | null;
    industry: string | null;
    company_size: string | null;
    planned_agents: number | null;
    use_case: string | null;
    country: string | null;
    city: string | null;
    address: string | null;
    phone: string | null;
    contact_email: string | null;
    website: string | null;
    rccm: string | null;
    nif: string | null;
    acquisition_source: string | null;
    marketing_opt_in: boolean;
    completedAt: string | null;
    isComplete: boolean;
};

type Option = { value: string; label: string };

export type OrganizationProfileOptions = {
    industries: Option[];
    sizes: Option[];
    use_cases: Option[];
    acquisition_sources: Option[];
    countries: Option[];
};

const props = defineProps<{
    teamSlug: string;
    profile: OrganizationProfile;
    options: OrganizationProfileOptions;
    canUpdate: boolean;
}>();

const form = useForm({
    legal_name: props.profile.legal_name ?? '',
    industry: props.profile.industry ?? '',
    company_size: props.profile.company_size ?? '',
    planned_agents: props.profile.planned_agents ?? ('' as number | ''),
    use_case: props.profile.use_case ?? '',
    country: props.profile.country ?? '',
    city: props.profile.city ?? '',
    address: props.profile.address ?? '',
    phone: props.profile.phone ?? '',
    contact_email: props.profile.contact_email ?? '',
    website: props.profile.website ?? '',
    rccm: props.profile.rccm ?? '',
    nif: props.profile.nif ?? '',
    acquisition_source: props.profile.acquisition_source ?? '',
    marketing_opt_in: props.profile.marketing_opt_in,
});

// The fields that make the profile usable for segmentation (Team::PROFILE_REQUIRED_FIELDS).
const required = [
    'industry',
    'company_size',
    'country',
    'city',
    'phone',
] as const;

const missing = computed(
    () =>
        required.filter((field) => String(form[field] ?? '').trim() === '')
            .length,
);

function submit(): void {
    form.patch(update.url(props.teamSlug), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('Profil de l\'entreprise')"
                :description="
                    t(
                        'Activité, coordonnées et identifiants légaux de l\'organisation',
                    )
                "
            />
            <span
                class="shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium"
                :class="
                    missing === 0
                        ? 'border-positive-border bg-positive-subtle text-positive-strong'
                        : 'border-notice-border bg-notice-subtle text-notice-strong'
                "
                data-test="profile-completion"
            >
                {{
                    missing === 0
                        ? t('Profil complet')
                        : `${missing} champ(s) essentiel(s) manquant(s)`
                }}
            </span>
        </div>

        <form class="space-y-8" @submit.prevent="submit">
            <fieldset :disabled="!canUpdate" class="space-y-4">
                <legend class="mb-2 text-sm font-semibold text-heading">
                    {{ t('Activité') }}
                </legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="profile-legal-name">{{
                            t('Raison sociale')
                        }}</Label>
                        <Input
                            id="profile-legal-name"
                            v-model="form.legal_name"
                            :placeholder="t('Ex. MbayeLab SARL')"
                            autocomplete="organization"
                        />
                        <InputError :message="form.errors.legal_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-industry">{{
                            t("Secteur d'activité *")
                        }}</Label>
                        <SelectField
                            id="profile-industry"
                            v-model="form.industry"
                            :options="options.industries"
                            :placeholder="t('Choisir un secteur')"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.industry" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-size">{{
                            t("Taille de l'entreprise *")
                        }}</Label>
                        <SelectField
                            id="profile-size"
                            v-model="form.company_size"
                            :options="options.sizes"
                            :placeholder="t('Nombre d\'employés')"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.company_size" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-use-case">{{
                            t('Usage principal')
                        }}</Label>
                        <SelectField
                            id="profile-use-case"
                            v-model="form.use_case"
                            :options="options.use_cases"
                            :placeholder="t('Choisir un usage')"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.use_case" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-agents">{{
                            t("Nombre d'agents prévus")
                        }}</Label>
                        <Input
                            id="profile-agents"
                            v-model="form.planned_agents"
                            type="number"
                            min="1"
                            max="5000"
                            :placeholder="t('Ex. 10')"
                        />
                        <InputError :message="form.errors.planned_agents" />
                    </div>
                </div>
            </fieldset>

            <fieldset :disabled="!canUpdate" class="space-y-4">
                <legend class="mb-2 text-sm font-semibold text-heading">
                    {{ t('Coordonnées') }}
                </legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="profile-country">{{ t('Pays *') }}</Label>
                        <SelectField
                            id="profile-country"
                            v-model="form.country"
                            :options="options.countries"
                            :placeholder="t('Choisir un pays')"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.country" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-city">{{ t('Ville *') }}</Label>
                        <Input
                            id="profile-city"
                            v-model="form.city"
                            :placeholder="t('Ex. Bamako')"
                            autocomplete="address-level2"
                        />
                        <InputError :message="form.errors.city" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="profile-address">{{ t('Adresse') }}</Label>
                        <Input
                            id="profile-address"
                            v-model="form.address"
                            :placeholder="t('Quartier, rue, porte')"
                            autocomplete="street-address"
                        />
                        <InputError :message="form.errors.address" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-phone">{{
                            t('Téléphone *')
                        }}</Label>
                        <Input
                            id="profile-phone"
                            v-model="form.phone"
                            type="tel"
                            :placeholder="t('+223 20 00 00 00')"
                            autocomplete="tel"
                        />
                        <InputError :message="form.errors.phone" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-email">{{
                            t('E-mail de contact')
                        }}</Label>
                        <Input
                            id="profile-email"
                            v-model="form.contact_email"
                            type="email"
                            :placeholder="t('contact@entreprise.ml')"
                            autocomplete="email"
                        />
                        <InputError :message="form.errors.contact_email" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="profile-website">{{ t('Site web') }}</Label>
                        <Input
                            id="profile-website"
                            v-model="form.website"
                            placeholder="www.entreprise.ml"
                            autocomplete="url"
                        />
                        <InputError :message="form.errors.website" />
                    </div>
                </div>
            </fieldset>

            <fieldset :disabled="!canUpdate" class="space-y-4">
                <legend class="mb-2 text-sm font-semibold text-heading">
                    {{ t('Identifiants légaux') }}
                </legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="profile-rccm">{{ t('RCCM') }}</Label>
                        <Input
                            id="profile-rccm"
                            v-model="form.rccm"
                            :placeholder="t('Ex. MA.BKO.2024.B.1234')"
                        />
                        <InputError :message="form.errors.rccm" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile-nif">{{ t('NIF') }}</Label>
                        <Input
                            id="profile-nif"
                            v-model="form.nif"
                            :placeholder="t('Numéro d\'identification fiscale')"
                        />
                        <InputError :message="form.errors.nif" />
                    </div>
                </div>
            </fieldset>

            <fieldset :disabled="!canUpdate" class="space-y-4">
                <legend class="mb-2 text-sm font-semibold text-heading">
                    {{ t('Relation commerciale') }}
                </legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="profile-source">{{
                            t('Comment nous avez-vous connus ?')
                        }}</Label>
                        <SelectField
                            id="profile-source"
                            v-model="form.acquisition_source"
                            :options="options.acquisition_sources"
                            :placeholder="t('Choisir une origine')"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.acquisition_source" />
                    </div>
                </div>

                <label
                    for="profile-opt-in"
                    class="flex items-start gap-3 text-sm"
                >
                    <Checkbox
                        id="profile-opt-in"
                        v-model="form.marketing_opt_in"
                        :disabled="!canUpdate"
                        class="mt-0.5"
                    />
                    <span>
                        {{
                            t(
                                'Recevoir nos nouveautés et offres par e-mail, SMS ou WhatsApp.',
                            )
                        }}
                        <span class="block text-xs text-muted-foreground">
                            {{
                                t(
                                    "Vous pouvez changer d'avis à tout moment ici.",
                                )
                            }}
                        </span>
                    </span>
                </label>
            </fieldset>

            <div v-if="canUpdate" class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="profile-save-button"
                >
                    {{ t('Enregistrer le profil') }}
                </Button>
                <span
                    v-if="profile.completedAt"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('Profil complété le :p0', {
                            p0: formatDate(profile.completedAt),
                        })
                    }}
                </span>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                {{
                    t(
                        'Seuls le propriétaire et les administrateurs peuvent modifier le profil.',
                    )
                }}
            </p>
        </form>
    </div>
</template>
