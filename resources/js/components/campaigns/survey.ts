/** Campaign questionnaire (App\Models\SurveyQuestion::toBuilder). */
export type SurveyQuestionType =
    | 'single'
    | 'multiple'
    | 'yes_no'
    | 'rating'
    | 'nps'
    | 'number'
    | 'text'
    | 'long_text'
    | 'date';

export type SurveyChoice = { value: string; label: string };

export type SurveyQuestion = {
    id?: number | null;
    /** Builder key: conditions point at questions by key. */
    key: string;
    type: SurveyQuestionType;
    label: string;
    help: string | null;
    required: boolean;
    options: SurveyChoice[];
    settings: {
        min?: number | null;
        max?: number | null;
        min_label?: string | null;
        max_label?: string | null;
    };
    condition: { key: string; values: string[] } | null;
    /** Same condition by question id (saved questions). */
    showIf?: { questionId: number; values: string[] } | null;
    choices?: SurveyChoice[];
};

export type SurveyAnswer = string | number | string[] | null;

export type SurveyAnswers = Record<string, SurveyAnswer>;

/** Fixed answers of a question (yes/no, or its options). */
export function choicesOf(question: SurveyQuestion): SurveyChoice[] {
    if (question.type === 'yes_no') {
        return [
            { value: 'yes', label: 'Oui' },
            { value: 'no', label: 'Non' },
        ];
    }

    return question.type === 'single' || question.type === 'multiple'
        ? question.options.filter((o) => o.label.trim() !== '')
        : [];
}

export function scaleOf(question: SurveyQuestion): number[] {
    const min =
        question.type === 'nps' ? 0 : Number(question.settings.min ?? 1);
    const max =
        question.type === 'nps' ? 10 : Number(question.settings.max ?? 5);

    return max > min
        ? Array.from({ length: max - min + 1 }, (_, i) => min + i)
        : [];
}

/**
 * Shown when it has no condition or the earlier answer matches. `answerKey`
 * maps a question to the key its answer is stored under (id in the agent
 * desktop, builder key in the preview).
 */
export function isShown(
    question: SurveyQuestion,
    questions: SurveyQuestion[],
    answers: SurveyAnswers,
    answerKey: (q: SurveyQuestion) => string,
): boolean {
    const condition = question.condition;

    if (!condition) {
        return true;
    }

    const target = questions.find((q) => q.key === condition.key);

    if (!target || !isShown(target, questions, answers, answerKey)) {
        return false;
    }

    const given = answers[answerKey(target)];
    const values = Array.isArray(given)
        ? given
        : given === null || given === undefined || given === ''
          ? []
          : [String(given)];

    return values.some((v) => condition.values.includes(String(v)));
}

/** Identifier of a new choice (6 lowercase letters/digits, kept by the server). */
export function optionValue(): string {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';

    return Array.from(
        { length: 6 },
        () => chars[Math.floor(Math.random() * chars.length)],
    ).join('');
}

/** A fresh client key for a new question. */
export function newKey(): string {
    return `n${Math.random().toString(36).slice(2, 10)}`;
}
