<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Check,
    CircleCheck,
    Hash,
    ListOrdered,
    MapPin,
    Phone,
    Rocket,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import type {
    OrganizationProfile,
    OrganizationProfileOptions,
} from '@/components/OrganizationProfileForm.vue';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { complete, update } from '@/routes/onboarding';

type Step = 'company' | 'contact' | 'setup';

type ChecklistItem = {
    key: 'extensions' | 'queues' | 'numbers' | 'members';
    done: boolean;
    count: number;
};

const props = defineProps<{
    team: { name: string; slug: string; onboarded: boolean };
    step: Step;
    profile: OrganizationProfile;
    profileOptions: OrganizationProfileOptions;
    checklist: ChecklistItem[];
    trial: { planName: string; endsAt: string; onTrial: boolean } | null;
}>();

const steps: { key: Step; title: string; icon: typeof Building2 }[] = [
    { key: 'company', title: t('Votre entreprise'), icon: Building2 },
    { key: 'contact', title: t('Coordonnées'), icon: MapPin },
    { key: 'setup', title: t('Premiers pas'), icon: Rocket },
];

const currentIndex = computed(() =>
    steps.findIndex((step) => step.key === props.step),
);

const form = useForm({
    step: props.step === 'setup' ? 'contact' : props.step,
    name: props.team.name,
    usage_mode: ((props.profile as { usage_mode?: string }).usage_mode ??
        'call_center') as 'call_center' | 'internal',
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

const usageChoices = [
    {
        value: 'call_center' as const,
        title: t('Centre d’appels'),
        text: t(
            'Files d’attente, numéros, campagnes, supervision, plus les appels internes et les réunions.',
        ),
    },
    {
        value: 'internal' as const,
        title: t('Communication interne'),
        text: t(
            'Appels entre collègues et réunions vidéo uniquement. Modifiable plus tard.',
        ),
    },
];

function save(step: 'company' | 'contact') {
    form.step = step;
    form.put(update.url(props.team.slug), { preserveScroll: false });
}

function back() {
    const previous = steps[currentIndex.value - 1];

    if (previous) {
        router.get(
            `${window.location.pathname}?step=${previous.key}`,
            {},
            { preserveState: false },
        );
    }
}

const finishing = useForm<{ skipped: boolean; next: string | null }>({
    skipped: false,
    next: null,
});

function finish(next: ChecklistItem['key'] | null = null, skipped = false) {
    finishing.skipped = skipped;
    finishing.next = next;
    finishing.post(complete.url(props.team.slug));
}

const checklistCopy: Record<
    ChecklistItem['key'],
    { title: string; text: string; icon: typeof Phone; action: string }
> = {
    extensions: {
        title: t('Créer les postes des agents'),
        text: t(
            'Chaque agent a un poste (7001, 7002…) pour utiliser le softphone. Le plus simple : « Créer un membre » dans Organisation.',
        ),
        icon: Phone,
        action: t('Postes SIP'),
    },
    queues: {
        title: t('Créer une file d’attente'),
        text: t(
            'Une file par service (Support, Ventes…) : elle fait patienter les appelants et distribue les appels.',
        ),
        icon: ListOrdered,
        action: t('Files d’attente'),
    },
    numbers: {
        title: t('Brancher un numéro'),
        text: t(
            'Indiquez où arrive chaque numéro de votre opérateur : une file, un menu vocal ou un poste.',
        ),
        icon: Hash,
        action: t('Numéros et SVI'),
    },
    members: {
        title: t('Inviter votre équipe'),
        text: t(
            'Créez les comptes de vos agents et superviseurs, ou invitez-les par e-mail.',
        ),
        icon: Users,
        action: 'Membres',
    },
};

const doneCount = computed(
    () => props.checklist.filter((item) => item.done).length,
);
</script>

<template>
    <Head title="Bienvenue" />

    <div class="pt-4 sm:pt-8">
        <div class="mb-8 space-y-2">
            <p class="text-sm font-medium text-primary">
                {{ t('Bienvenue sur votre centre d’appels') }}
            </p>
            <h1
                class="text-2xl font-bold tracking-tight text-heading sm:text-3xl"
            >
                {{ t('Mettons :p0 en place', { p0: team.name }) }}
            </h1>
            <p class="max-w-xl text-sm text-muted-foreground">
                {{
                    t(
                        'Trois étapes rapides : présentez votre entreprise, puis nous vous montrons comment recevoir vos premiers appels.',
                    )
                }}
            </p>
        </div>

        <!-- Stepper -->
        <ol class="mb-6 grid grid-cols-3 gap-2" :aria-label="t('Étapes')">
            <li
                v-for="(item, i) in steps"
                :key="item.key"
                class="flex items-center gap-2 rounded-[var(--control-radius)] border bg-background px-3 py-2 text-sm"
                :class="
                    i === currentIndex
                        ? 'border-primary text-heading'
                        : 'text-muted-foreground'
                "
                :aria-current="i === currentIndex ? 'step' : undefined"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                    :class="
                        i < currentIndex
                            ? 'bg-positive text-white'
                            : i === currentIndex
                              ? 'bg-primary text-primary-foreground'
                              : 'bg-muted'
                    "
                >
                    <Check v-if="i < currentIndex" class="size-3.5" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span class="truncate font-medium">{{ item.title }}</span>
            </li>
        </ol>

        <section
            class="rounded-[var(--card-radius)] border bg-background p-5 sm:p-8"
        >
            <!-- Step 1: company -->
            <form
                v-if="step === 'company'"
                class="space-y-6"
                @submit.prevent="save('company')"
            >
                <div>
                    <h2 class="text-lg font-bold text-heading">
                        {{ t('Votre entreprise') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Ces informations nous aident à vous proposer les bons réglages et l’accompagnement adapté.',
                            )
                        }}
                    </p>
                </div>

                <fieldset class="grid gap-3 sm:grid-cols-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ t('Comment utiliserez-vous l’application ?') }}
                    </legend>
                    <label
                        v-for="choice in usageChoices"
                        :key="choice.value"
                        class="flex cursor-pointer flex-col gap-1 rounded-lg border p-4 transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                    >
                        <input
                            v-model="form.usage_mode"
                            type="radio"
                            name="usage_mode"
                            :value="choice.value"
                            class="sr-only"
                        />
                        <span class="font-medium">{{ choice.title }}</span>
                        <span class="text-sm text-muted-foreground">{{
                            choice.text
                        }}</span>
                    </label>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="onboarding-name">{{
                            t('Nom de l’organisation *')
                        }}</Label>
                        <Input
                            id="onboarding-name"
                            v-model="form.name"
                            required
                            autocomplete="organization"
                            v-focus
                        />
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'Le nom affiché à vos agents dans l’application.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="onboarding-legal-name">{{
                            t('Raison sociale')
                        }}</Label>
                        <Input
                            id="onboarding-legal-name"
                            v-model="form.legal_name"
                            :placeholder="t('Ex. MbayeLab SARL')"
                        />
                        <InputError :message="form.errors.legal_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-industry">{{
                            t('Secteur d’activité *')
                        }}</Label>
                        <SelectField
                            id="onboarding-industry"
                            v-model="form.industry"
                            :options="profileOptions.industries"
                            :placeholder="t('Choisir un secteur')"
                        />
                        <InputError :message="form.errors.industry" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-size">{{
                            t('Taille de l’entreprise *')
                        }}</Label>
                        <SelectField
                            id="onboarding-size"
                            v-model="form.company_size"
                            :options="profileOptions.sizes"
                            :placeholder="t('Nombre d’employés')"
                        />
                        <InputError :message="form.errors.company_size" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-use-case">{{
                            t('Usage principal')
                        }}</Label>
                        <SelectField
                            id="onboarding-use-case"
                            v-model="form.use_case"
                            :options="profileOptions.use_cases"
                            :placeholder="t('Choisir un usage')"
                        />
                        <InputError :message="form.errors.use_case" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-agents">{{
                            t('Nombre d’agents prévus')
                        }}</Label>
                        <Input
                            id="onboarding-agents"
                            v-model="form.planned_agents"
                            type="number"
                            min="1"
                            max="5000"
                            :placeholder="t('Ex. 10')"
                        />
                        <InputError :message="form.errors.planned_agents" />
                    </div>
                </div>

                <div
                    class="flex flex-col-reverse gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="finishing.processing"
                        @click="finish(null, true)"
                        >{{ t('Passer pour l’instant') }}</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        {{ t('Continuer') }} <ArrowRight />
                    </Button>
                </div>
            </form>

            <!-- Step 2: contact -->
            <form
                v-else-if="step === 'contact'"
                class="space-y-6"
                @submit.prevent="save('contact')"
            >
                <div>
                    <h2 class="text-lg font-bold text-heading">
                        {{ t('Coordonnées') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ t('Pour vous joindre et préparer vos factures.') }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="onboarding-country">{{
                            t('Pays *')
                        }}</Label>
                        <SelectField
                            id="onboarding-country"
                            v-model="form.country"
                            :options="profileOptions.countries"
                            :placeholder="t('Choisir un pays')"
                        />
                        <InputError :message="form.errors.country" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-city">{{ t('Ville *') }}</Label>
                        <Input
                            id="onboarding-city"
                            v-model="form.city"
                            :placeholder="t('Ex. Bamako')"
                            autocomplete="address-level2"
                            v-focus
                        />
                        <InputError :message="form.errors.city" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="onboarding-address">{{
                            t('Adresse')
                        }}</Label>
                        <Input
                            id="onboarding-address"
                            v-model="form.address"
                            :placeholder="t('Quartier, rue, porte')"
                            autocomplete="street-address"
                        />
                        <InputError :message="form.errors.address" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-phone">{{
                            t('Téléphone *')
                        }}</Label>
                        <Input
                            id="onboarding-phone"
                            v-model="form.phone"
                            type="tel"
                            :placeholder="t('+223 20 00 00 00')"
                            autocomplete="tel"
                        />
                        <InputError :message="form.errors.phone" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-email">{{
                            t('E-mail de contact')
                        }}</Label>
                        <Input
                            id="onboarding-email"
                            v-model="form.contact_email"
                            type="email"
                            :placeholder="t('contact@entreprise.ml')"
                            autocomplete="email"
                        />
                        <InputError :message="form.errors.contact_email" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="onboarding-website">{{
                            t('Site web')
                        }}</Label>
                        <Input
                            id="onboarding-website"
                            v-model="form.website"
                            placeholder="www.entreprise.ml"
                            autocomplete="url"
                        />
                        <InputError :message="form.errors.website" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-rccm">{{ t('RCCM') }}</Label>
                        <Input
                            id="onboarding-rccm"
                            v-model="form.rccm"
                            :placeholder="t('Ex. MA.BKO.2024.B.1234')"
                        />
                        <InputError :message="form.errors.rccm" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="onboarding-nif">{{ t('NIF') }}</Label>
                        <Input
                            id="onboarding-nif"
                            v-model="form.nif"
                            :placeholder="t('Numéro d’identification fiscale')"
                        />
                        <InputError :message="form.errors.nif" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="onboarding-source">{{
                            t('Comment nous avez-vous connus ?')
                        }}</Label>
                        <SelectField
                            id="onboarding-source"
                            v-model="form.acquisition_source"
                            :options="profileOptions.acquisition_sources"
                            :placeholder="t('Choisir une origine')"
                        />
                        <InputError :message="form.errors.acquisition_source" />
                    </div>
                </div>

                <label
                    for="onboarding-opt-in"
                    class="flex items-start gap-3 text-sm"
                >
                    <Checkbox
                        id="onboarding-opt-in"
                        v-model="form.marketing_opt_in"
                        class="mt-0.5"
                    />
                    <span>
                        {{
                            t(
                                'Recevoir nos nouveautés et offres par e-mail, SMS ou WhatsApp.',
                            )
                        }}
                    </span>
                </label>

                <div
                    class="flex flex-col-reverse gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Button type="button" variant="ghost" @click="back">
                        {{ t('Retour') }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ t('Continuer') }} <ArrowRight />
                    </Button>
                </div>
            </form>

            <!-- Step 3: first steps -->
            <div v-else class="space-y-6">
                <div class="flex items-start gap-3">
                    <CircleCheck
                        class="mt-0.5 size-6 shrink-0 text-positive"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 class="text-lg font-bold text-heading">
                            {{ t('Votre organisation est prête') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Voici les 4 étapes pour recevoir vos premiers appels (:p0/4 faites). Vous pouvez les faire maintenant ou plus tard : elles restent disponibles dans les Paramètres.',
                                    { p0: doneCount },
                                )
                            }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="trial?.onTrial"
                    class="rounded-[var(--control-radius)] border border-informative-border bg-informative-subtle px-3 py-2 text-sm text-informative-strong"
                >
                    {{
                        t(
                            'Votre essai gratuit de la formule :p0 court jusqu’au :p1.',
                            {
                                p0: trial.planName,
                                p1: formatDate(trial.endsAt),
                            },
                        )
                    }}
                </p>

                <ul class="divide-y rounded-[var(--card-radius)] border">
                    <li
                        v-for="item in checklist"
                        :key="item.key"
                        class="flex items-start gap-3 p-4"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full"
                            :class="
                                item.done
                                    ? 'bg-positive-subtle text-positive-strong'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            <Check v-if="item.done" class="size-4" />
                            <component
                                :is="checklistCopy[item.key].icon"
                                v-else
                                class="size-4"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-heading">
                                {{ checklistCopy[item.key].title }}
                                <span
                                    v-if="item.done"
                                    class="ml-1 text-xs font-normal text-positive-strong"
                                    >{{ t('Fait') }}</span
                                >
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ checklistCopy[item.key].text }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            class="shrink-0"
                            :disabled="finishing.processing"
                            @click="finish(item.key)"
                        >
                            {{ checklistCopy[item.key].action }}
                        </Button>
                    </li>
                </ul>

                <div
                    class="flex flex-col gap-3 rounded-[var(--card-radius)] bg-muted p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p class="font-semibold text-heading">
                            {{ t('Faites le tour de l’application') }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Une visite guidée de deux minutes vous attend sur le tableau de bord.',
                                )
                            }}
                        </p>
                    </div>
                    <Button
                        :disabled="finishing.processing"
                        data-test="onboarding-finish"
                        @click="finish()"
                    >
                        {{ t('Découvrir l’application') }} <ArrowRight />
                    </Button>
                </div>

                <div class="flex justify-start">
                    <Button type="button" variant="ghost" @click="back">
                        {{ t('Retour') }}
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
