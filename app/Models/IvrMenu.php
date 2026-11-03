<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $greeting_sound
 * @property string|null $invalid_sound
 * @property int $timeout
 * @property int $max_retries
 * @property list<array{digit: string, label?: string|null, type: string, target?: string|null}> $options
 * @property string $fallback_type
 * @property string|null $fallback_target
 * @property-read Team $team
 */
#[Fillable(['name', 'greeting_sound', 'invalid_sound', 'timeout', 'max_retries', 'options', 'fallback_type', 'fallback_target'])]
class IvrMenu extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Dialplan extension of the menu in the team context. */
    public function extension(): string
    {
        return 'ivr-'.$this->id;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'timeout' => 'integer',
            'max_retries' => 'integer',
        ];
    }
}
