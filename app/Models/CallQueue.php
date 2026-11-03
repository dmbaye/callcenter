<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $asterisk_name
 * @property int|null $max_wait
 * @property string|null $overflow_type
 * @property string|null $overflow_target
 * @property bool $callback_offer
 * @property bool $survey_enabled
 * @property bool $emergency_closed
 * @property string|null $emergency_sound
 * @property string|null $closed_sound
 * @property bool $record_calls
 * @property bool $recording_notice
 * @property-read Team $team
 */
#[Fillable([
    'team_id', 'name', 'asterisk_name', 'max_wait', 'overflow_type', 'overflow_target',
    'callback_offer', 'survey_enabled', 'emergency_closed', 'emergency_sound', 'closed_sound',
    'record_calls', 'recording_notice',
])]
class CallQueue extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (CallQueue $queue) {
            if ($queue->isDirty('asterisk_name') && $queue->getOriginal('asterisk_name') !== null) {
                throw new LogicException('The Asterisk queue name is immutable.');
            }
        });
    }

    protected $table = 'call_queues';

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'queue_memberships')
            ->withPivot(['penalty'])
            ->withTimestamps();
    }

    public function hours(): HasMany
    {
        return $this->hasMany(QueueHour::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(QueueClosure::class)->orderBy('date');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_wait' => 'integer',
            'callback_offer' => 'boolean',
            'survey_enabled' => 'boolean',
            'emergency_closed' => 'boolean',
            'record_calls' => 'boolean',
            'recording_notice' => 'boolean',
        ];
    }
}
