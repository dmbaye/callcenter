<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import DestinationPicker from '@/components/routing/DestinationPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import {
    agents as saveAgentsRoute,
    destroy,
    hours as saveHoursRoute,
    index,
    store,
    update,
} from '@/routes/asterisk/queues';
import {
    destroy as destroyClosure,
    store as storeClosure,
} from '@/routes/asterisk/queues/closures';

type Hour = { weekday: number; opens_at: string; closes_at: string };

type QueueSettings = {
    id: number;
    name: string;
    asterisk_name: string;
    campaign: boolean;
    strategy?: string | null;
    timeout?: number | string | null;
    retry?: number | string | null;
    wrapuptime?: number | string | null;
    maxlen?: number | string | null;
    musiconhold?: string | null;
    ringinuse?: string | null;
    joinempty?: string | null;
    leavewhenempty?: string | null;
    servicelevel?: number | string | null;
    announce_position?: string | null;
    announce_holdtime?: string | null;
    announce_frequency?: number | string | null;
    periodic_announce?: string | null;
    periodic_announce_frequency?: number | string | null;
    announce?: string | null;
    max_wait: number | null;
    overflow_type: string | null;
    overflow_target: string | null;
    callback_offer: boolean;
    survey_enabled: boolean;
    emergency_closed: boolean;
    emergency_sound: string | null;
    closed_sound: string | null;
    record_calls: boolean;
    recording_notice: boolean;
    agents: Record<string, number>;
    hours: Hour[];
    closures: { id: number; date: string; label: string | null }[];
};

const props = defineProps<{
    queues: QueueSettings[];
    agents: { id: number; name: string; extension: string | null }[];
    ivrMenus: { id: number; name: string }[];
    strategies: string[];
    destinations: { value: string; label: string }[];
    sounds: Record<string, string>;
    closedSound: string;
    serviceLevel: { enabled: boolean; target: number };
    recording: { allowed: boolean; days: number };
    holdMusicClass: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Files d'attente", href: index() }],
    },
});

const strategyLabels: Record<string, string> = {
    ringall: 'Faire sonner tous les agents libres',
    roundrobin: 'Chacun son tour',
    rrmemory: 'Chacun son tour (reprend au suivant)',
    leastrecent: 'Agent libre depuis le plus longtemps',
    fewestcalls: "Agent ayant pris le moins d'appels",
    linear: "Toujours dans l'ordre de la liste",
    random: 'Au hasard',
    wrandom: 'Au hasard, en tenant compte du niveau',
};

const days = [
    'Lundi',
    'Mardi',
    'Mercredi',
    'Jeudi',
    'Vendredi',
    'Samedi',
    'Dimanche',
];

const tabs = [
    { key: 'general', label: t('Général') },
    { key: 'agents', label: 'Agents' },
    { key: 'hours', label: t('Horaires et fermetures') },
    { key: 'waiting', label: t('Attente et débordement') },
    { key: 'after', label: t('Enregistrement et enquête') },
] as const;

type TabKey = (typeof tabs)[number]['key'];

const selectedId = ref<number | null>(props.queues[0]?.id ?? null);
const tab = ref<TabKey>('general');
const selected = computed(
    () => props.queues.find((q) => q.id === selectedId.value) ?? null,
);
const otherQueues = computed(() =>
    props.queues
        .filter((q) => q.id !== selectedId.value)
        .map((q) => ({ id: q.id, name: q.name })),
);

const text = (value: unknown) =>
    value === null || value === undefined ? '' : String(value);

/* ---------- New queue ---------- */
const createForm = useForm({ name: '', strategy: 'rrmemory' });

function createQueue() {
    createForm.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            selectedId.value =
                props.queues[props.queues.length - 1]?.id ?? selectedId.value;
        },
    });
}

/* ---------- One form for the queue settings (Général, Attente, Après) ---------- */
function settingsFrom(q: QueueSettings | null) {
    return {
        id: q?.id ?? 0,
        name: q?.name ?? '',
        strategy: text(q?.strategy) || 'rrmemory',
        timeout: text(q?.timeout),
        retry: text(q?.retry),
        wrapuptime: text(q?.wrapuptime),
        maxlen: text(q?.maxlen),
        musiconhold: text(q?.musiconhold) || 'default',
        ringinuse: text(q?.ringinuse) || 'no',
        joinempty: text(q?.joinempty) || 'yes',
        leavewhenempty: text(q?.leavewhenempty) || 'no',
        servicelevel: text(q?.servicelevel),
        announce_position: text(q?.announce_position),
        announce_holdtime: text(q?.announce_holdtime),
        announce_frequency: text(q?.announce_frequency),
        periodic_announce: text(q?.periodic_announce),
        periodic_announce_frequency: text(q?.periodic_announce_frequency),
        announce: text(q?.announce),
        max_wait: text(q?.max_wait),
        overflow_type: q?.overflow_type ?? null,
        overflow_target: q?.overflow_target ?? null,
        callback_offer: q?.callback_offer ?? false,
        survey_enabled: q?.survey_enabled ?? false,
        emergency_closed: q?.emergency_closed ?? false,
        emergency_sound: text(q?.emergency_sound),
        closed_sound: text(q?.closed_sound),
        record_calls: q?.record_calls ?? false,
        recording_notice: q?.recording_notice ?? true,
    };
}

type SettingsForm = ReturnType<typeof settingsFrom>;

const settings = useForm(settingsFrom(selected.value));

/** Each tab saves only its own fields (plus id and name). */
const tabFields: Record<
    'general' | 'waiting' | 'after' | 'hours',
    (keyof SettingsForm)[]
> = {
    general: [
        'strategy',
        'timeout',
        'retry',
        'wrapuptime',
        'maxlen',
        'musiconhold',
        'ringinuse',
        'joinempty',
        'leavewhenempty',
        'servicelevel',
    ],
    waiting: [
        'max_wait',
        'overflow_type',
        'overflow_target',
        'callback_offer',
        'announce_position',
        'announce_holdtime',
        'announce_frequency',
        'periodic_announce',
        'periodic_announce_frequency',
        'announce',
    ],
    after: ['record_calls', 'recording_notice', 'survey_enabled'],
    hours: ['emergency_closed', 'emergency_sound', 'closed_sound'],
};

function saveSettings(part: keyof typeof tabFields) {
    settings
        .transform((data) => {
            const payload: Record<string, unknown> = {
                id: data.id,
                name: data.name,
            };

            for (const key of tabFields[part]) {
                payload[key] = data[key];
            }

            return payload;
        })
        .put(update().url, { preserveScroll: true });
}

function deleteQueue() {
    const q = selected.value;

    if (
        q &&
        confirm(
            `Supprimer la file « ${q.name} » ? Ses agents, horaires et sa liste de rappels sont supprimés.`,
        )
    ) {
        router.delete(destroy().url, {
            data: { id: q.id },
            preserveScroll: true,
            onSuccess: () => (selectedId.value = props.queues[0]?.id ?? null),
        });
    }
}

/* ---------- Agents ---------- */
const levels = [
    { value: 0, label: 'Principal' },
    { value: 1, label: 'Secours' },
    { value: 2, label: t('Dernier recours') },
];

const agentForm = useForm<{ agents: Record<string, number> }>({ agents: {} });

function toggleAgent(id: number) {
    const key = String(id);
    const next = { ...agentForm.agents };

    if (key in next) {
        delete next[key];
    } else {
        next[key] = 0;
    }

    agentForm.agents = next;
}

function saveAgents() {
    if (selected.value) {
        agentForm.put(saveAgentsRoute(selected.value.id).url, {
            preserveScroll: true,
        });
    }
}

/* ---------- Hours ---------- */
type DayRow = {
    weekday: number;
    open: boolean;
    opens_at: string;
    closes_at: string;
};

function hoursFrom(q: QueueSettings | null): DayRow[] {
    return days.map((_, weekday) => {
        const hour = q?.hours.find((h) => h.weekday === weekday);

        return {
            weekday,
            open: !!hour,
            opens_at: hour?.opens_at ?? '08:00',
            closes_at: hour?.closes_at ?? '17:00',
        };
    });
}

const hoursForm = useForm<{ days: DayRow[] }>({
    days: hoursFrom(selected.value),
});
const hoursErrors = computed(() => hoursForm.errors as Record<string, string>);
const alwaysOpen = computed(() => hoursForm.days.every((d) => !d.open));

function copyMondayToWeekdays() {
    const monday = hoursForm.days[0];

    hoursForm.days = hoursForm.days.map((d) =>
        d.weekday <= 4
            ? {
                  ...d,
                  open: monday.open,
                  opens_at: monday.opens_at,
                  closes_at: monday.closes_at,
              }
            : d,
    );
}

function saveHours() {
    if (!selected.value) {
        return;
    }

    hoursForm
        .transform((data) => ({
            days: data.days
                .filter((d) => d.open)
                .map(({ weekday, opens_at, closes_at }) => ({
                    weekday,
                    opens_at,
                    closes_at,
                })),
        }))
        .put(saveHoursRoute(selected.value.id).url, { preserveScroll: true });
}

const closureForm = useForm({ date: '', label: '', all_queues: false });

/* Optional: share of calls answered within each queue's threshold. */
const serviceLevelForm = useForm({
    enabled: props.serviceLevel.enabled,
    target: props.serviceLevel.target,
});

function saveServiceLevel() {
    serviceLevelForm.put('/settings/asterisk/queues/service-level', {
        preserveScroll: true,
    });
}

function addClosure() {
    if (selected.value) {
        closureForm.post(storeClosure(selected.value.id).url, {
            preserveScroll: true,
            onSuccess: () => closureForm.reset(),
        });
    }
}

function removeClosure(id: number) {
    router.delete(destroyClosure(id).url, { preserveScroll: true });
}

/* ---------- Switching queue reloads every form ---------- */
watch(
    selected,
    (q) => {
        settings.defaults(settingsFrom(q));
        settings.reset();
        settings.clearErrors();
        agentForm.defaults({ agents: { ...q?.agents } });
        agentForm.reset();
        hoursForm.defaults({ days: hoursFrom(q) });
        hoursForm.reset();
        hoursForm.clearErrors();
    },
    { immediate: true },
);

const selectClass = 'h-9 rounded-md border bg-background px-3 text-sm';
</script>

<template>
    <Head :title="t('Files d\'attente')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Files d\'attente')"
            :description="
                t(
                    'Tout ce qui concerne une file au même endroit : réglages, agents, horaires, ce qui se passe quand personne ne répond, et l\'enquête après l\'appel.',
                )
            "
        />

        <form
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="createQueue"
        >
            <div class="grid gap-2">
                <Label for="new-queue">{{ t('Nouvelle file') }}</Label>
                <Input
                    id="new-queue"
                    v-model="createForm.name"
                    :placeholder="t('Support, Ventes…')"
                    maxlength="60"
                    required
                />
            </div>
            <Button type="submit" :disabled="createForm.processing"
                ><Plus /> {{ t('Créer') }}</Button
            >
            <InputError class="w-full" :message="createForm.errors.name" />
        </form>

        <form
            class="flex flex-wrap items-end gap-x-6 gap-y-3 rounded-md border p-3"
            data-test="service-level"
            @submit.prevent="saveServiceLevel"
        >
            <label class="flex max-w-md items-start gap-2 text-sm">
                <input
                    v-model="serviceLevelForm.enabled"
                    type="checkbox"
                    class="mt-0.5 size-4"
                />
                <span>
                    <span class="font-medium">{{
                        t('Suivre le niveau de service')
                    }}</span>
                    <span class="block text-muted-foreground">{{
                        t(
                            'Part des appels décrochés dans le délai de chaque file, affichée en supervision. Les files sans délai reçoivent 20 s.',
                        )
                    }}</span>
                </span>
            </label>
            <div v-if="serviceLevelForm.enabled" class="grid gap-2">
                <Label for="sl-target">{{ t('Objectif (%)') }}</Label>
                <Input
                    id="sl-target"
                    v-model.number="serviceLevelForm.target"
                    type="number"
                    min="1"
                    max="100"
                    class="w-24"
                />
                <InputError :message="serviceLevelForm.errors.target" />
            </div>
            <Button
                type="submit"
                variant="outline"
                :disabled="serviceLevelForm.processing"
                >{{ t('Enregistrer') }}</Button
            >
        </form>

        <p v-if="queues.length === 0" class="text-sm text-muted-foreground">
            {{ t("Aucune file pour l'instant.") }}
        </p>

        <template v-else>
            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Files">
                <Button
                    v-for="q in queues"
                    :key="q.id"
                    size="sm"
                    role="tab"
                    :aria-selected="q.id === selectedId"
                    :variant="q.id === selectedId ? 'default' : 'outline'"
                    @click="selectedId = q.id"
                >
                    {{ q.name }}
                    <Badge
                        v-if="q.emergency_closed"
                        class="ml-1 border-transparent bg-negative text-white"
                        >{{ t('fermée') }}</Badge
                    >
                    <Badge
                        v-else-if="q.campaign"
                        variant="outline"
                        class="ml-1"
                        >{{ t('campagne') }}</Badge
                    >
                </Button>
            </div>

            <div v-if="selected" class="rounded-lg border">
                <nav
                    class="flex flex-wrap gap-1 border-b p-2"
                    :aria-label="t('Réglages de la file')"
                >
                    <button
                        v-for="item in tabs"
                        :key="item.key"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm"
                        :class="
                            tab === item.key
                                ? 'bg-accent font-semibold'
                                : 'text-muted-foreground hover:bg-accent/50'
                        "
                        :aria-pressed="tab === item.key"
                        @click="tab = item.key"
                    >
                        {{ t(item.label) }}
                    </button>
                </nav>

                <div class="p-4">
                    <!-- Général -->
                    <form
                        v-if="tab === 'general'"
                        class="space-y-4"
                        @submit.prevent="saveSettings('general')"
                    >
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div class="grid gap-2">
                                <Label for="qname">{{ t('Nom') }}</Label>
                                <Input
                                    id="qname"
                                    v-model="settings.name"
                                    maxlength="60"
                                    required
                                />
                                <InputError :message="settings.errors.name" />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="strategy">{{
                                    t("Qui reçoit l'appel ?")
                                }}</Label>
                                <select
                                    id="strategy"
                                    v-model="settings.strategy"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="s in strategies"
                                        :key="s"
                                        :value="s"
                                    >
                                        {{ strategyLabels[s] ?? s }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <Label for="timeout">{{
                                    t('Sonnerie par agent (s)')
                                }}</Label>
                                <Input
                                    id="timeout"
                                    v-model="settings.timeout"
                                    type="number"
                                    min="0"
                                    placeholder="15"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="retry">{{
                                    t("Pause avant l'agent suivant (s)")
                                }}</Label>
                                <Input
                                    id="retry"
                                    v-model="settings.retry"
                                    type="number"
                                    min="0"
                                    placeholder="5"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="wrapup">{{
                                    t(
                                        'Temps de qualification après un appel (s)',
                                    )
                                }}</Label>
                                <Input
                                    id="wrapup"
                                    v-model="settings.wrapuptime"
                                    type="number"
                                    min="0"
                                    placeholder="0"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="maxlen">{{
                                    t('Appelants en attente max.')
                                }}</Label>
                                <Input
                                    id="maxlen"
                                    v-model="settings.maxlen"
                                    type="number"
                                    min="0"
                                    :placeholder="t('0 = illimité')"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="moh">{{
                                    t("Musique d'attente")
                                }}</Label>
                                <select
                                    id="moh"
                                    v-model="settings.musiconhold"
                                    class="h-9 rounded-md border bg-background px-2 text-sm"
                                >
                                    <option value="default">
                                        {{ t('Musique de la plateforme') }}
                                    </option>
                                    <option
                                        v-if="holdMusicClass"
                                        :value="holdMusicClass"
                                    >
                                        {{ t('Notre musique d’attente') }}
                                    </option>
                                    <option
                                        v-if="
                                            settings.musiconhold &&
                                            settings.musiconhold !== 'default' &&
                                            settings.musiconhold !== holdMusicClass
                                        "
                                        :value="settings.musiconhold"
                                    >
                                        {{ settings.musiconhold }}
                                    </option>
                                </select>
                                <p
                                    v-if="!holdMusicClass"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        t(
                                            'Ajoutez votre propre musique dans Paramètres → Musique d’attente.',
                                        )
                                    }}
                                </p>
                                <InputError
                                    :message="settings.errors.musiconhold"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="ringinuse">{{
                                    t('Sonner un agent déjà en ligne')
                                }}</Label>
                                <select
                                    id="ringinuse"
                                    v-model="settings.ringinuse"
                                    :class="selectClass"
                                >
                                    <option value="no">{{ t('Non') }}</option>
                                    <option value="yes">{{ t('Oui') }}</option>
                                </select>
                            </div>
                        </div>

                        <details class="rounded-md border p-3 text-sm">
                            <summary class="cursor-pointer font-medium">
                                {{ t('Options avancées') }}
                            </summary>
                            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label for="joinempty">{{
                                        t(
                                            'Accepter les appels sans agent connecté',
                                        )
                                    }}</Label>
                                    <select
                                        id="joinempty"
                                        v-model="settings.joinempty"
                                        :class="selectClass"
                                    >
                                        <option value="yes">
                                            {{ t("Oui (l'appelant attend)") }}
                                        </option>
                                        <option value="no">
                                            {{
                                                t('Non (débordement immédiat)')
                                            }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="leave">{{
                                        t('Faire sortir si plus aucun agent')
                                    }}</Label>
                                    <select
                                        id="leave"
                                        v-model="settings.leavewhenempty"
                                        :class="selectClass"
                                    >
                                        <option value="no">
                                            {{ t('Non') }}
                                        </option>
                                        <option value="yes">
                                            {{ t('Oui (débordement)') }}
                                        </option>
                                    </select>
                                </div>
                                <div
                                    v-if="serviceLevel.enabled"
                                    class="grid gap-2"
                                >
                                    <Label for="sl">{{
                                        t('Délai de décroché visé (s)')
                                    }}</Label>
                                    <Input
                                        id="sl"
                                        v-model="settings.servicelevel"
                                        type="number"
                                        min="0"
                                        placeholder="20"
                                    />
                                </div>
                            </div>
                            <p
                                class="mt-3 font-mono text-xs text-muted-foreground"
                            >
                                {{
                                    t('Nom Asterisk : :p0', {
                                        p0: selected.asterisk_name,
                                    })
                                }}
                            </p>
                        </details>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                :disabled="settings.processing"
                                >{{ t('Enregistrer') }}</Button
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                class="ml-auto text-negative-strong"
                                @click="deleteQueue"
                                ><Trash2 /> {{ t('Supprimer la file') }}</Button
                            >
                        </div>
                    </form>

                    <!-- Agents -->
                    <form
                        v-else-if="tab === 'agents'"
                        class="space-y-4"
                        @submit.prevent="saveAgents"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Les agents « Secours » ne reçoivent un appel que si tous les agents « Principal » sont occupés, et « Dernier recours » seulement après eux.',
                                )
                            }}
                        </p>
                        <ul class="divide-y rounded-md border">
                            <li
                                v-for="a in agents"
                                :key="a.id"
                                class="flex flex-wrap items-center gap-3 px-3 py-2 text-sm"
                            >
                                <label class="flex flex-1 items-center gap-2">
                                    <input
                                        type="checkbox"
                                        class="size-4"
                                        :checked="
                                            String(a.id) in agentForm.agents
                                        "
                                        @change="toggleAgent(a.id)"
                                    />
                                    <span class="font-medium">{{
                                        a.name
                                    }}</span>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            a.extension
                                                ? `poste ${a.extension}`
                                                : t('sans poste')
                                        }}</span
                                    >
                                </label>
                                <select
                                    v-if="String(a.id) in agentForm.agents"
                                    v-model.number="
                                        agentForm.agents[String(a.id)]
                                    "
                                    :class="selectClass"
                                    :aria-label="`Niveau de ${a.name}`"
                                >
                                    <option
                                        v-for="l in levels"
                                        :key="l.value"
                                        :value="l.value"
                                    >
                                        {{ l.label }}
                                    </option>
                                </select>
                            </li>
                            <li
                                v-if="agents.length === 0"
                                class="px-3 py-2 text-sm text-muted-foreground"
                            >
                                {{ t("Aucun agent dans l'équipe.") }}
                            </li>
                        </ul>
                        <InputError :message="agentForm.errors.agents" />
                        <Button
                            type="submit"
                            :disabled="agentForm.processing"
                            >{{ t('Enregistrer les agents') }}</Button
                        >
                    </form>

                    <!-- Horaires et fermetures -->
                    <div v-else-if="tab === 'hours'" class="space-y-8">
                        <form class="space-y-3" @submit.prevent="saveHours">
                            <h3 class="font-semibold">
                                {{ t("Horaires d'ouverture") }}
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    t(
                                        "En dehors de ces horaires, l'appelant entend « :p0 ».",
                                        {
                                            p0:
                                                settings.closed_sound ||
                                                closedSound,
                                        },
                                    )
                                }}
                                <template v-if="alwaysOpen">
                                    {{
                                        t(
                                            'Aucun jour coché : la file est ouverte 24 h/24.',
                                        )
                                    }}</template
                                >
                            </p>
                            <div class="space-y-2">
                                <div
                                    v-for="(d, i) in hoursForm.days"
                                    :key="d.weekday"
                                    class="grid grid-cols-[7rem_auto_1fr] items-center gap-3 text-sm sm:grid-cols-[7rem_6rem_8rem_8rem]"
                                >
                                    <span class="font-medium">{{
                                        days[d.weekday]
                                    }}</span>
                                    <label class="flex items-center gap-2">
                                        <input
                                            v-model="d.open"
                                            type="checkbox"
                                            class="size-4"
                                        />
                                        {{ d.open ? 'Ouvert' : t('Fermé') }}
                                    </label>
                                    <template v-if="d.open">
                                        <Input
                                            v-model="d.opens_at"
                                            type="time"
                                            :aria-label="`${days[d.weekday]} ouverture`"
                                        />
                                        <Input
                                            v-model="d.closes_at"
                                            type="time"
                                            :aria-label="`${days[d.weekday]} fermeture`"
                                        />
                                    </template>
                                    <InputError
                                        class="col-span-full"
                                        :message="
                                            hoursErrors[`days.${i}.closes_at`]
                                        "
                                    />
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    :disabled="hoursForm.processing"
                                    >{{ t('Enregistrer les horaires') }}</Button
                                >
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="copyMondayToWeekdays"
                                    >{{
                                        t(
                                            'Copier lundi sur toute la semaine (lun–ven)',
                                        )
                                    }}</Button
                                >
                            </div>
                        </form>

                        <section class="space-y-3">
                            <h3 class="font-semibold">
                                {{ t('Jours de fermeture') }}
                            </h3>
                            <form
                                class="grid items-end gap-3 sm:grid-cols-[10rem_1fr_auto_auto]"
                                @submit.prevent="addClosure"
                            >
                                <div class="grid gap-2">
                                    <Label for="cdate">{{ t('Date') }}</Label>
                                    <Input
                                        id="cdate"
                                        v-model="closureForm.date"
                                        type="date"
                                        required
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="clabel">{{ t('Motif') }}</Label>
                                    <Input
                                        id="clabel"
                                        v-model="closureForm.label"
                                        placeholder="Tabaski"
                                        maxlength="120"
                                    />
                                </div>
                                <label
                                    class="flex h-9 items-center gap-2 text-sm"
                                >
                                    <input
                                        v-model="closureForm.all_queues"
                                        type="checkbox"
                                        class="size-4"
                                    />
                                    {{ t('Toutes les files') }}
                                </label>
                                <Button
                                    type="submit"
                                    :disabled="closureForm.processing"
                                    >{{ t('Ajouter') }}</Button
                                >
                            </form>
                            <InputError :message="closureForm.errors.date" />
                            <ul class="divide-y rounded-md border text-sm">
                                <li
                                    v-for="c in selected.closures"
                                    :key="c.id"
                                    class="flex items-center justify-between gap-2 px-3 py-2"
                                >
                                    <span
                                        >{{ formatDate(c.date) }}
                                        <span class="text-muted-foreground">{{
                                            c.label ?? ''
                                        }}</span></span
                                    >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Supprimer ${c.date}`"
                                        @click="removeClosure(c.id)"
                                        ><Trash2
                                    /></Button>
                                </li>
                                <li
                                    v-if="selected.closures.length === 0"
                                    class="px-3 py-2 text-muted-foreground"
                                >
                                    {{ t('Aucune fermeture prévue.') }}
                                </li>
                            </ul>
                        </section>

                        <form
                            class="space-y-3"
                            @submit.prevent="saveSettings('hours')"
                        >
                            <h3 class="font-semibold">
                                {{ t('Message de fermeture') }}
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    t(
                                        'Joué en dehors des horaires et les jours de fermeture. Laissez vide pour le message par défaut.',
                                    )
                                }}
                            </p>
                            <div class="grid max-w-md gap-2">
                                <Label for="csound">{{
                                    t('Fichier audio')
                                }}</Label>
                                <Input
                                    id="csound"
                                    v-model="settings.closed_sound"
                                    :placeholder="closedSound"
                                />
                                <InputError
                                    :message="settings.errors.closed_sound"
                                />
                            </div>
                            <Button
                                type="submit"
                                :disabled="settings.processing"
                                >{{ t('Enregistrer') }}</Button
                            >
                        </form>

                        <form
                            class="space-y-3 rounded-md border p-3"
                            :class="
                                settings.emergency_closed
                                    ? 'border-negative'
                                    : ''
                            "
                            @submit.prevent="saveSettings('hours')"
                        >
                            <h3 class="font-semibold">
                                {{ t("Fermeture d'urgence") }}
                            </h3>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="settings.emergency_closed"
                                    type="checkbox"
                                    class="size-4"
                                />
                                {{
                                    t(
                                        'Fermer la file maintenant, quels que soient les horaires',
                                    )
                                }}
                            </label>
                            <div class="grid max-w-md gap-2">
                                <Label for="esound">{{
                                    t('Message joué')
                                }}</Label>
                                <Input
                                    id="esound"
                                    v-model="settings.emergency_sound"
                                    :placeholder="
                                        settings.closed_sound || closedSound
                                    "
                                />
                                <InputError
                                    :message="settings.errors.emergency_sound"
                                />
                            </div>
                            <Button
                                type="submit"
                                :disabled="settings.processing"
                                >{{ t('Enregistrer') }}</Button
                            >
                        </form>
                    </div>

                    <!-- Attente et débordement -->
                    <form
                        v-else-if="tab === 'waiting'"
                        class="space-y-6"
                        @submit.prevent="saveSettings('waiting')"
                    >
                        <section class="space-y-3">
                            <h3 class="font-semibold">
                                {{ t('Si personne ne répond') }}
                            </h3>
                            <div class="grid gap-4 md:grid-cols-[12rem_1fr]">
                                <div class="grid gap-2">
                                    <Label for="max_wait">{{
                                        t('Attente maximale (s)')
                                    }}</Label>
                                    <Input
                                        id="max_wait"
                                        v-model="settings.max_wait"
                                        type="number"
                                        min="10"
                                        max="3600"
                                        :placeholder="t('Illimitée')"
                                    />
                                    <InputError
                                        :message="settings.errors.max_wait"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="overflow">{{
                                        t(
                                            'Ensuite (ou si la file est pleine / sans agent)',
                                        )
                                    }}</Label>
                                    <DestinationPicker
                                        id="overflow"
                                        v-model:type="settings.overflow_type"
                                        v-model:target="
                                            settings.overflow_target
                                        "
                                        :destinations="destinations"
                                        :queues="otherQueues"
                                        :ivr-menus="ivrMenus"
                                        allow-empty
                                    />
                                    <InputError
                                        :message="
                                            settings.errors.overflow_target
                                        "
                                    />
                                </div>
                            </div>
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    v-model="settings.callback_offer"
                                    type="checkbox"
                                    class="mt-0.5 size-4"
                                />
                                <span>
                                    {{
                                        t(
                                            'Proposer « tapez 1 pour être rappelé » avant cette étape et quand la file est fermée.',
                                        )
                                    }}
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                'Les demandes arrivent dans le poste agent des agents de la file. Sons : :p0, :p1.',
                                                {
                                                    p0: sounds.callback_offer,
                                                    p1: sounds.callback_confirm,
                                                },
                                            )
                                        }}</span
                                    >
                                </span>
                            </label>
                        </section>

                        <section class="space-y-3">
                            <h3 class="font-semibold">
                                {{ t("Pendant l'attente") }}
                            </h3>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label for="pos">{{
                                        t('Annoncer la position')
                                    }}</Label>
                                    <select
                                        id="pos"
                                        v-model="settings.announce_position"
                                        :class="selectClass"
                                    >
                                        <option value="">{{ t('Non') }}</option>
                                        <option value="yes">
                                            {{ t('Oui') }}
                                        </option>
                                        <option value="more">
                                            {{ t('Seulement au-delà de 5') }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="hold">{{
                                        t("Annoncer le temps d'attente")
                                    }}</Label>
                                    <select
                                        id="hold"
                                        v-model="settings.announce_holdtime"
                                        :class="selectClass"
                                    >
                                        <option value="">{{ t('Non') }}</option>
                                        <option value="yes">
                                            {{ t('Oui') }}
                                        </option>
                                        <option value="once">
                                            {{ t('Une seule fois') }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="freq">{{
                                        t('Répéter toutes les (s)')
                                    }}</Label>
                                    <Input
                                        id="freq"
                                        v-model="settings.announce_frequency"
                                        type="number"
                                        min="0"
                                        :placeholder="t('0 = jamais')"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="periodic">{{
                                        t('Message périodique')
                                    }}</Label>
                                    <Input
                                        id="periodic"
                                        v-model="settings.periodic_announce"
                                        :placeholder="
                                            t('custom/merci-de-patienter')
                                        "
                                    />
                                    <InputError
                                        :message="
                                            settings.errors.periodic_announce
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="pfreq">{{
                                        t('Toutes les (s)')
                                    }}</Label>
                                    <Input
                                        id="pfreq"
                                        v-model="
                                            settings.periodic_announce_frequency
                                        "
                                        type="number"
                                        min="0"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="whisper">{{
                                        t("Annonce chuchotée à l'agent")
                                    }}</Label>
                                    <Input
                                        id="whisper"
                                        v-model="settings.announce"
                                        :placeholder="t('custom/file-support')"
                                    />
                                    <InputError
                                        :message="settings.errors.announce"
                                    />
                                </div>
                            </div>
                        </section>

                        <Button type="submit" :disabled="settings.processing">{{
                            t('Enregistrer')
                        }}</Button>
                    </form>

                    <!-- Enregistrement et enquête -->
                    <form
                        v-else
                        class="space-y-4"
                        @submit.prevent="saveSettings('after')"
                    >
                        <fieldset
                            class="space-y-3 rounded-lg border p-4"
                            :disabled="!recording.allowed"
                        >
                            <legend class="px-1 text-sm font-semibold">
                                {{ t('Enregistrement des appels') }}
                            </legend>
                            <p
                                v-if="!recording.allowed"
                                class="text-sm text-muted-foreground"
                            >
                                {{
                                    t(
                                        "Inclus dans les offres :plans. Changez d'offre depuis la page Abonnement.",
                                        {
                                            plans: $page.props.billing
                                                ?.includedIn?.call_recording,
                                        },
                                    )
                                }}
                            </p>
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    v-model="settings.record_calls"
                                    type="checkbox"
                                    class="mt-0.5 size-4"
                                />
                                <span>
                                    {{
                                        t(
                                            'Enregistrer les conversations de cette file',
                                        )
                                    }}
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                "À réécouter dans l'historique des appels.",
                                            )
                                        }}
                                        <template v-if="recording.allowed">{{
                                            t('Conservés :p0 jours.', {
                                                p0: recording.days,
                                            })
                                        }}</template>
                                        {{
                                            t(
                                                'Les appels de campagne utilisant cette file sont aussi enregistrés.',
                                            )
                                        }}</span
                                    >
                                </span>
                            </label>
                            <label
                                class="flex items-start gap-2 text-sm"
                                :class="{
                                    'opacity-50': !settings.record_calls,
                                }"
                            >
                                <input
                                    v-model="settings.recording_notice"
                                    type="checkbox"
                                    class="mt-0.5 size-4"
                                    :disabled="!settings.record_calls"
                                />
                                <span>
                                    {{
                                        t(
                                            "Prévenir l'appelant avant l'enregistrement",
                                        )
                                    }}
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                'Son : :p0 (« Cet appel peut être enregistré… »). Recommandé : informer vos clients est en général une obligation légale.',
                                                { p0: sounds.recording_notice },
                                            )
                                        }}</span
                                    >
                                </span>
                            </label>
                            <InputError
                                :message="settings.errors.record_calls"
                            />
                        </fieldset>

                        <label class="flex items-start gap-2 text-sm">
                            <input
                                v-model="settings.survey_enabled"
                                type="checkbox"
                                class="mt-0.5 size-4"
                            />
                            <span>
                                {{
                                    t(
                                        "Enquête de satisfaction : quand l'agent raccroche, l'appelant note l'appel de 1 à 5.",
                                    )
                                }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        t(
                                            'Sons : :p0, :p1. Résultats sur le tableau de bord.',
                                            {
                                                p0: sounds.survey,
                                                p1: sounds.survey_thanks,
                                            },
                                        )
                                    }}</span
                                >
                            </span>
                        </label>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    "Le temps de qualification accordé à l'agent se règle dans l'onglet Général.",
                                )
                            }}
                        </p>
                        <Button type="submit" :disabled="settings.processing">{{
                            t('Enregistrer')
                        }}</Button>
                    </form>
                </div>
            </div>
        </template>
    </div>
</template>
