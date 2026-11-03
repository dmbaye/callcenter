<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent's correction of a work session (or a forgotten one). Applied
 * once approved; `previous` keeps the values it replaced.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $time_entry_id
 * @property CarbonImmutable $date
 * @property CarbonImmutable $clock_in_at
 * @property CarbonImmutable $clock_out_at
 * @property int $break_minutes
 * @property string $reason
 * @property array<string, mixed>|null $previous
 * @property string $status
 * @property string|null $review_note
 * @property-read User $user
 * @property-read User|null $reviewer
 */
#[Fillable(['team_id', 'user_id', 'time_entry_id', 'date', 'clock_in_at', 'clock_out_at', 'break_minutes', 'reason', 'previous', 'status', 'reviewed_by', 'reviewed_at', 'review_note'])]
class TimeAdjustment extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class, 'time_entry_id');
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'user' => ['id' => $this->user_id, 'name' => $this->user?->name],
            'entryId' => $this->time_entry_id,
            'date' => $this->date->toDateString(),
            'clockIn' => $this->clock_in_at->format('Y-m-d H:i'),
            'clockOut' => $this->clock_out_at->format('Y-m-d H:i'),
            'breakMinutes' => $this->break_minutes,
            'reason' => $this->reason,
            'previous' => $this->previous,
            'status' => $this->status,
            'reviewer' => $this->reviewer?->name,
            'reviewNote' => $this->review_note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'clock_in_at' => 'immutable_datetime',
            'clock_out_at' => 'immutable_datetime',
            'previous' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
