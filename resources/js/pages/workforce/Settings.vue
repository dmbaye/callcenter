<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkforceNav from '@/components/workforce/WorkforceNav.vue';
import { t } from '@/lib/i18n';
import { planningUrl, shiftColor } from '@/lib/workforce';
import type {
    LeaveTypeCard,
    ShiftTemplateCard,
    Team,
    WorkforceShell,
} from '@/types';

type Rules = {
    max_consecutive_hours: number;
    min_rest_hours: number;
    min_rest_after_long_hours: number;
    max_weekly_hours: number;
    enforcement: 'block' | 'warn';
    late_tolerance_minutes: number;
    auto_approve_swaps: boolean;
};

const props = defineProps<{
    workforce: WorkforceShell;
    templates: ShiftTemplateCard[];
    colors: string[];
    rules: Rules;
    leaveTypes: LeaveTypeCard[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Planning',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning`
                    : '/',
            },
            {
                title: 'Réglages',
                href: props.currentTeam
                    ? `/${props.currentTeam.slug}/planning/settings`
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const canAdmin = computed(() => props.workforce.isAdmin);

function onError(errors: Record<string, string>) {
    toast.error(Object.values(errors)[0] ?? t('Enregistrement impossible.'));
}

// Shift blocks.
const shifts = ref(
    props.templates.map((tpl) => ({
        id: tpl.id as number | null,
        code: tpl.code,
        name: tpl.name,
        start_time: tpl.startTime,
        end_time: tpl.endTime,
        break_minutes: tpl.breakMinutes,
        min_agents: tpl.minAgents,
        color: tpl.color,
        active: tpl.active,
    })),
);

function addShift() {
    shifts.value.push({
        id: null,
        code: String.fromCharCode(65 + shifts.value.length),
        name: '',
        start_time: '00:00',
        end_time: '08:00',
        break_minutes: 60,
        min_agents: 0,
        color:
            props.colors[shifts.value.length % props.colors.length] ?? 'gray',
        active: true,
    });
}

const savingShifts = ref(false);

function saveShifts() {
    savingShifts.value = true;
    router.put(
        planningUrl(slug.value, 'settings/shifts'),
        { templates: shifts.value },
        {
            preserveScroll: true,
            onError,
            onFinish: () => (savingShifts.value = false),
        },
    );
}

function duration(start: string, end: string) {
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    let minutes = eh * 60 + em - (sh * 60 + sm);

    if (minutes <= 0) {
        minutes += 24 * 60;
    }

    return Math.round((minutes / 60) * 10) / 10;
}

// Rules.
const rules = ref<Rules>({ ...props.rules });
const savingRules = ref(false);

function saveRules() {
    savingRules.value = true;
    router.put(
        planningUrl(slug.value, 'settings/rules'),
        { ...rules.value },
        {
            preserveScroll: true,
            onError,
            onFinish: () => (savingRules.value = false),
        },
    );
}

// Leave types.
const types = ref(
    props.leaveTypes.map((type) => ({
        id: type.id as number | null,
        code: type.code,
        name: type.name,
        paid: type.paid,
        annual_days: type.annualDays === null ? '' : String(type.annualDays),
        active: type.active,
    })),
);

function addType() {
    types.value.push({
        id: null,
        code: '',
        name: '',
        paid: true,
        annual_days: '',
        active: true,
    });
}

const savingTypes = ref(false);

function saveTypes() {
    savingTypes.value = true;
    router.put(
        planningUrl(slug.value, 'settings/leave-types'),
        {
            types: types.value.map((type) => ({
                ...type,
                annual_days:
                    type.annual_days === '' ? null : Number(type.annual_days),
            })),
        },
        {
            preserveScroll: true,
            onError,
            onFinish: () => (savingTypes.value = false),
        },
    );
}
</script>

<template>
    <Head :title="t('Réglages du planning')" />

    <div class="flex flex-col gap-8 p-4 md:p-6">
        <WorkforceNav :workforce="workforce" current="settings" />

        <!-- Shifts -->
        <section class="grid gap-4">
            <header>
                <h2 class="text-base font-semibold">{{ t('Postes') }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Un poste dont la fin précède le début se termine le lendemain. Les changements ne touchent que les nouvelles affectations.',
                        )
                    }}
                </p>
            </header>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[860px] text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Code') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Nom') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Début') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Fin') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Pause (min)') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Effectif min.') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Couleur') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Actif') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(shift, index) in shifts"
                            :key="shift.id ?? `new-${index}`"
                        >
                            <td class="px-3 py-2">
                                <Input
                                    v-model="shift.code"
                                    class="w-16"
                                    maxlength="10"
                                    :aria-label="t('Code')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="shift.name"
                                    maxlength="60"
                                    :aria-label="t('Nom')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="shift.start_time"
                                    type="time"
                                    :aria-label="t('Début')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <Input
                                        v-model="shift.end_time"
                                        type="time"
                                        :aria-label="t('Fin')"
                                    />
                                    <span
                                        class="text-xs whitespace-nowrap text-muted-foreground"
                                        >{{
                                            duration(
                                                shift.start_time,
                                                shift.end_time,
                                            )
                                        }}
                                        h</span
                                    >
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model.number="shift.break_minutes"
                                    type="number"
                                    min="0"
                                    max="240"
                                    class="w-20"
                                    :aria-label="t('Pause (min)')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model.number="shift.min_agents"
                                    type="number"
                                    min="0"
                                    max="500"
                                    class="w-20"
                                    :aria-label="t('Effectif min.')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <div
                                    class="flex gap-1"
                                    role="radiogroup"
                                    :aria-label="t('Couleur')"
                                >
                                    <button
                                        v-for="color in colors"
                                        :key="color"
                                        type="button"
                                        role="radio"
                                        :aria-checked="shift.color === color"
                                        :aria-label="color"
                                        class="size-5 rounded-full ring-offset-2 ring-offset-background"
                                        :class="[
                                            shiftColor(color).dot,
                                            shift.color === color
                                                ? 'ring-2 ring-foreground'
                                                : '',
                                        ]"
                                        @click="shift.color = color"
                                    />
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <Checkbox
                                    v-model="shift.active"
                                    :aria-label="t('Actif')"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="shifts.length >= 12"
                    @click="addShift"
                >
                    <Plus class="size-4" />
                    {{ t('Ajouter un poste') }}
                </Button>
                <Button
                    size="sm"
                    class="ml-auto"
                    :disabled="savingShifts"
                    @click="saveShifts"
                    >{{ t('Enregistrer les postes') }}</Button
                >
            </div>
        </section>

        <!-- Rules -->
        <section class="grid gap-4">
            <header>
                <h2 class="text-base font-semibold">
                    {{ t('Règles de travail') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    <template v-if="canAdmin">{{
                        t(
                            'Vérifiées à chaque affectation, échange et copie de semaine.',
                        )
                    }}</template>
                    <template v-else>{{
                        t(
                            "Seuls un planificateur ou un administrateur de l'équipe peuvent modifier ces règles.",
                        )
                    }}</template>
                </p>
            </header>
            <fieldset
                :disabled="!canAdmin"
                class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div class="grid gap-2">
                    <Label for="r-max">{{ t('Heures d’affilée max.') }}</Label>
                    <Input
                        id="r-max"
                        v-model.number="rules.max_consecutive_hours"
                        type="number"
                        min="1"
                        max="24"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Deux postes enchaînés (A puis B) comptent comme un seul bloc.',
                            )
                        }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="r-rest">{{ t('Repos minimum (h)') }}</Label>
                    <Input
                        id="r-rest"
                        v-model.number="rules.min_rest_hours"
                        type="number"
                        min="0"
                        max="48"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="r-rest-long">{{
                        t('Repos après un bloc de plus de 8 h')
                    }}</Label>
                    <Input
                        id="r-rest-long"
                        v-model.number="rules.min_rest_after_long_hours"
                        type="number"
                        min="0"
                        max="72"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('Empêche deux journées de 16 h à la suite.') }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="r-week">{{
                        t('Heures max. par semaine')
                    }}</Label>
                    <Input
                        id="r-week"
                        v-model.number="rules.max_weekly_hours"
                        type="number"
                        min="0"
                        max="100"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="r-late">{{
                        t('Tolérance de retard (min)')
                    }}</Label>
                    <Input
                        id="r-late"
                        v-model.number="rules.late_tolerance_minutes"
                        type="number"
                        min="0"
                        max="120"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="r-mode">{{
                        t('Si une règle est dépassée')
                    }}</Label>
                    <SelectField
                        id="r-mode"
                        v-model="rules.enforcement"
                        :disabled="!canAdmin"
                        :options="[
                            {
                                value: 'block',
                                label: t('Refuser l’affectation'),
                            },
                            {
                                value: 'warn',
                                label: t('Autoriser avec un avertissement'),
                            },
                        ]"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Les chevauchements et les congés approuvés bloquent toujours.',
                            )
                        }}
                    </p>
                </div>
                <label
                    class="flex items-start gap-2 text-sm sm:col-span-2 lg:col-span-3"
                >
                    <Checkbox
                        v-model="rules.auto_approve_swaps"
                        class="mt-0.5"
                    />
                    <span>
                        <span class="font-medium">{{
                            t('Approuver automatiquement les échanges')
                        }}</span>
                        <span class="block text-muted-foreground">
                            {{
                                t(
                                    'Quand les deux agents sont d’accord, partagent une file et qu’aucune règle n’est dépassée (pas d’heures sup.).',
                                )
                            }}
                        </span>
                    </span>
                </label>
            </fieldset>
            <div v-if="canAdmin" class="flex justify-end">
                <Button size="sm" :disabled="savingRules" @click="saveRules">{{
                    t('Enregistrer les règles')
                }}</Button>
            </div>
        </section>

        <!-- Leave types -->
        <section class="grid gap-4">
            <header>
                <h2 class="text-base font-semibold">
                    {{ t('Types de congé') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Laissez le quota vide pour un type sans solde limité (maladie, sans solde…).',
                        )
                    }}
                </p>
            </header>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Code') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Nom') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Jours par an') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Payé') }}
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                {{ t('Actif') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(type, index) in types"
                            :key="type.id ?? `new-${index}`"
                        >
                            <td class="px-3 py-2">
                                <Input
                                    v-model="type.code"
                                    class="w-28"
                                    maxlength="20"
                                    :disabled="!canAdmin"
                                    :aria-label="t('Code')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="type.name"
                                    maxlength="60"
                                    :disabled="!canAdmin"
                                    :aria-label="t('Nom')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model="type.annual_days"
                                    type="number"
                                    min="0"
                                    max="366"
                                    step="0.5"
                                    class="w-24"
                                    :placeholder="t('illimité')"
                                    :disabled="!canAdmin"
                                    :aria-label="t('Jours par an')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Checkbox
                                    v-model="type.paid"
                                    :disabled="!canAdmin"
                                    :aria-label="t('Payé')"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <Checkbox
                                    v-model="type.active"
                                    :disabled="!canAdmin"
                                    :aria-label="t('Actif')"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="canAdmin" class="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="types.length >= 20"
                    @click="addType"
                >
                    <Plus class="size-4" />
                    {{ t('Ajouter un type') }}
                </Button>
                <Button
                    size="sm"
                    class="ml-auto"
                    :disabled="savingTypes"
                    @click="saveTypes"
                    >{{ t('Enregistrer les types') }}</Button
                >
            </div>
        </section>
    </div>
</template>
