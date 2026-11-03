<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    CheckCircle2,
    ClipboardList,
    Download,
    Pause,
    Pencil,
    Play,
    RotateCcw,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import CampaignStatusBadge from '@/components/campaigns/CampaignStatusBadge.vue';
import InputError from '@/components/InputError.vue';
import StatCard from '@/components/StatCard.vue';
import StatusLight from '@/components/StatusLight.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDuration } from '@/lib/format';
import {
    destroy,
    edit,
    exportMethod,
    index,
    recycle,
    show,
    transition,
} from '@/routes/campaigns';
import {
    destroy as destroyContact,
    importMethod,
    store as storeContact,
} from '@/routes/campaigns/contacts';
import type {
    AgentStat,
    CampaignDetails,
    CampaignStatsSummary,
    ContactCard,
    Option,
    Paginated,
    Team,
} from '@/types';

const props = defineProps<{
    campaign: CampaignDetails;
    stats: CampaignStatsSummary;
    agentStats: AgentStat[];
    agents: { id: number; name: string; presence: string }[];
    contacts: Paginated<ContactCard>;
    filters: { status?: string; search?: string };
    contactStatuses: Option[];
    canManage: boolean;
    enabled: boolean;
    survey: { questions: number; responses: number; completed: number };
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
const args = computed(() => ({
    current_team: slug.value,
    campaign: props.campaign.id,
}));
const campaignError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.campaign,
);

const importForm = useForm<{ file: File | null }>({ file: null });
const contactForm = useForm({
    phone: '',
    first_name: '',
    last_name: '',
    company: '',
});
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

function act(action: 'start' | 'pause' | 'complete') {
    router.post(
        transition({ ...args.value, action }).url,
        {},
        { preserveScroll: true },
    );
}

function onFile(event: Event) {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function importContacts() {
    importForm.post(importMethod(args.value).url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => importForm.reset(),
    });
}

function addContact() {
    contactForm.post(storeContact(args.value).url, {
        preserveScroll: true,
        onSuccess: () => contactForm.reset(),
    });
}

function filter(pageNumber?: number) {
    router.get(
        show(args.value).url,
        {
            search: search.value || undefined,
            status: status.value || undefined,
            page: pageNumber,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['contacts', 'filters'],
        },
    );
}

function removeContact(contact: ContactCard) {
    router.delete(destroyContact({ ...args.value, contact: contact.id }).url, {
        preserveScroll: true,
    });
}

function removeCampaign() {
    if (
        window.confirm(
            `Supprimer la campagne « ${props.campaign.name} » et ses contacts ?`,
        )
    ) {
        router.delete(destroy(args.value).url);
    }
}

function percent(count: number, total: number) {
    return total > 0 ? Math.round((count * 100) / total) : 0;
}

// Live figures while the campaign runs.
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => {
        if (props.campaign.status === 'running' && !document.hidden) {
            router.reload({ only: ['stats', 'agentStats', 'agents'] });
        }
    }, 10000);
});

onUnmounted(() => {
    if (timer) {
        clearInterval(timer);
    }
});

const presenceVariant: Record<string, 'positive' | 'notice' | 'neutral'> = {
    available: 'positive',
    paused: 'notice',
    offline: 'neutral',
};
</script>

<template>
    <Head :title="campaign.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ campaign.name }}</h1>
                    <CampaignStatusBadge
                        :status="campaign.status"
                        :label="campaign.statusLabel"
                    />
                    <Badge variant="outline">{{
                        campaign.dialModeLabel
                    }}</Badge>
                    <span
                        v-if="
                            campaign.status === 'running' &&
                            !stats.live.withinHours
                        "
                        class="text-sm text-notice-strong"
                        >{{ t("Hors horaires d'appel") }}</span
                    >
                </div>
                <p
                    v-if="campaign.description"
                    class="mt-1 max-w-2xl text-sm text-muted-foreground"
                >
                    {{ campaign.description }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="
                        enabled &&
                        (campaign.status === 'draft' ||
                            campaign.status === 'paused' ||
                            campaign.status === 'completed')
                    "
                    @click="act('start')"
                    ><Play /> {{ t('Lancer') }}</Button
                >
                <Button
                    v-if="campaign.status === 'running'"
                    variant="outline"
                    @click="act('pause')"
                    ><Pause /> {{ t('Pause') }}</Button
                >
                <Button
                    v-if="
                        campaign.status !== 'completed' &&
                        campaign.status !== 'draft'
                    "
                    variant="outline"
                    @click="act('complete')"
                    ><CheckCircle2 /> {{ t('Terminer') }}</Button
                >
                <Button as-child variant="outline">
                    <a :href="exportMethod(args).url"
                        ><Download /> {{ t('Export CSV') }}</a
                    >
                </Button>
                <Button as-child variant="outline">
                    <Link
                        :href="`/${args.current_team}/campaigns/${campaign.id}/survey`"
                        ><ClipboardList />
                        {{
                            survey.questions
                                ? t('Questionnaire (:count)', {
                                      count: survey.questions,
                                  })
                                : t('Créer un questionnaire')
                        }}</Link
                    >
                </Button>
                <Button v-if="survey.responses" as-child variant="outline">
                    <Link
                        :href="`/${args.current_team}/campaigns/${campaign.id}/survey/results`"
                        ><BarChart3 />
                        {{
                            t('Résultats (:count)', {
                                count: survey.responses,
                            })
                        }}</Link
                    >
                </Button>
                <Button v-if="canManage" as-child variant="outline">
                    <Link :href="edit(args).url"
                        ><Pencil /> {{ t('Modifier') }}</Link
                    >
                </Button>
            </div>
        </header>

        <p
            v-if="campaignError"
            class="rounded-md bg-negative/10 p-3 text-sm text-negative-strong"
            role="alert"
        >
            {{ campaignError }}
        </p>

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-7">
            <StatCard label="Contacts" :value="stats.contacts.total" />
            <StatCard
                :label="t('Liste traitée')"
                :value="`${stats.contacts.penetration} %`"
                :hint="`${stats.contacts.callableNow} appelables maintenant`"
            />
            <StatCard label="Appels" :value="stats.attempts.total" />
            <StatCard
                :label="t('Taux de joignabilité')"
                :value="`${stats.attempts.connectRate} %`"
                tone="info"
            />
            <StatCard
                label="Conversion"
                :value="`${stats.attempts.conversionRate} %`"
                hint="succès / joints"
                tone="good"
            />
            <StatCard
                :label="t('Abandons (1 h)')"
                :value="`${stats.attempts.abandonRateHour} %`"
                :hint="`seuil ${campaign.maxAbandonRate} %`"
                :tone="
                    stats.attempts.abandonRateHour > campaign.maxAbandonRate
                        ? 'bad'
                        : 'default'
                "
            />
            <StatCard
                :label="t('Conversation moy.')"
                :value="formatDuration(stats.attempts.avgTalkSeconds)"
            />
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card>
                <CardHeader
                    ><CardTitle>{{
                        t('Résultats des appels')
                    }}</CardTitle></CardHeader
                >
                <CardContent class="space-y-2">
                    <div
                        v-for="row in stats.attempts.byResult.filter(
                            (r) => r.count > 0,
                        )"
                        :key="row.result"
                        class="text-sm"
                    >
                        <div class="flex justify-between">
                            <span>{{ row.label }}</span>
                            <span class="tabular-nums">{{ row.count }}</span>
                        </div>
                        <div
                            class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-primary"
                                :style="{
                                    width: `${percent(row.count, stats.attempts.total)}%`,
                                }"
                            />
                        </div>
                    </div>
                    <p
                        v-if="stats.attempts.total === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t("Aucun appel pour l'instant.") }}
                    </p>
                    <p class="pt-2 text-xs text-muted-foreground">
                        {{
                            t(
                                'En direct : :p0 en numérotation, :p1 en conversation, ratio :p2.',
                                {
                                    p0: stats.live.inFlight,
                                    p1: stats.live.connected,
                                    p2: stats.live.effectiveRatio,
                                },
                            )
                        }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle>{{
                        t('Qualifications')
                    }}</CardTitle></CardHeader
                >
                <CardContent class="space-y-2">
                    <div
                        v-for="row in stats.dispositions"
                        :key="row.id"
                        class="flex items-center justify-between text-sm"
                    >
                        <span class="flex items-center gap-2">
                            <span
                                class="size-2 rounded-full"
                                :class="{
                                    'bg-positive': row.outcome === 'success',
                                    'bg-muted-foreground':
                                        row.outcome === 'final',
                                    'bg-informative':
                                        row.outcome === 'retry' ||
                                        row.outcome === 'callback',
                                    'bg-negative': row.outcome === 'dnc',
                                }"
                            />
                            {{ row.label }}
                        </span>
                        <span class="tabular-nums">{{ row.count }}</span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle>{{ t('Agents') }}</CardTitle></CardHeader
                >
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Agent') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Appels')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Joints')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Succès')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="agent in agents" :key="agent.id">
                                <TableCell>
                                    <StatusLight
                                        :variant="
                                            presenceVariant[agent.presence] ??
                                            'neutral'
                                        "
                                        >{{ agent.name }}</StatusLight
                                    >
                                </TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    agentStats.find((s) => s.id === agent.id)
                                        ?.calls ?? 0
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    agentStats.find((s) => s.id === agent.id)
                                        ?.connected ?? 0
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    agentStats.find((s) => s.id === agent.id)
                                        ?.successes ?? 0
                                }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-if="agents.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('Aucun agent affecté.') }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Contacts') }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div v-if="canManage" class="grid gap-4 lg:grid-cols-2">
                    <form
                        class="space-y-2 rounded-lg border p-4"
                        @submit.prevent="importContacts"
                    >
                        <div class="font-medium">
                            {{ t('Importer un fichier CSV') }}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'Colonnes reconnues : telephone, prenom, nom, entreprise, email, priorite. Les autres colonnes sont gardées pour le script. Séparateur virgule ou point-virgule.',
                                )
                            }}
                        </p>
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            class="block w-full text-sm"
                            :aria-label="t('Fichier CSV')"
                            @change="onFile"
                        />
                        <InputError :message="importForm.errors.file" />
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                !importForm.file || importForm.processing
                            "
                            ><Upload /> {{ t('Importer') }}</Button
                        >
                    </form>
                    <form
                        class="grid gap-2 rounded-lg border p-4 sm:grid-cols-2"
                        @submit.prevent="addContact"
                    >
                        <div class="font-medium sm:col-span-2">
                            {{ t('Ajouter un contact') }}
                        </div>
                        <Input
                            v-model="contactForm.phone"
                            :placeholder="t('Téléphone *')"
                            :aria-label="t('Téléphone')"
                            required
                        />
                        <Input
                            v-model="contactForm.company"
                            placeholder="Entreprise"
                            aria-label="Entreprise"
                        />
                        <Input
                            v-model="contactForm.first_name"
                            :placeholder="t('Prénom')"
                            :aria-label="t('Prénom')"
                        />
                        <Input
                            v-model="contactForm.last_name"
                            placeholder="Nom"
                            aria-label="Nom"
                        />
                        <InputError
                            class="sm:col-span-2"
                            :message="contactForm.errors.phone"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            class="justify-self-start"
                            :disabled="contactForm.processing"
                            >{{ t('Ajouter') }}</Button
                        >
                    </form>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="t('Nom, téléphone, entreprise')"
                        class="max-w-xs"
                        aria-label="Rechercher"
                        @keyup.enter="filter()"
                    />
                    <select
                        v-model="status"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                        aria-label="Statut"
                        @change="filter()"
                    >
                        <option value="">{{ t('Tous les statuts') }}</option>
                        <option
                            v-for="s in contactStatuses"
                            :key="s.value"
                            :value="s.value"
                        >
                            {{ s.label }}
                            ({{
                                stats.contacts.byStatus.find(
                                    (b) => b.status === s.value,
                                )?.count ?? 0
                            }})
                        </option>
                    </select>
                    <Button variant="outline" size="sm" @click="filter()">{{
                        t('Filtrer')
                    }}</Button>
                    <Button
                        v-if="canManage"
                        variant="ghost"
                        size="sm"
                        class="ml-auto"
                        @click="
                            router.post(
                                recycle(args).url,
                                {},
                                { preserveScroll: true },
                            )
                        "
                        ><RotateCcw />
                        {{ t('Recycler les tentatives épuisées') }}</Button
                    >
                </div>

                <div class="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Contact') }}</TableHead>
                                <TableHead>{{ t('Téléphone') }}</TableHead>
                                <TableHead>{{ t('Statut') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Tent.')
                                }}</TableHead>
                                <TableHead>{{
                                    t('Dernier résultat')
                                }}</TableHead>
                                <TableHead>{{ t('Qualification') }}</TableHead>
                                <TableHead>{{ t('Prochain appel') }}</TableHead>
                                <TableHead v-if="canManage" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="contact in contacts.data"
                                :key="contact.id"
                            >
                                <TableCell>
                                    <div class="font-medium">
                                        {{ contact.name }}
                                    </div>
                                    <div
                                        v-if="contact.company"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ contact.company }}
                                    </div>
                                </TableCell>
                                <TableCell class="font-mono text-xs">{{
                                    contact.phone
                                }}</TableCell>
                                <TableCell>
                                    <Badge variant="outline">{{
                                        contact.statusLabel
                                    }}</Badge>
                                </TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    contact.attempts
                                }}</TableCell>
                                <TableCell class="text-sm">{{
                                    contact.lastResultLabel ?? '—'
                                }}</TableCell>
                                <TableCell class="text-sm">{{
                                    contact.disposition ?? '—'
                                }}</TableCell>
                                <TableCell class="text-sm">{{
                                    formatDate(contact.nextAttemptAt)
                                }}</TableCell>
                                <TableCell v-if="canManage" class="text-right">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Supprimer ${contact.name}`"
                                        @click="removeContact(contact)"
                                        ><Trash2
                                    /></Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted-foreground"
                        >{{ contacts.total }} contact(s) — page
                        {{ contacts.current_page }}/{{
                            contacts.last_page
                        }}</span
                    >
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="contacts.current_page <= 1"
                            @click="filter(contacts.current_page - 1)"
                            >{{ t('Précédent') }}</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                contacts.current_page >= contacts.last_page
                            "
                            @click="filter(contacts.current_page + 1)"
                            >{{ t('Suivant') }}</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>

        <div v-if="canManage && campaign.status !== 'running'">
            <Button
                variant="ghost"
                class="text-negative-strong"
                @click="removeCampaign"
                ><Trash2 /> {{ t('Supprimer la campagne') }}</Button
            >
        </div>
    </div>
</template>
