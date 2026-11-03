<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent on a shift, on a day. starts_at / ends_at are wall-clock times
 * in the workforce timezone.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $shift_template_id
 * @property CarbonImmutable $date
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $break_minutes
 * @property string|null $note
 * @property list<string>|null $warnings
 * @property-read User $user
 * @property-read ShiftTemplate|null $template
 */
#[Fillable(['team_id', 'user_id', 'shift_template_id', 'date', 'starts_at', 'ends_at', 'break_minutes', 'note', 'warnings', 'created_by'])]
class ShiftAssignment extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** Scheduled work, without the break. */
    public function scheduledMinutes(): int
    {
        return max(0, (int) $this->starts_at->diffInMinutes($this->ends_at) - $this->break_minutes);
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->relationLoaded('user') ? $this->user?->name : null,
            'templateId' => $this->shift_template_id,
            'code' => $this->template?->code ?? '·',
            'name' => $this->template?->name,
            'color' => $this->template?->color ?? 'gray',
            'date' => $this->date->toDateString(),
            'startsAt' => $this->starts_at->format('Y-m-d H:i'),
            'endsAt' => $this->ends_at->format('Y-m-d H:i'),
            'window' => $this->starts_at->format('H:i').'–'.$this->ends_at->format('H:i'),
            'note' => $this->note,
            'warnings' => $this->warnings ?? [],
        ];
    }

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'warnings' => 'array',
        ];
    }
}
