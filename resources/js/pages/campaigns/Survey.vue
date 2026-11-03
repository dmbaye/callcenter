<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    BarChart3,
    Copy,
    GitBranch,
    Plus,
    Sparkles,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import SurveyForm from '@/components/campaigns/SurveyForm.vue';
import { choicesOf, newKey, optionValue } from '@/components/campaigns/survey';
import type {
    SurveyAnswers,
    SurveyQuestion,
    SurveyQuestionType,
} from '@/components/campaigns/survey';
import InputError from '@/components/InputError.vue';
import SelectField from '@/components/SelectField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { index as campaignsIndex } from '@/routes/campaigns';
import type { Team } from '@/types';

/**
 * Survey builder of a campaign: the questions the agent asks during the
 * call, with conditional questions and a live preview of what the agent
 * will see.
 */
const props = defineProps<{
    campaign: { id: number; name: string; status: string };
    questions: SurveyQuestion[];
    responses: number;
    types: { value: SurveyQuestionType; label: string }[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Campagnes',
                href: props.currentTeam
                    ? campaignsIndex(props.currentTeam.slug)
                    : '/',
            },
            { title: 'Questionnaire', href: '#' },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const base = computed(() => `/${slug.value}/campaigns/${props.campaign.id}`);

const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value));

const form = useForm<{ questions: SurveyQuestion[] }>({
    questions: clone(props.questions).map((q) => ({
        ...q,
        settings: { ...q.settings },
        options: q.options ?? [],
    })),
});

const errors = computed(() => form.errors as Record<string, string>);

function blank(type: SurveyQuestionType): SurveyQuestion {
    return {
        id: null,
        key: newKey(),
        type,
        label: '',
        help: null,
        required: false,
        options:
            type === 'single' || type === 'multiple'
                ? [
                      { value: optionValue(), label: '' },
                      { value: optionValue(), label: '' },
                  ]
                : [],
        settings: type === 'rating' ? { min: 1, max: 5 } : {},
        condition: null,
    };
}

function add(type: SurveyQuestionType) {
    form.questions.push(blank(type));
}

function move(index: number, delta: number) {
    const target = index + delta;

    if (target < 0 || target >= form.questions.length) {
        return;
    }

    const list = [...form.questions];
    [list[index], list[target]] = [list[target], list[index]];
    form.questions = list;
    dropBrokenConditions();
}

function duplicate(index: number) {
    const copy = clone(form.questions[index]);
    copy.id = null;
    copy.key = newKey();
    copy.options = copy.options.map((o) => ({ ...o, value: optionValue() }));
    copy.condition = copy.condition ? { ...copy.condition } : null;
    form.questions.splice(index + 1, 0, copy);
}

function remove(index: number) {
    form.questions.splice(index, 1);
    dropBrokenConditions();
}

/** A condition must point at an earlier choice question. */
function dropBrokenConditions() {
    form.questions.forEach((q, i) => {
        if (
            q.condition &&
            !form.questions
                .slice(0, i)
                .some(
                    (p) =>
                        p.key === q.condition?.key &&
                        ['single', 'multiple', 'yes_no'].includes(p.type),
                )
        ) {
            q.condition = null;
        }
    });
}

function changeType(q: SurveyQuestion, type: SurveyQuestionType) {
    q.type = type;

    if ((type === 'single' || type === 'multiple') && q.options.length < 2) {
        q.options = [
            { value: optionValue(), label: '' },
            { value: optionValue(), label: '' },
        ];
    }

    if (type === 'rating') {
        q.settings = { min: q.settings.min ?? 1, max: q.settings.max ?? 5 };
    }

    dropBrokenConditions();
}

/** Earlier questions with fixed answers: what a condition can depend on. */
function conditionSources(index: number) {
    return form.questions
        .slice(0, index)
        .filter((q) => ['single', 'multiple', 'yes_no'].includes(q.type));
}

function toggleCondition(q: SurveyQuestion, index: number, on: boolean) {
    const source = conditionSources(index).at(-1);
    q.condition = on && source ? { key: source.key, values: [] } : null;
}

function conditionSource(q: SurveyQuestion) {
    return form.questions.find((p) => p.key === q.condition?.key) ?? null;
}

/** Every choice has an identifier (older drafts may lack one). */
function ensureValues(q: SurveyQuestion) {
    q.options.forEach((o) => {
        if (!o.value) {
            o.value = optionValue();
        }
    });
}

function toggleConditionValue(q: SurveyQuestion, value: string, on: boolean) {
    if (!q.condition) {
        return;
    }

    q.condition.values = on
        ? [...new Set([...q.condition.values, value])]
        : q.condition.values.filter((v) => v !== value);
}

function save() {
    form.questions.forEach(ensureValues);
    form.put(`${base.value}/survey`, { preserveScroll: true });
}

/** Ready-made start: customer satisfaction after a service. */
function useTemplate() {
    const satisfied = blank('rating');
    satisfied.label = 'Sur 5, êtes-vous satisfait de notre service ?';
    satisfied.required = true;
    satisfied.settings = {
        min: 1,
        max: 5,
        min_label: 'Pas du tout',
        max_label: 'Très satisfait',
    };

    const why = blank('long_text');
    why.label = 'Qu’est-ce qui vous a déçu ?';

    const recommend = blank('nps');
    recommend.label =
        'Recommanderiez-vous notre entreprise à un proche ? (0 à 10)';
    recommend.required = true;

    const callback = blank('yes_no');
    callback.label = 'Souhaitez-vous être rappelé par un conseiller ?';

    form.questions = [satisfied, why, recommend, callback];
}

/* Preview */
const previewAnswers = ref<SurveyAnswers>({});
const previewQuestions = computed(() =>
    form.questions.map((q) => ({ ...q, options: choicesOf(q) })),
);
</script>

<template>
    <Head :title="t('Questionnaire')" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <Link
                    :href="base"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    ><ArrowLeft class="size-4" /> {{ campaign.name }}</Link
                >
                <h1 class="mt-1 text-2xl font-semibold text-[var(--heading)]">
                    {{ t('Questionnaire') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            "Les questions que l'agent pose pendant l'appel. Les réponses sont enregistrées pour chaque contact.",
                        )
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="responses" as-child variant="outline">
                    <Link :href="`${base}/survey/results`"
                        ><BarChart3 />
                        {{
                            t('Résultats (:count)', { count: responses })
                        }}</Link
                    >
                </Button>
                <Button
                    :disabled="form.processing || !form.isDirty"
                    @click="save"
                    >{{ t('Enregistrer') }}</Button
                >
            </div>
        </header>

        <p
            v-if="responses"
            class="rounded-md bg-notice-subtle p-3 text-sm text-notice-strong"
        >
            {{
                t(
                    ':count réponse(s) déjà enregistrée(s). Modifier un libellé est sans risque ; supprimer une question ou un choix retire ses réponses des résultats.',
                    { count: responses },
                )
            }}
        </p>
        <InputError :message="errors.questions" />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <!-- Editor -->
            <div class="space-y-4">
                <div
                    v-if="form.questions.length === 0"
                    class="rounded-lg border border-dashed p-8 text-center"
                >
                    <p class="font-medium">
                        {{ t('Aucune question pour le moment') }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            t(
                                'Ajoutez une question ci-dessous, ou partez d’un modèle.',
                            )
                        }}
                    </p>
                    <Button class="mt-4" variant="outline" @click="useTemplate"
                        ><Sparkles />
                        {{ t('Modèle : satisfaction client') }}</Button
                    >
                </div>

                <section
                    v-for="(q, index) in form.questions"
                    :key="q.key"
                    class="space-y-4 rounded-lg border p-4"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{{
                            t('Question :n', { n: index + 1 })
                        }}</Badge>
                        <div class="w-56">
                            <SelectField
                                :model-value="q.type"
                                :options="types"
                                :aria-label="t('Type de question')"
                                @update:model-value="
                                    (v) =>
                                        changeType(q, v as SurveyQuestionType)
                                "
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox v-model="q.required" />
                            {{ t('Obligatoire') }}
                        </label>
                        <div class="ml-auto flex gap-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                :disabled="index === 0"
                                :aria-label="t('Monter')"
                                @click="move(index, -1)"
                                ><ArrowUp
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :disabled="index === form.questions.length - 1"
                                :aria-label="t('Descendre')"
                                @click="move(index, 1)"
                                ><ArrowDown
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="t('Dupliquer')"
                                @click="duplicate(index)"
                                ><Copy
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="t('Supprimer la question')"
                                @click="remove(index)"
                                ><Trash2
                            /></Button>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`label-${q.key}`">{{
                            t('Question posée')
                        }}</Label>
                        <Input
                            :id="`label-${q.key}`"
                            v-model="q.label"
                            maxlength="300"
                            :placeholder="
                                t(
                                    'ex. Êtes-vous satisfait de votre livraison ?',
                                )
                            "
                        />
                        <InputError
                            :message="errors[`questions.${index}.label`]"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`help-${q.key}`">{{
                            t("Consigne pour l'agent (facultatif)")
                        }}</Label>
                        <Input
                            :id="`help-${q.key}`"
                            :model-value="q.help ?? ''"
                            maxlength="500"
                            :placeholder="t('ex. Lire les choix au client')"
                            @update:model-value="
                                (v) => (q.help = v ? String(v) : null)
                            "
                        />
                    </div>

                    <!-- Choices -->
                    <div
                        v-if="q.type === 'single' || q.type === 'multiple'"
                        class="space-y-2"
                    >
                        <Label>{{ t('Réponses proposées') }}</Label>
                        <div
                            v-for="(option, o) in q.options"
                            :key="o"
                            class="flex gap-2"
                        >
                            <Input
                                v-model="option.label"
                                maxlength="120"
                                :placeholder="t('Réponse :n', { n: o + 1 })"
                            />
                            <Button
                                variant="ghost"
                                size="icon"
                                :disabled="q.options.length <= 2"
                                :aria-label="t('Retirer cette réponse')"
                                @click="q.options.splice(o, 1)"
                                ><X
                            /></Button>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="q.options.length >= 20"
                            @click="
                                q.options.push({
                                    value: optionValue(),
                                    label: '',
                                })
                            "
                            ><Plus /> {{ t('Ajouter une réponse') }}</Button
                        >
                        <InputError
                            :message="errors[`questions.${index}.options`]"
                        />
                    </div>

                    <!-- Rating scale -->
                    <div
                        v-else-if="q.type === 'rating'"
                        class="grid gap-3 sm:grid-cols-4"
                    >
                        <div class="grid gap-2">
                            <Label>{{ t('De') }}</Label>
                            <SelectField
                                :model-value="q.settings.min ?? 1"
                                :options="[
                                    { value: 0, label: '0' },
                                    { value: 1, label: '1' },
                                ]"
                                @update:model-value="
                                    (v) => (q.settings.min = Number(v))
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('À') }}</Label>
                            <SelectField
                                :model-value="q.settings.max ?? 5"
                                :options="
                                    [2, 3, 4, 5, 6, 7, 8, 9, 10].map((n) => ({
                                        value: n,
                                        label: String(n),
                                    }))
                                "
                                @update:model-value="
                                    (v) => (q.settings.max = Number(v))
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Libellé du minimum') }}</Label>
                            <Input
                                :model-value="q.settings.min_label ?? ''"
                                maxlength="60"
                                :placeholder="t('Pas du tout')"
                                @update:model-value="
                                    (v) =>
                                        (q.settings.min_label = v
                                            ? String(v)
                                            : null)
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Libellé du maximum') }}</Label>
                            <Input
                                :model-value="q.settings.max_label ?? ''"
                                maxlength="60"
                                :placeholder="t('Tout à fait')"
                                @update:model-value="
                                    (v) =>
                                        (q.settings.max_label = v
                                            ? String(v)
                                            : null)
                                "
                            />
                        </div>
                        <InputError
                            class="sm:col-span-4"
                            :message="errors[`questions.${index}.settings`]"
                        />
                    </div>

                    <!-- Number bounds -->
                    <div
                        v-else-if="q.type === 'number'"
                        class="grid max-w-md gap-3 sm:grid-cols-2"
                    >
                        <div class="grid gap-2">
                            <Label>{{ t('Minimum (facultatif)') }}</Label>
                            <Input
                                type="number"
                                :model-value="q.settings.min ?? ''"
                                @update:model-value="
                                    (v) =>
                                        (q.settings.min =
                                            v === '' ? null : Number(v))
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Maximum (facultatif)') }}</Label>
                            <Input
                                type="number"
                                :model-value="q.settings.max ?? ''"
                                @update:model-value="
                                    (v) =>
                                        (q.settings.max =
                                            v === '' ? null : Number(v))
                                "
                            />
                        </div>
                        <InputError
                            class="sm:col-span-2"
                            :message="errors[`questions.${index}.settings`]"
                        />
                    </div>

                    <!-- Condition -->
                    <div
                        v-if="conditionSources(index).length"
                        class="space-y-3 rounded-md bg-muted/50 p-3"
                    >
                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox
                                :model-value="!!q.condition"
                                @update:model-value="
                                    (v) => toggleCondition(q, index, v === true)
                                "
                            />
                            <GitBranch class="size-4 text-muted-foreground" />
                            {{
                                t(
                                    'Poser cette question seulement selon une réponse précédente',
                                )
                            }}
                        </label>
                        <template v-if="q.condition">
                            <SelectField
                                :model-value="q.condition.key"
                                :options="
                                    conditionSources(index).map((p) => ({
                                        value: p.key,
                                        label: `${form.questions.indexOf(p) + 1}. ${p.label || t('Question sans titre')}`,
                                    }))
                                "
                                @update:model-value="
                                    (v) =>
                                        (q.condition = {
                                            key: String(v),
                                            values: [],
                                        })
                                "
                            />
                            <div class="flex flex-wrap gap-3">
                                <span class="text-sm text-muted-foreground">{{
                                    t('Si la réponse est :')
                                }}</span>
                                <label
                                    v-for="c in choicesOf(
                                        conditionSource(q) ?? q,
                                    )"
                                    :key="c.value || c.label"
                                    class="flex items-center gap-1.5 text-sm"
                                >
                                    <Checkbox
                                        :model-value="
                                            q.condition.values.includes(c.value)
                                        "
                                        @update:model-value="
                                            (v) => {
                                                ensureValues(
                                                    conditionSource(q) ?? q,
                                                );
                                                toggleConditionValue(
                                                    q,
                                                    c.value,
                                                    v === true,
                                                );
                                            }
                                        "
                                    />
                                    {{
                                        conditionSource(q)?.type === 'yes_no'
                                            ? t(c.label)
                                            : c.label
                                    }}
                                </label>
                            </div>
                            <InputError
                                :message="
                                    errors[`questions.${index}.condition`]
                                "
                            />
                        </template>
                    </div>
                </section>

                <div class="rounded-lg border border-dashed p-4">
                    <p class="mb-3 text-sm font-medium">
                        {{ t('Ajouter une question') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="type in types"
                            :key="type.value"
                            variant="outline"
                            size="sm"
                            :disabled="form.questions.length >= 100"
                            @click="add(type.value)"
                            ><Plus /> {{ type.label }}</Button
                        >
                    </div>
                </div>
            </div>

            <!-- Preview -->
            <aside class="xl:sticky xl:top-4 xl:self-start">
                <div class="rounded-lg border p-4">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-semibold">{{ t('Aperçu agent') }}</h2>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="previewAnswers = {}"
                            >{{ t('Effacer') }}</Button
                        >
                    </div>
                    <SurveyForm
                        v-if="previewQuestions.length"
                        v-model="previewAnswers"
                        :questions="previewQuestions"
                        key-by="key"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            t(
                                "L'aperçu montre le questionnaire tel que l'agent le verra.",
                            )
                        }}
                    </p>
                    <p class="mt-4 border-t pt-3 text-xs text-muted-foreground">
                        {{
                            t(
                                'Les questions conditionnelles apparaissent dès que la réponse attendue est choisie.',
                            )
                        }}
                    </p>
                </div>
            </aside>
        </div>
    </div>
</template>
