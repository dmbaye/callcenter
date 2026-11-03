<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    Phone,
    PhoneCall,
    SkipForward,
    UserRound,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, watch } from 'vue';
import SurveyForm from '@/components/campaigns/SurveyForm.vue';
import type {
    SurveyAnswers,
    SurveyQuestion,
} from '@/components/campaigns/survey';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StatCard from '@/components/StatCard.vue';
import { Badge } from '@/components/ui/badge';
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
import { useAgentQueue } from '@/composables/useAgentQueue';
import { getEcho } from '@/lib/echo';
import { formatDate } from '@/lib/format';
import { next, workspace } from '@/routes/campaigns';
import { dispose } from '@/routes/campaigns/attempts';
import { call, skip } from '@/routes/campaigns/contacts';
import type { ContactCard, DispositionOutcomeValue, Team } from '@/types';

type WorkspaceCampaign = {
    id: number;
    name: string;
    mode: string;
    modeLabel: string;
    withinHours: boolean;
    blockers: string[];
    callable: number | null;
};

type Current = {
    campaign: {
        id: number;
        name: string;
        mode: string;
        script: string;
        dispositions: {
            id: number;
            label: string;
            outcome: DispositionOutcomeValue;
        }[];
    };
    contact: ContactCard;
    /** Campaign questionnaire and this contact's answers so far (null: none). */
    survey: {
        questions: SurveyQuestion[];
        answers: SurveyAnswers;
        completed: boolean;
    } | null;
    history: {
        id: number;
        at: string;
        result: string | null;
        disposition: string | null;
        agent: string | null;
        notes: string | null;
    }[];
    attempt: {
        id: number;
        status: string;
        result: string | null;
        resultLabel: string | null;
        connectedAt: string | null;
        dispositioned: boolean;
    } | null;
};

type Callback = ContactCard & {
    campaignId: number;
    campaignName: string;
    due: boolean;
};

const props = defineProps<{
    enabled: boolean;
    hasExtension: boolean;
    campaigns: WorkspaceCampaign[];
    current: Current | null;
    callbacks: Callback[];
    today: { calls: number; connected: number; qualified: number };
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Poste agent',
                href: props.currentTeam
                    ? workspace(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const { finishWrapUp } = useAgentQueue();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

const wrapup = useForm<{
    disposition_id: number | null;
    notes: string;
    callback_at: string;
}>({ disposition_id: null, notes: '', callback_at: '' });

const selectedOutcome = computed(
    () =>
        props.current?.campaign.dispositions.find(
            (d) => d.id === wrapup.disposition_id,
        )?.outcome,
);

/** Wrap-up is offered once the customer was reached (or during the call). */
const canQualify = computed(() => {
    const attempt = props.current?.attempt;

    return (
        !!attempt &&
        !attempt.dispositioned &&
        (attempt.connectedAt !== null || attempt.status === 'connected')
    );
});

const inCall = computed(() => {
    const status = props.current?.attempt?.status;

    return (
        status === 'dialing' || status === 'answered' || status === 'connected'
    );
});

watch(
    () => props.current?.attempt?.id,
    () => wrapup.reset(),
);

function reserveNext(campaignId: number) {
    router.post(
        next({ current_team: slug.value, campaign: campaignId }).url,
        {},
        { preserveScroll: true },
    );
}

function dial(campaignId: number, contactId: number) {
    router.post(
        call({
            current_team: slug.value,
            campaign: campaignId,
            contact: contactId,
        }).url,
        {},
        { preserveScroll: true },
    );
}

function skipContact() {
    if (!props.current) {
        return;
    }

    router.post(
        skip({
            current_team: slug.value,
            campaign: props.current.campaign.id,
            contact: props.current.contact.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

/* Questionnaire: answers of the current contact, saved as a draft or completed. */
const survey = useForm<{
    answers: SurveyAnswers;
    complete: boolean;
    attempt_id: number | null;
}>({
    answers: { ...props.current?.survey?.answers },
    complete: false,
    attempt_id: props.current?.attempt?.id ?? null,
});
const surveyErrors = computed(() => survey.errors as Record<string, string>);

// Another contact on screen: its own answers.
watch(
    () => props.current?.contact.id,
    () => {
        survey.defaults({
            answers: { ...props.current?.survey?.answers },
            complete: false,
            attempt_id: props.current?.attempt?.id ?? null,
        });
        survey.reset();
        survey.clearErrors();
    },
);

function saveSurvey(complete: boolean, onSuccess?: () => void) {
    const current = props.current;

    if (!current?.survey) {
        return;
    }

    survey.complete = complete;
    survey.attempt_id = current.attempt?.id ?? null;
    survey.post(
        `/${slug.value}/campaigns/${current.campaign.id}/contacts/${current.contact.id}/survey`,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                survey.defaults();
                onSuccess?.();
            },
        },
    );
}

function qualify() {
    const attempt = props.current?.attempt;

    if (!attempt) {
        return;
    }

    // Unsaved answers are kept as a draft before the call is qualified.
    if (props.current?.survey && survey.isDirty) {
        saveSurvey(false, qualify);

        return;
    }

    wrapup.post(
        dispose({ current_team: slug.value, attempt: attempt.id }).url,
        {
            preserveScroll: true,
            // Qualified: the post-call state ends.
            onSuccess: () => void finishWrapUp(),
        },
    );
}

const reload = () =>
    router.reload({ only: ['current', 'campaigns', 'callbacks', 'today'] });

// Screen pop and call results arrive on the agent's own channel.
let channel: string | null = null;

onMounted(() => {
    const echo = getEcho();
    const userId = page.props.auth?.user?.id;

    if (!echo || !userId) {
        return;
    }

    channel = `users.${userId}`;
    echo.private(channel)
        .listen('.campaign.connected', reload)
        .listen('.campaign.ended', reload);
});

onUnmounted(() => {
    if (channel) {
        getEcho()
            ?.private(channel)
            .stopListening('.campaign.connected', reload)
            .stopListening('.campaign.ended', reload);
    }
});

// Without Reverb, keep the screen fresh while a call is in progress.
let poll: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    poll = setInterval(() => {
        if (inCall.value && !getEcho()) {
            reload();
        }
    }, 4000);
});

onUnmounted(() => {
    if (poll) {
        clearInterval(poll);
    }
});
</script>

<template>
    <Head :title="t('Poste agent — campagnes')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="t('Poste agent')"
            :description="
                t(
                    'Vos campagnes, la fiche du contact, le script et la qualification des appels.',
                )
            "
        />

        <p
            v-if="!enabled"
            class="rounded-md bg-notice/20 p-3 text-sm text-notice-strong"
        >
            {{
                t(
                    "Les campagnes ne sont pas incluses dans l'offre de votre équipe.",
                )
            }}
        </p>
        <p
            v-else-if="!hasExtension"
            class="rounded-md bg-notice/20 p-3 text-sm text-notice-strong"
        >
            {{
                t(
                    'Aucun poste SIP ne vous est attribué : demandez à votre administrateur.',
                )
            }}
        </p>
        <p
            v-if="errors.campaign || errors.contact"
            class="rounded-md bg-negative/10 p-3 text-sm text-negative-strong"
            role="alert"
        >
            {{ errors.campaign ?? errors.contact }}
        </p>

        <div class="grid gap-6 xl:grid-cols-[20rem_minmax(0,1fr)]">
            <!-- Left: campaigns, today, callbacks -->
            <aside class="space-y-6">
                <div class="grid grid-cols-3 gap-2">
                    <StatCard label="Appels" :value="today.calls" />
                    <StatCard
                        label="Joints"
                        :value="today.connected"
                        tone="info"
                    />
                    <StatCard
                        :label="t('Qualifiés')"
                        :value="today.qualified"
                        tone="good"
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Mes campagnes') }}</CardTitle>
                        <CardDescription>{{
                            t(
                                'Restez disponible dans le softphone pour recevoir les appels automatiques.',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div
                            v-for="c in campaigns"
                            :key="c.id"
                            class="rounded-md border p-3"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="font-medium">{{ c.name }}</span>
                                <Badge variant="outline">{{
                                    c.modeLabel
                                }}</Badge>
                            </div>
                            <p
                                v-if="!c.withinHours"
                                class="mt-1 text-xs text-notice-strong"
                            >
                                {{ t("Hors horaires d'appel") }}
                            </p>
                            <template v-if="c.mode === 'preview'">
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ c.callable }} contact(s) à appeler
                                </p>
                                <Button
                                    size="sm"
                                    class="mt-2 w-full"
                                    :disabled="
                                        !!current ||
                                        !c.withinHours ||
                                        !c.callable
                                    "
                                    @click="reserveNext(c.id)"
                                    ><UserRound />
                                    {{ t('Contact suivant') }}</Button
                                >
                            </template>
                            <template v-else>
                                <p
                                    v-if="c.blockers.length === 0"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{
                                        t(
                                            'Les appels arrivent automatiquement sur votre softphone.',
                                        )
                                    }}
                                </p>
                                <ul
                                    v-else
                                    class="mt-2 space-y-1 rounded-md bg-notice/15 p-2 text-xs text-notice-strong"
                                    role="status"
                                >
                                    <li class="font-medium">
                                        {{
                                            t(
                                                'Aucun appel ne partira tant que :',
                                            )
                                        }}
                                    </li>
                                    <li
                                        v-for="(reason, i) in c.blockers"
                                        :key="i"
                                    >
                                        • {{ reason }}
                                    </li>
                                </ul>
                            </template>
                        </div>
                        <p
                            v-if="campaigns.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                t(
                                    'Aucune campagne en cours ne vous est affectée.',
                                )
                            }}
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="callbacks.length">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"
                            ><CalendarClock class="size-4" />
                            {{ t('Mes rappels') }}</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div
                            v-for="cb in callbacks"
                            :key="cb.id"
                            class="flex items-center gap-2 rounded-md border p-2 text-sm"
                            :class="cb.due ? 'border-notice' : ''"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">
                                    {{ cb.name }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ cb.campaignName }} ·
                                    {{
                                        cb.nextAttemptAt
                                            ? new Intl.DateTimeFormat(
                                                  intlLocale(),
                                                  {
                                                      dateStyle: 'short',
                                                      timeStyle: 'short',
                                                  },
                                              ).format(
                                                  new Date(cb.nextAttemptAt),
                                              )
                                            : '—'
                                    }}
                                </div>
                            </div>
                            <Button
                                size="sm"
                                :variant="cb.due ? 'default' : 'outline'"
                                :disabled="!!current"
                                :aria-label="`Appeler ${cb.name}`"
                                @click="dial(cb.campaignId, cb.id)"
                                ><Phone
                            /></Button>
                        </div>
                    </CardContent>
                </Card>
            </aside>

            <!-- Main: contact, script, wrap-up -->
            <section v-if="current" class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <CardTitle class="text-xl">{{
                                current.contact.name
                            }}</CardTitle>
                            <Badge
                                v-if="current.attempt"
                                :class="
                                    current.attempt.status === 'connected'
                                        ? 'border-transparent bg-positive text-white'
                                        : ''
                                "
                                variant="outline"
                                >{{
                                    current.attempt.status === 'connected'
                                        ? 'En conversation'
                                        : current.attempt.status === 'ended'
                                          ? (current.attempt.resultLabel ??
                                            t('Terminé'))
                                          : t('Numérotation…')
                                }}</Badge
                            >
                        </div>
                        <CardDescription>{{
                            current.campaign.name
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <dl
                            class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('Téléphone') }}
                            </dt>
                            <dd class="font-mono">
                                {{ current.contact.phone }}
                            </dd>
                            <template v-if="current.contact.company">
                                <dt class="text-muted-foreground">
                                    {{ t('Entreprise') }}
                                </dt>
                                <dd>{{ current.contact.company }}</dd>
                            </template>
                            <template v-if="current.contact.email">
                                <dt class="text-muted-foreground">
                                    {{ t('E-mail') }}
                                </dt>
                                <dd>{{ current.contact.email }}</dd>
                            </template>
                            <template
                                v-for="(value, key) in current.contact.data"
                                :key="key"
                            >
                                <dt class="text-muted-foreground capitalize">
                                    {{ String(key).replace(/_/g, ' ') }}
                                </dt>
                                <dd>{{ value }}</dd>
                            </template>
                            <dt class="text-muted-foreground">
                                {{ t('Tentatives') }}
                            </dt>
                            <dd>{{ current.contact.attempts }}</dd>
                        </dl>

                        <div
                            v-if="!current.attempt"
                            class="flex flex-wrap gap-2"
                        >
                            <Button
                                :disabled="!hasExtension"
                                @click="
                                    dial(
                                        current.campaign.id,
                                        current.contact.id,
                                    )
                                "
                                ><PhoneCall /> {{ t('Appeler') }}</Button
                            >
                            <Button variant="outline" @click="skipContact"
                                ><SkipForward /> {{ t('Passer') }}</Button
                            >
                        </div>
                        <p
                            v-else-if="
                                current.attempt.status === 'dialing' &&
                                current.campaign.mode === 'preview'
                            "
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                t(
                                    'Décrochez votre softphone : le client est appelé dès que vous répondez.',
                                )
                            }}
                        </p>

                        <div v-if="current.history.length" class="space-y-1">
                            <div class="text-sm font-medium">
                                {{ t('Historique') }}
                            </div>
                            <ul class="space-y-1 text-xs text-muted-foreground">
                                <li v-for="h in current.history" :key="h.id">
                                    {{ formatDate(h.at) }} —
                                    {{ h.result ?? t('en cours') }}
                                    <template v-if="h.disposition">
                                        · {{ h.disposition }}</template
                                    >
                                    <template v-if="h.agent">
                                        · {{ h.agent }}</template
                                    >
                                </li>
                            </ul>
                        </div>
                        <p
                            v-if="current.contact.notes"
                            class="rounded-md bg-muted p-2 text-xs whitespace-pre-line"
                        >
                            {{ current.contact.notes }}
                        </p>
                    </CardContent>
                </Card>

                <div class="space-y-6">
                    <Card>
                        <CardHeader
                            ><CardTitle>{{
                                t('Script')
                            }}</CardTitle></CardHeader
                        >
                        <CardContent>
                            <p
                                class="text-sm leading-relaxed whitespace-pre-line"
                            >
                                {{
                                    current.campaign.script ||
                                    t('Aucun script.')
                                }}
                            </p>
                        </CardContent>
                    </Card>

                    <Card v-if="current.survey">
                        <CardHeader
                            class="flex flex-row items-center justify-between space-y-0"
                        >
                            <CardTitle>{{ t('Questionnaire') }}</CardTitle>
                            <Badge
                                v-if="current.survey.completed"
                                class="border-transparent bg-positive text-white"
                                >{{ t('Terminé') }}</Badge
                            >
                        </CardHeader>
                        <CardContent class="space-y-5">
                            <SurveyForm
                                v-model="survey.answers"
                                :questions="current.survey.questions"
                                :errors="surveyErrors"
                                :disabled="survey.processing"
                            />
                            <div class="flex flex-wrap gap-2 border-t pt-4">
                                <Button
                                    :disabled="survey.processing"
                                    @click="saveSurvey(true)"
                                    >{{
                                        current.survey.completed
                                            ? t('Mettre à jour les réponses')
                                            : t('Terminer le questionnaire')
                                    }}</Button
                                >
                                <Button
                                    variant="outline"
                                    :disabled="
                                        survey.processing || !survey.isDirty
                                    "
                                    @click="saveSurvey(false)"
                                    >{{ t('Enregistrer le brouillon') }}</Button
                                >
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'Le brouillon est aussi enregistré quand vous qualifiez l’appel. Rappelé plus tard, le contact reprend là où il s’était arrêté.',
                                    )
                                }}
                            </p>
                        </CardContent>
                    </Card>

                    <Card v-if="canQualify">
                        <CardHeader
                            ><CardTitle>{{
                                t('Qualification')
                            }}</CardTitle></CardHeader
                        >
                        <CardContent>
                            <form class="space-y-4" @submit.prevent="qualify">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <button
                                        v-for="d in current.campaign
                                            .dispositions"
                                        :key="d.id"
                                        type="button"
                                        class="rounded-md border px-3 py-2 text-left text-sm transition hover:border-primary"
                                        :class="
                                            wrapup.disposition_id === d.id
                                                ? 'border-2 border-primary bg-accent/50'
                                                : ''
                                        "
                                        :aria-pressed="
                                            wrapup.disposition_id === d.id
                                        "
                                        @click="wrapup.disposition_id = d.id"
                                    >
                                        {{ d.label }}
                                    </button>
                                </div>
                                <InputError
                                    :message="wrapup.errors.disposition_id"
                                />
                                <div
                                    v-if="selectedOutcome === 'callback'"
                                    class="grid gap-2"
                                >
                                    <Label for="cb">{{
                                        t('Rappeler le')
                                    }}</Label>
                                    <Input
                                        id="cb"
                                        v-model="wrapup.callback_at"
                                        type="datetime-local"
                                        required
                                    />
                                    <InputError
                                        :message="wrapup.errors.callback_at"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="notes">{{ t('Notes') }}</Label>
                                    <Textarea
                                        id="notes"
                                        v-model="wrapup.notes"
                                        rows="3"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    :disabled="
                                        !wrapup.disposition_id ||
                                        wrapup.processing
                                    "
                                    >{{ t('Valider') }}</Button
                                >
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <Card v-else class="self-start">
                <CardContent
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Aucun contact en cours. Choisissez « Contact suivant » dans une campagne en mode aperçu, ou restez disponible pour les appels automatiques.',
                        )
                    }}
                </CardContent>
            </Card>
        </div>
    </div>
</template>
