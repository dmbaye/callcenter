<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignAttempt;
use App\Models\CampaignContact;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Campaign questionnaires: the builder saves the questions, the agent
 * desktop records one response per contact (a later call continues it),
 * and the results page aggregates them per question.
 *
 * Questions can depend on an earlier choice question ("show only if the
 * answer to Q2 is Oui"): hidden questions are never required and their
 * answers are dropped.
 */
class CampaignSurvey
{
    public const MAX_QUESTIONS = 100;

    public const MAX_OPTIONS = 20;

    /**
     * Replace the questionnaire with the builder's list. Questions keep their
     * id (and their answers) when sent back with it; choices keep their value.
     * Conditions point at another question by its builder `key`.
     *
     * @param  list<array<string, mixed>>  $input
     * @return Collection<int, SurveyQuestion>
     */
    public function saveQuestions(Campaign $campaign, array $input): Collection
    {
        $input = array_values($input);

        if (count($input) > self::MAX_QUESTIONS) {
            throw ValidationException::withMessages(['questions' => __('A questionnaire has at most :max questions.', ['max' => self::MAX_QUESTIONS])]);
        }

        $existing = $campaign->surveyQuestions()->get()->keyBy('id');
        $errors = [];
        $clean = [];

        foreach ($input as $index => $raw) {
            $prefix = "questions.{$index}";
            $type = (string) ($raw['type'] ?? '');
            $label = trim((string) ($raw['label'] ?? ''));

            if (! in_array($type, SurveyQuestion::TYPES, true)) {
                $errors["{$prefix}.type"] = __('Choose a question type.');

                continue;
            }

            if ($label === '' || mb_strlen($label) > 300) {
                $errors["{$prefix}.label"] = __('Write the question (300 characters at most).');
            }

            $id = isset($raw['id']) && $existing->has((int) $raw['id']) ? (int) $raw['id'] : null;
            $previous = $id !== null ? $existing->get($id) : null;

            $clean[$index] = [
                'id' => $id,
                'key' => (string) ($raw['key'] ?? ($id !== null ? "q{$id}" : "new{$index}")),
                'type' => $type,
                'label' => $label,
                'help' => ($help = trim((string) ($raw['help'] ?? ''))) === '' ? null : mb_substr($help, 0, 500),
                'required' => (bool) ($raw['required'] ?? false),
                'options' => null,
                'settings' => null,
                'condition' => $raw['condition'] ?? null,
            ];

            if (in_array($type, ['single', 'multiple'], true)) {
                $clean[$index]['options'] = $this->cleanOptions($raw['options'] ?? [], $previous, "{$prefix}.options", $errors);
            }

            if ($type === 'rating') {
                $min = (int) ($raw['settings']['min'] ?? 1);
                $max = (int) ($raw['settings']['max'] ?? 5);

                if (! in_array($min, [0, 1], true) || $max < 2 || $max > 10 || $max <= $min) {
                    $errors["{$prefix}.settings"] = __('A rating goes from 0 or 1 up to 2–10.');
                }

                $clean[$index]['settings'] = array_filter([
                    'min' => $min,
                    'max' => $max,
                    'min_label' => mb_substr(trim((string) ($raw['settings']['min_label'] ?? '')), 0, 60) ?: null,
                    'max_label' => mb_substr(trim((string) ($raw['settings']['max_label'] ?? '')), 0, 60) ?: null,
                ], fn ($value) => $value !== null);
            }

            if ($type === 'number') {
                $min = $raw['settings']['min'] ?? null;
                $max = $raw['settings']['max'] ?? null;
                $min = is_numeric($min) ? (float) $min : null;
                $max = is_numeric($max) ? (float) $max : null;

                if ($min !== null && $max !== null && $min > $max) {
                    $errors["{$prefix}.settings"] = __('The minimum must be below the maximum.');
                }

                $clean[$index]['settings'] = array_filter(['min' => $min, 'max' => $max], fn ($value) => $value !== null);
            }
        }

        // Conditions: an earlier choice question, and some of its answers.
        $byKey = [];

        foreach ($clean as $index => $question) {
            $condition = $question['condition'];
            $clean[$index]['condition'] = null;

            if (is_array($condition) && ($condition['key'] ?? '') !== '') {
                $target = $byKey[(string) $condition['key']] ?? null;
                $values = array_values(array_unique(array_map('strval', (array) ($condition['values'] ?? []))));
                $allowed = $target ? array_column($this->choicesOf($target), 'value') : [];

                if ($target === null || ! in_array($target['type'], SurveyQuestion::CHOICE_TYPES, true)) {
                    $errors["questions.{$index}.condition"] = __('A condition depends on an earlier choice question.');
                } elseif ($values === [] || array_diff($values, $allowed) !== []) {
                    $errors["questions.{$index}.condition"] = __('Pick the answers that show this question.');
                } else {
                    $clean[$index]['condition'] = ['key' => $target['key'], 'values' => $values];
                }
            }

            $byKey[$question['key']] = $clean[$index];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($campaign, $clean, $existing): Collection {
            $ids = [];
            $keyToId = [];

            foreach (array_values($clean) as $position => $question) {
                $attributes = [
                    'position' => $position,
                    'type' => $question['type'],
                    'label' => $question['label'],
                    'help' => $question['help'],
                    'required' => $question['required'],
                    'options' => $question['options'],
                    'settings' => $question['settings'] ?: null,
                    'condition' => null,
                ];

                $model = $question['id'] !== null
                    ? tap($existing->get($question['id']))->update($attributes)
                    : $campaign->surveyQuestions()->create($attributes);

                $ids[] = $model->id;
                $keyToId[$question['key']] = $model->id;
            }

            // Second pass: conditions now point at real ids.
            foreach (array_values($clean) as $position => $question) {
                if ($question['condition'] !== null) {
                    SurveyQuestion::query()->whereKey($ids[$position])->update(['condition' => json_encode([
                        'question_id' => $keyToId[$question['condition']['key']],
                        'values' => $question['condition']['values'],
                    ])]);
                }
            }

            $campaign->surveyQuestions()->whereNotIn('id', $ids)->delete();

            return $campaign->surveyQuestions()->get();
        });
    }

    /**
     * Record (or continue) the contact's response. `complete` checks the
     * required questions that are shown; saving a draft does not.
     *
     * @param  array<string|int, mixed>  $answers  question id => answer
     */
    public function answer(
        Campaign $campaign,
        CampaignContact $contact,
        ?CampaignAttempt $attempt,
        User $agent,
        array $answers,
        bool $complete,
    ): SurveyResponse {
        $questions = $campaign->surveyQuestions()->get();
        [$clean, $errors] = $this->checkAnswers($questions, $answers, $complete);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $response = SurveyResponse::query()->firstOrNew([
            'campaign_id' => $campaign->id,
            'campaign_contact_id' => $contact->id,
        ]);

        $response->fill([
            'campaign_attempt_id' => $attempt?->id ?? $response->campaign_attempt_id,
            'user_id' => $agent->id,
            'answers' => $clean,
        ]);

        if ($complete) {
            $response->completed_at ??= now();
        }

        $response->save();

        return $response;
    }

    /**
     * @param  Collection<int, SurveyQuestion>  $questions
     * @param  array<string|int, mixed>  $answers
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function checkAnswers(Collection $questions, array $answers, bool $complete): array
    {
        $clean = [];
        $errors = [];

        foreach ($questions as $question) {
            $key = (string) $question->id;

            if (! $this->isShown($question, $clean)) {
                continue;
            }

            $value = $this->normalize($question, $answers[$key] ?? $answers[$question->id] ?? null);

            if ($value === false) {
                $errors["answers.{$key}"] = __('This answer is not valid.');

                continue;
            }

            if ($value === null) {
                if ($complete && $question->required) {
                    $errors["answers.{$key}"] = __('This question needs an answer.');
                }

                continue;
            }

            $clean[$key] = $value;
        }

        return [$clean, $errors];
    }

    /**
     * Shown when it has no condition, or the earlier answer matches.
     *
     * @param  array<string, mixed>  $answers
     */
    public function isShown(SurveyQuestion $question, array $answers): bool
    {
        $condition = $question->condition;

        if (! is_array($condition) || ! isset($condition['question_id'])) {
            return true;
        }

        $given = $answers[(string) $condition['question_id']] ?? null;
        $given = is_array($given) ? $given : ($given === null ? [] : [$given]);

        return array_intersect(array_map('strval', $given), (array) $condition['values']) !== [];
    }

    /**
     * Per-question figures over every response (complete or not).
     *
     * @return array<string, mixed>
     */
    public function results(Campaign $campaign): array
    {
        $questions = $campaign->surveyQuestions()->get();
        $total = $campaign->surveyResponses()->count();
        $completed = $campaign->surveyResponses()->whereNotNull('completed_at')->count();

        $stats = [];

        foreach ($questions as $question) {
            $stats[$question->id] = ['answered' => 0, 'counts' => [], 'values' => [], 'texts' => []];
        }

        $campaign->surveyResponses()
            ->with('contact:id,phone,first_name,last_name')
            ->orderByDesc('updated_at')
            ->chunk(500, function (Collection $responses) use ($questions, &$stats): void {
                foreach ($responses as $response) {
                    foreach ($questions as $question) {
                        $value = $response->answers[(string) $question->id] ?? null;

                        if ($value === null || $value === '' || $value === []) {
                            continue;
                        }

                        $id = $question->id;
                        $stats[$id]['answered']++;

                        if ($question->type === 'multiple') {
                            foreach ((array) $value as $choice) {
                                $stats[$id]['counts'][(string) $choice] = ($stats[$id]['counts'][(string) $choice] ?? 0) + 1;
                            }
                        } elseif (in_array($question->type, ['single', 'yes_no', 'rating', 'nps'], true)) {
                            $stats[$id]['counts'][(string) $value] = ($stats[$id]['counts'][(string) $value] ?? 0) + 1;
                        } elseif ($question->type === 'number') {
                            $stats[$id]['values'][] = (float) $value;
                        } elseif (count($stats[$id]['texts']) < 30) {
                            // Most recent first (responses are read newest first).
                            $stats[$id]['texts'][] = [
                                'value' => (string) $value,
                                'contact' => $response->contact?->fullName() ?: $response->contact?->phone,
                                'at' => $response->updated_at?->toIso8601String(),
                            ];
                        }
                    }
                }
            });

        return [
            'responses' => $total,
            'completed' => $completed,
            'questions' => $questions->map(fn (SurveyQuestion $question) => [
                ...$question->toBuilder(),
                ...$this->summarize($question, $stats[$question->id]),
            ])->values()->all(),
        ];
    }

    /**
     * Rows for the CSV export: one per response, one column per question.
     *
     * @return \Generator<int, list<string>>
     */
    public function exportRows(Campaign $campaign): \Generator
    {
        $questions = $campaign->surveyQuestions()->get();

        yield [__('Téléphone'), __('Contact'), __('Agent'), __('Statut'), __('Date'), ...$questions->pluck('label')->all()];

        foreach ($campaign->surveyResponses()->with(['contact', 'agent:id,name'])->orderBy('id')->lazy(500) as $response) {
            yield [
                (string) $response->contact?->phone,
                (string) ($response->contact?->fullName() ?: $response->contact?->company),
                (string) $response->agent?->name,
                $response->completed_at ? __('Terminé') : __('En cours'),
                ($response->completed_at ?? $response->updated_at)?->format('Y-m-d H:i') ?? '',
                ...$questions->map(fn (SurveyQuestion $q) => $this->display($q, $response->answers[(string) $q->id] ?? null))->all(),
            ];
        }
    }

    /** An answer as text (choice labels instead of their values). */
    public function display(SurveyQuestion $question, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        $labels = array_column($question->choices(), 'label', 'value');

        return match ($question->type) {
            'single', 'yes_no' => (string) ($labels[(string) $value] ?? $value),
            'multiple' => implode(' | ', array_map(fn ($v) => (string) ($labels[(string) $v] ?? $v), (array) $value)),
            default => (string) $value,
        };
    }

    /**
     * @param  array{answered: int, counts: array<string, int>, values: list<float>, texts: list<array<string, mixed>>}  $stat
     * @return array<string, mixed>
     */
    private function summarize(SurveyQuestion $question, array $stat): array
    {
        $answered = $stat['answered'];
        $result = ['answered' => $answered];

        if (in_array($question->type, SurveyQuestion::CHOICE_TYPES, true)) {
            $result['breakdown'] = array_map(fn (array $choice) => [
                'label' => $choice['label'],
                'count' => $count = (int) ($stat['counts'][$choice['value']] ?? 0),
                'percent' => $answered > 0 ? round($count * 100 / $answered, 1) : 0,
            ], $question->choices());
        }

        if (in_array($question->type, ['rating', 'nps'], true)) {
            ['min' => $min, 'max' => $max] = $question->scale();
            $sum = 0;
            $breakdown = [];

            foreach (range($min, $max) as $score) {
                $count = (int) ($stat['counts'][(string) $score] ?? 0);
                $sum += $score * $count;
                $breakdown[] = ['label' => (string) $score, 'count' => $count, 'percent' => $answered > 0 ? round($count * 100 / $answered, 1) : 0];
            }

            $result['breakdown'] = $breakdown;
            $result['average'] = $answered > 0 ? round($sum / $answered, 2) : null;

            if ($question->type === 'nps') {
                $promoters = array_sum(array_map(fn ($s) => (int) ($stat['counts'][(string) $s] ?? 0), [9, 10]));
                $detractors = array_sum(array_map(fn ($s) => (int) ($stat['counts'][(string) $s] ?? 0), range(0, 6)));
                $result['nps'] = $answered > 0 ? (int) round(($promoters - $detractors) * 100 / $answered) : null;
                $result['promoters'] = $promoters;
                $result['passives'] = $answered - $promoters - $detractors;
                $result['detractors'] = $detractors;
            }
        }

        if ($question->type === 'number') {
            $values = $stat['values'];
            $result['average'] = $values ? round(array_sum($values) / count($values), 2) : null;
            $result['min'] = $values ? min($values) : null;
            $result['max'] = $values ? max($values) : null;
        }

        if (in_array($question->type, ['text', 'long_text', 'date'], true)) {
            $result['texts'] = $stat['texts'];
        }

        return $result;
    }

    /**
     * Normalized answer, null when empty, false when invalid.
     */
    private function normalize(SurveyQuestion $question, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $choices = array_column($question->choices(), 'value');

        switch ($question->type) {
            case 'single':
            case 'yes_no':
                return is_scalar($value) && in_array((string) $value, $choices, true) ? (string) $value : false;

            case 'multiple':
                $values = array_values(array_unique(array_map('strval', (array) $value)));

                return array_diff($values, $choices) === [] ? $values : false;

            case 'rating':
            case 'nps':
                ['min' => $min, 'max' => $max] = $question->scale();

                return is_numeric($value) && (int) $value == $value && $value >= $min && $value <= $max ? (int) $value : false;

            case 'number':
                if (! is_numeric($value)) {
                    return false;
                }
                $number = $value + 0;
                $min = $question->settings['min'] ?? null;
                $max = $question->settings['max'] ?? null;

                return ($min !== null && $number < $min) || ($max !== null && $number > $max) ? false : $number;

            case 'date':
                return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : false;

            case 'text':
                return is_scalar($value) ? (mb_substr(trim((string) $value), 0, 500) ?: null) : false;

            case 'long_text':
                return is_scalar($value) ? (mb_substr(trim((string) $value), 0, 5000) ?: null) : false;
        }

        return false;
    }

    /**
     * @param  mixed  $options
     * @param  array<string, string>  $errors
     * @return list<array{value: string, label: string}>
     */
    private function cleanOptions(mixed $options, ?SurveyQuestion $previous, string $field, array &$errors): array
    {
        $known = $previous ? array_column((array) $previous->options, 'value') : [];
        $clean = [];

        foreach (array_values((array) $options) as $option) {
            $label = trim((string) (is_array($option) ? ($option['label'] ?? '') : $option));

            if ($label === '') {
                continue;
            }

            $value = is_array($option) ? (string) ($option['value'] ?? '') : '';
            // A known value keeps its answers; anything else gets a fresh one.
            $value = $value !== '' && (in_array($value, $known, true) || preg_match('/^[a-z0-9]{6}$/', $value)) ? $value : Str::lower(Str::random(6));

            $clean[] = ['value' => $value, 'label' => mb_substr($label, 0, 120)];
        }

        if (count($clean) < 2) {
            $errors[$field] = __('Give at least two answers to choose from.');
        } elseif (count($clean) > self::MAX_OPTIONS) {
            $errors[$field] = __('At most :max answers to choose from.', ['max' => self::MAX_OPTIONS]);
        } elseif (count(array_unique(array_column($clean, 'value'))) !== count($clean)) {
            $errors[$field] = __('Two answers have the same identifier.');
        }

        return $clean;
    }

    /**
     * Choices of a cleaned builder question (not saved yet).
     *
     * @param  array<string, mixed>  $question
     * @return list<array{value: string, label: string}>
     */
    private function choicesOf(array $question): array
    {
        return match ($question['type']) {
            'yes_no' => [['value' => 'yes', 'label' => ''], ['value' => 'no', 'label' => '']],
            'single', 'multiple' => (array) $question['options'],
            default => [],
        };
    }
}
