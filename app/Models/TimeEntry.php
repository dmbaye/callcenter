<?php

namespace App\Models;

use App\Support\Workforce;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work session: clock in → clock out (wall clock, workforce timezone).
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $shift_assignment_id
 * @property CarbonImmutable $date
 * @property CarbonImmutable $clock_in_at
 * @property CarbonImmutable|null $clock_out_at
 * @property string $source
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TimeBreak> $breaks
 */
#[Fillable(['team_id', 'user_id', 'shift_assignment_id', 'date', 'clock_in_at', 'clock_out_at', 'source'])]
class TimeEntry extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(TimeBreak::class)->orderBy('started_at');
    }

    public function breakMinutes(?CarbonImmutable $now = null): int
    {
        $now ??= Workforce::now();

        return (int) $this->breaks->sum(fn (TimeBreak $break) => $break->started_at->diffInMinutes($break->ended_at ?? $now));
    }

    /** Worked time without breaks (an open session counts until now). */
    public function workedMinutes(?CarbonImmutable $now = null): int
    {
        $now ??= Workforce::now();
        $end = $this->clock_out_at ?? $now;

        return max(0, (int) $this->clock_in_at->diffInMinutes($end) - $this->breakMinutes($now));
    }

    public function openBreak(): ?TimeBreak
    {
        return $this->breaks->first(fn (TimeBreak $break) => $break->ended_at === null);
    }

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'clock_in_at' => 'immutable_datetime',
            'clock_out_at' => 'immutable_datetime',
        ];
    }
}
