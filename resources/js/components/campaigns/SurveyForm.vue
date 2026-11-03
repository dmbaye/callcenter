<script setup lang="ts">
import { computed } from 'vue';
import { choicesOf, isShown, scaleOf } from '@/components/campaigns/survey';
import type {
    SurveyAnswer,
    SurveyAnswers,
    SurveyQuestion,
} from '@/components/campaigns/survey';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';

/**
 * Answers a questionnaire, one question after the other. Conditional
 * questions appear as soon as the earlier answer matches. Used by the agent
 * desktop (answers keyed by question id) and the builder preview (by key).
 */
const props = withDefaults(
    defineProps<{
        questions: SurveyQuestion[];
        errors?: Record<string, string>;
        disabled?: boolean;
        /** 'id' in the agent desktop, 'key' in the builder preview. */
        keyBy?: 'id' | 'key';
    }>(),
    { errors: () => ({}), disabled: false, keyBy: 'id' },
);

const answers = defineModel<SurveyAnswers>({ required: true });

const keyOf = (q: SurveyQuestion) =>
    props.keyBy === 'id' ? String(q.id ?? q.key) : q.key;

const visible = computed(() =>
    props.questions.filter((q) =>
        isShown(q, props.questions, answers.value, keyOf),
    ),
);

function set(q: SurveyQuestion, value: SurveyAnswer) {
    answers.value = { ...answers.value, [keyOf(q)]: value };
}

function toggle(q: SurveyQuestion, value: string, checked: boolean) {
    const current = answers.value[keyOf(q)];
    const list = Array.isArray(current) ? [...current] : [];
    const next = checked
        ? [...new Set([...list, value])]
        : list.filter((v) => v !== value);

    set(q, next.length ? next : null);
}

const choiceClass = (selected: boolean) =>
    selected
        ? 'border-primary bg-primary/10 font-medium text-primary'
        : 'hover:bg-accent';
</script>

<template>
    <ol class="space-y-5">
        <li v-for="(q, index) in visible" :key="q.key" class="space-y-2">
            <div class="text-sm font-medium">
                <span class="text-muted-foreground">{{ index + 1 }}.</span>
                {{ q.label || t('Question sans titre') }}
                <span v-if="q.required" class="text-negative" aria-hidden="true"
                    >*</span
                >
            </div>
            <p v-if="q.help" class="text-xs text-muted-foreground">
                {{ q.help }}
            </p>

            <!-- One choice -->
            <div
                v-if="q.type === 'single' || q.type === 'yes_no'"
                class="flex flex-wrap gap-2"
                role="radiogroup"
                :aria-label="q.label"
            >
                <button
                    v-for="c in choicesOf(q)"
                    :key="c.value"
                    type="button"
                    role="radio"
                    :aria-checked="answers[keyOf(q)] === c.value"
                    :disabled="disabled"
                    class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                    :class="choiceClass(answers[keyOf(q)] === c.value)"
                    @click="
                        set(q, answers[keyOf(q)] === c.value ? null : c.value)
                    "
                >
                    {{ q.type === 'yes_no' ? t(c.label) : c.label }}
                </button>
            </div>

            <!-- Several choices -->
            <div v-else-if="q.type === 'multiple'" class="grid gap-2">
                <label
                    v-for="c in choicesOf(q)"
                    :key="c.value"
                    class="flex items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="
                            Array.isArray(answers[keyOf(q)]) &&
                            (answers[keyOf(q)] as string[]).includes(c.value)
                        "
                        :disabled="disabled"
                        @update:model-value="
                            (v) => toggle(q, c.value, v === true)
                        "
                    />
                    {{ c.label }}
                </label>
            </div>

            <!-- Rating / NPS -->
            <div v-else-if="q.type === 'rating' || q.type === 'nps'">
                <div class="flex flex-wrap gap-1.5" role="radiogroup">
                    <button
                        v-for="n in scaleOf(q)"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="answers[keyOf(q)] === n"
                        :disabled="disabled"
                        class="size-9 rounded-md border text-sm tabular-nums transition-colors"
                        :class="choiceClass(answers[keyOf(q)] === n)"
                        @click="set(q, answers[keyOf(q)] === n ? null : n)"
                    >
                        {{ n }}
                    </button>
                </div>
                <div
                    v-if="
                        q.type === 'nps' ||
                        q.settings.min_label ||
                        q.settings.max_label
                    "
                    class="mt-1 flex justify-between text-xs text-muted-foreground"
                    :style="{ maxWidth: `${scaleOf(q).length * 2.625}rem` }"
                >
                    <span>{{
                        q.type === 'nps'
                            ? t('Pas du tout probable')
                            : q.settings.min_label
                    }}</span>
                    <span>{{
                        q.type === 'nps'
                            ? t('Très probable')
                            : q.settings.max_label
                    }}</span>
                </div>
            </div>

            <Input
                v-else-if="q.type === 'number'"
                type="number"
                class="max-w-48"
                :min="q.settings.min ?? undefined"
                :max="q.settings.max ?? undefined"
                :model-value="(answers[keyOf(q)] as number | null) ?? ''"
                :disabled="disabled"
                @update:model-value="
                    (v) => set(q, v === '' || v === null ? null : Number(v))
                "
            />

            <Input
                v-else-if="q.type === 'date'"
                type="date"
                class="max-w-48"
                :model-value="(answers[keyOf(q)] as string | null) ?? ''"
                :disabled="disabled"
                @update:model-value="(v) => set(q, v ? String(v) : null)"
            />

            <Textarea
                v-else-if="q.type === 'long_text'"
                rows="3"
                :model-value="(answers[keyOf(q)] as string | null) ?? ''"
                :disabled="disabled"
                @update:model-value="(v) => set(q, v ? String(v) : null)"
            />

            <Input
                v-else
                :model-value="(answers[keyOf(q)] as string | null) ?? ''"
                :disabled="disabled"
                maxlength="500"
                @update:model-value="(v) => set(q, v ? String(v) : null)"
            />

            <InputError :message="errors[`answers.${keyOf(q)}`]" />
        </li>
    </ol>
</template>
