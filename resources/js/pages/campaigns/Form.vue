<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import MemberPicker from '@/components/meetings/MemberPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index, show, store, update } from '@/routes/campaigns';
import type {
    CampaignDetails,
    CampaignSchedule,
    DialModeValue,
    DispositionOutcomeValue,
    DispositionRow,
    Option,
    Team,
} from '@/types';

const props = defineProps<{
    campaign: CampaignDetails | null;
    agents: { id: number; name: string; extension: string | null }[];
    dialModes: Option<DialModeValue>[];
    outcomes: Option<DispositionOutcomeValue>[];
    defaultDispositions: DispositionRow[];
    defaultRetryMinutes: Record<string, number>;
    defaultSchedule: CampaignSchedule;
    defaultDialPrefix: string | null;
    defaultCallerId: string | null;
    teamNumbers: string[];
    timezones: string[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Campagnes',
                href: props.currentTeam ? index(props.currentTeam.slug) : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const c = props.campaign;

const form = useForm({
    name: c?.name ?? '',
    description: c?.description ?? '',
    dial_mode: (c?.dialMode ?? 'preview') as DialModeValue,
    dial_ratio: c?.dialRatio ?? 1.5,
    max_abandon_rate: c?.maxAbandonRate ?? 3,
    max_lines: (c?.maxLines ?? undefined) as number | undefined,
    caller_id: c?.callerId ?? '',
    dial_prefix: c?.dialPrefix ?? '',
    ring_timeout: c?.ringTimeout ?? 25,
    queue_timeout: c?.queueTimeout ?? 15,
    wrapup_seconds: c?.wrapupSeconds ?? 30,
    amd_enabled: c?.amdEnabled ?? false,
    voicemail_sound: c?.voicemailSound ?? '',
    abandon_sound: c?.abandonSound ?? '',
    max_attempts: c?.maxAttempts ?? 3,
    retry_minutes: { ...props.defaultRetryMinutes, ...c?.retryMinutes },
    timezone: c?.timezone ?? 'Africa/Bamako',
    schedule: { ...(c?.schedule ?? props.defaultSchedule) },
    script:
        c?.script ??
        'Bonjour {first_name}, je suis [votre prénom] de [entreprise].\nJe vous appelle au sujet de …\n\nQuestions à poser :\n- …',
    agent_ids: c?.agentIds ?? props.agents.map((a) => a.id),
    dispositions: (c?.dispositions ?? props.defaultDispositions).map((d) => ({
        id: d.id ?? null,
        label: d.label,
        outcome: d.outcome,
        retry_minutes: (d.retry_minutes ?? undefined) as number | undefined,
    })),
});

const modeHelp: Record<DialModeValue, string> = {
    preview:
        "L'agent voit la fiche, puis clique sur Appeler. Idéal pour les ventes complexes et les relances.",
    progressive:
        'Un appel par agent libre. Le client décroche et arrive directement sur un agent. Aucun appel abandonné.',
    power: "Plusieurs appels par agent libre (ratio). Plus de rendement, mais des appels peuvent être abandonnés : le ratio se réduit automatiquement si le taux d'abandon dépasse le seuil.",
};

const days = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mer' },
    { value: 4, label: 'Jeu' },
    { value: 5, label: 'Ven' },
    { value: 6, label: 'Sam' },
    { value: 7, label: 'Dim' },
];

const retryLabels: Record<string, string> = {
    no_answer: 'Pas de réponse',
    busy: 'Occupé',
    failed: 'Échec réseau',
    machine: 'Répondeur',
    abandoned: 'Abandonné',
    cancelled: 'Agent absent',
};

const agentMembers = computed(() =>
    props.agents.map((a) => ({
        id: a.id,
        name: a.name,
        role: a.extension ? t('poste :p0', { p0: a.extension }) : 'sans poste',
    })),
);

const errors = computed(() => form.errors as Record<string, string>);

function toggleDay(day: number) {
    form.schedule.days = form.schedule.days.includes(day)
        ? form.schedule.days.filter((d) => d !== day)
        : [...form.schedule.days, day].sort();
}

function addDisposition() {
    form.dispositions.push({
        id: null,
        label: '',
        outcome: 'final',
        retry_minutes: undefined,
    });
}

function submit() {
    // Empty optional numbers are sent as null so they can be cleared.
    form.transform((data) => ({
        ...data,
        max_lines: data.max_lines || null,
        dispositions: data.dispositions.map((d) => ({
            ...d,
            retry_minutes: d.retry_minutes || null,
        })),
    }));

    if (c) {
        form.put(update({ current_team: slug.value, campaign: c.id }).url);
    } else {
        form.post(store(slug.value).url);
    }
}

const cancelUrl = computed(() =>
    c
        ? show({ current_team: slug.value, campaign: c.id }).url
        : index(slug.value).url,
);
</script>

<template>
    <Head :title="c ? `Modifier — ${c.name}` : t('Nouvelle campagne')" />

    <form
        class="flex max-w-4xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <Heading
            :title="c ? `Modifier ${c.name}` : t('Nouvelle campagne')"
            :description="
                t(
                    'Les réglages sont modifiables à tout moment, même pendant la campagne.',
                )
            "
        />

        <Card>
            <CardHeader
                ><CardTitle>{{ t('Général') }}</CardTitle></CardHeader
            >
            <CardContent class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="name">{{ t('Nom') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        maxlength="120"
                        required
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="description">{{
                        t('Objectif (interne)')
                    }}</Label>
                    <Textarea
                        id="description"
                        v-model="form.description"
                        rows="2"
                    />
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Mode de numérotation') }}</CardTitle>
                <CardDescription>{{
                    t('Comme Genesys et RingCX : aperçu, progressif ou power.')
                }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <div class="grid gap-3 md:grid-cols-3">
                    <button
                        v-for="mode in dialModes"
                        :key="mode.value"
                        type="button"
                        class="rounded-lg border p-3 text-left text-sm transition hover:border-primary"
                        :class="
                            form.dial_mode === mode.value
                                ? 'border-2 border-primary bg-accent/50'
                                : ''
                        "
                        :aria-pressed="form.dial_mode === mode.value"
                        @click="form.dial_mode = mode.value"
                    >
                        <div class="font-semibold">{{ mode.label }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ modeHelp[mode.value] }}
                        </p>
                    </button>
                </div>
                <div
                    v-if="form.dial_mode === 'power'"
                    class="grid gap-4 sm:grid-cols-3"
                >
                    <div class="grid gap-2">
                        <Label for="ratio">{{
                            t('Lignes par agent libre')
                        }}</Label>
                        <Input
                            id="ratio"
                            v-model.number="form.dial_ratio"
                            type="number"
                            min="1"
                            max="5"
                            step="0.1"
                        />
                        <InputError :message="form.errors.dial_ratio" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="abandon">{{
                            t("Taux d'abandon max (%)")
                        }}</Label>
                        <Input
                            id="abandon"
                            v-model.number="form.max_abandon_rate"
                            type="number"
                            min="0"
                            max="30"
                            step="0.5"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="lines">{{
                            t('Lignes simultanées max')
                        }}</Label>
                        <Input
                            id="lines"
                            v-model.number="form.max_lines"
                            type="number"
                            min="1"
                            :placeholder="t('Illimité')"
                        />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Ligne sortante') }}</CardTitle>
                <CardDescription>{{
                    t(
                        'Les appels sortent par la ligne opérateur de la plateforme.',
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="prefix">{{
                        t('Préfixe de numérotation')
                    }}</Label>
                    <Input
                        id="prefix"
                        v-model="form.dial_prefix"
                        :placeholder="defaultDialPrefix ?? ''"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Ajouté devant le numéro international (ex. 00 → 0022376…).',
                            )
                        }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="cli">{{ t('Numéro présenté') }}</Label>
                    <Input
                        id="cli"
                        v-model="form.caller_id"
                        list="cli-numbers"
                        :placeholder="defaultCallerId ?? '+223 20 00 00 00'"
                    />
                    <datalist id="cli-numbers">
                        <option v-for="n in teamNumbers" :key="n" :value="n" />
                    </datalist>
                    <p class="text-xs text-muted-foreground">
                        {{
                            defaultCallerId
                                ? `Vide = numéro de l'équipe (${defaultCallerId}).`
                                : 'Vide = aucun numéro présenté : ajoutez un numéro dans Numéros et SVI.'
                        }}
                    </p>
                    <InputError :message="form.errors.caller_id" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="ring">{{ t('Sonnerie (s)') }}</Label>
                        <Input
                            id="ring"
                            v-model.number="form.ring_timeout"
                            type="number"
                            min="10"
                            max="90"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="qwait">{{ t('Attente agent (s)') }}</Label>
                        <Input
                            id="qwait"
                            v-model.number="form.queue_timeout"
                            type="number"
                            min="2"
                            max="120"
                        />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input
                        v-model="form.amd_enabled"
                        type="checkbox"
                        class="size-4"
                    />
                    {{
                        t(
                            'Détecter les répondeurs (AMD) — modes progressif et power',
                        )
                    }}
                </label>
                <div v-if="form.amd_enabled" class="grid gap-2">
                    <Label for="vm">{{
                        t('Message déposé sur répondeur')
                    }}</Label>
                    <Input
                        id="vm"
                        v-model="form.voicemail_sound"
                        :placeholder="
                            t('custom/campagne-message (vide = raccrocher)')
                        "
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="sorry">{{
                        t('Message si aucun agent libre')
                    }}</Label>
                    <Input
                        id="sorry"
                        v-model="form.abandon_sound"
                        :placeholder="t('custom/campagne-desole')"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="wrap">{{
                        t('Temps de qualification (s)')
                    }}</Label>
                    <Input
                        id="wrap"
                        v-model.number="form.wrapup_seconds"
                        type="number"
                        min="0"
                        max="600"
                    />
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Tentatives et horaires') }}</CardTitle>
                <CardDescription>{{
                    t(
                        "Délai avant de rappeler un contact, selon le résultat de l'appel.",
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-6">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="attempts">{{
                            t('Tentatives max par contact')
                        }}</Label>
                        <Input
                            id="attempts"
                            v-model.number="form.max_attempts"
                            type="number"
                            min="1"
                            max="20"
                        />
                    </div>
                    <div
                        v-for="(label, key) in retryLabels"
                        :key="key"
                        class="grid gap-2"
                    >
                        <Label :for="`retry-${key}`">{{
                            t(':p0 (minutes)', { p0: label })
                        }}</Label>
                        <Input
                            :id="`retry-${key}`"
                            v-model.number="form.retry_minutes[key]"
                            type="number"
                            min="0"
                        />
                    </div>
                </div>

                <div class="grid gap-3">
                    <Label>{{ t("Jours d'appel") }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="day in days"
                            :key="day.value"
                            type="button"
                            class="rounded-md border px-3 py-1.5 text-sm"
                            :class="
                                form.schedule.days.includes(day.value)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : ''
                            "
                            :aria-pressed="
                                form.schedule.days.includes(day.value)
                            "
                            @click="toggleDay(day.value)"
                        >
                            {{ day.label }}
                        </button>
                    </div>
                    <InputError :message="errors['schedule.days']" />
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="start">{{ t('De') }}</Label>
                        <Input
                            id="start"
                            v-model="form.schedule.start"
                            type="time"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="end">À</Label>
                        <Input
                            id="end"
                            v-model="form.schedule.end"
                            type="time"
                        />
                        <InputError :message="errors['schedule.end']" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="tz">{{ t('Fuseau horaire') }}</Label>
                        <select
                            id="tz"
                            v-model="form.timezone"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option
                                v-for="tz in timezones"
                                :key="tz"
                                :value="tz"
                            >
                                {{ tz }}
                            </option>
                        </select>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card v-if="c?.kind !== 'callback'">
            <CardHeader>
                <CardTitle>{{ t('Agents') }}</CardTitle>
                <CardDescription>{{
                    t(
                        'Ils reçoivent les appels de la campagne en plus de leurs files entrantes (mode mixte).',
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent>
                <MemberPicker
                    v-model="form.agent_ids"
                    :members="agentMembers"
                />
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Codes de qualification') }}</CardTitle>
                <CardDescription>{{
                    t(
                        "Choisis par l'agent après chaque appel. L'action décide de la suite pour le contact.",
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-3">
                <div
                    v-for="(d, i) in form.dispositions"
                    :key="i"
                    class="grid items-start gap-2 sm:grid-cols-[1fr_12rem_8rem_auto]"
                >
                    <Input
                        v-model="d.label"
                        :placeholder="t('Libellé')"
                        :aria-label="`Libellé ${i + 1}`"
                        required
                    />
                    <select
                        v-model="d.outcome"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                        :aria-label="`Action ${i + 1}`"
                    >
                        <option
                            v-for="o in outcomes"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                    <Input
                        v-if="d.outcome === 'retry'"
                        v-model.number="d.retry_minutes"
                        type="number"
                        min="1"
                        placeholder="minutes"
                        :aria-label="`Délai ${i + 1}`"
                    />
                    <span v-else />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Supprimer ${d.label}`"
                        @click="form.dispositions.splice(i, 1)"
                        ><Trash2
                    /></Button>
                </div>
                <InputError :message="form.errors.dispositions" />
                <Button
                    type="button"
                    variant="outline"
                    class="justify-self-start"
                    @click="addDisposition"
                    ><Plus /> {{ t('Ajouter un code') }}</Button
                >
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Script agent') }}</CardTitle>
                <CardDescription
                    >Variables : {first_name}, {last_name}, {company}, {phone}
                    et toute colonne de votre fichier (ex.
                    {ville}).</CardDescription
                >
            </CardHeader>
            <CardContent>
                <Textarea v-model="form.script" rows="8" />
            </CardContent>
        </Card>

        <div class="flex gap-3">
            <Button type="submit" :disabled="form.processing">{{
                c ? 'Enregistrer' : t('Créer la campagne')
            }}</Button>
            <Button as-child variant="outline"
                ><Link :href="cancelUrl">{{ t('Annuler') }}</Link></Button
            >
        </div>
    </form>
</template>
