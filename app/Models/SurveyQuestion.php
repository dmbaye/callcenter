<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question of a campaign questionnaire.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $position
 * @property string $type
 * @property string $label
 * @property string|null $help
 * @property bool $required
 * @property list<array{value: string, label: string}>|null $options
 * @property array<string, mixed>|null $settings
 * @property array{question_id: int, values: list<string>}|null $condition
 */
#[Fillable(['position', 'type', 'label', 'help', 'required', 'options', 'settings', 'condition'])]
class SurveyQuestion extends Model
{
    public const TYPES = ['single', 'multiple', 'yes_no', 'rating', 'nps', 'number', 'text', 'long_text', 'date'];

    /** Types with a fixed list of answers: they can drive a condition. */
    public const CHOICE_TYPES = ['single', 'multiple', 'yes_no'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return list<array{value: string, label: string}> */
    public function choices(): array
    {
        return match ($this->type) {
            'yes_no' => [['value' => 'yes', 'label' => __('Oui')], ['value' => 'no', 'label' => __('Non')]],
            'single', 'multiple' => array_values((array) $this->options),
            default => [],
        };
    }

    /** @return array{min: int, max: int} */
    public function scale(): array
    {
        return match ($this->type) {
            'nps' => ['min' => 0, 'max' => 10],
            'rating' => [
                'min' => (int) ($this->settings['min'] ?? 1),
                'max' => (int) ($this->settings['max'] ?? 5),
            ],
            default => ['min' => 0, 'max' => 0],
        };
    }

    /** @return array<string, mixed> */
    public function toBuilder(): array
    {
        $condition = $this->condition;

        return [
            'id' => $this->id,
            // Builder key: conditions point at questions by key (new ones have none yet).
            'key' => 'q'.$this->id,
            'type' => $this->type,
            'label' => $this->label,
            'help' => $this->help,
            'required' => $this->required,
            'options' => array_values((array) $this->options),
            'settings' => (object) ($this->settings ?? []),
            'condition' => is_array($condition) && isset($condition['question_id'])
                ? ['key' => 'q'.$condition['question_id'], 'values' => array_values((array) $condition['values'])]
                : null,
            // Same condition by id, for the agent desktop.
            'showIf' => is_array($condition) && isset($condition['question_id'])
                ? ['questionId' => (int) $condition['question_id'], 'values' => array_values((array) $condition['values'])]
                : null,
            'choices' => $this->choices(),
        ];
    }

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
            'settings' => 'array',
            'condition' => 'array',
        ];
    }
}
