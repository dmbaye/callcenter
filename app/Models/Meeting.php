<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A team video meeting backed by one LiveKit room.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $scheduled_at
 * @property int|null $duration_minutes
 * @property int|null $time_limit_minutes
 * @property string|null $ended_reason
 * @property string|null $notes
 * @property Carbon|null $notes_updated_at
 * @property int|null $notes_updated_by
 * @property Carbon|null $reminded_at
 * @property Carbon|null $cancelled_at
 * @property int $calendar_sequence
 * @property string $room_name
 * @property int $max_participants
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property string|null $recording_egress_id
 * @property Carbon|null $recording_started_at
 * @property string|null $recording_location
 * @property Carbon $created_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, User> $invitees
 */
#[Fillable([
    'uuid', 'title', 'description', 'room_name', 'max_participants', 'created_by', 'started_at', 'ended_at',
    'scheduled_at', 'duration_minutes', 'reminded_at', 'cancelled_at', 'calendar_sequence',
    'time_limit_minutes', 'ended_reason', 'notes', 'notes_updated_at', 'notes_updated_by',
    'recording_egress_id', 'recording_started_at', 'recording_location',
])]
class Meeting extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Meeting $meeting) {
            $meeting->uuid ??= (string) Str::uuid();
        });
    }

    public static function roomNameFor(Team $team, string $uuid): string
    {
        return 't_'.$team->asterisk_key.'_'.str_replace('-', '', $uuid);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notesEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notes_updated_by');
    }

    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('joined_at')->withTimestamps();
    }

    /** Ended, or cancelled before it took place: nobody can join any more. */
    public function isEnded(): bool
    {
        return $this->ended_at !== null || $this->cancelled_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Planned ahead with a date (otherwise an instant meeting). */
    public function isScheduled(): bool
    {
        return $this->scheduled_at !== null;
    }

    /** Planned and not started yet. */
    public function isUpcoming(): bool
    {
        return $this->isScheduled() && $this->started_at === null && ! $this->isEnded();
    }

    /** Someone has joined and the room is still open. */
    public function isLive(): bool
    {
        return $this->started_at !== null && ! $this->isEnded();
    }

    /** Planned end (start + duration), used by calendars. */
    public function plannedEnd(): ?CarbonInterface
    {
        return $this->scheduled_at?->copy()->addMinutes($this->duration_minutes ?? 30);
    }

    /** When the plan's time limit closes the room (null: not started, or no limit). */
    public function limitEndsAt(): ?CarbonInterface
    {
        return $this->started_at && $this->time_limit_minutes
            ? $this->started_at->copy()->addMinutes($this->time_limit_minutes)
            : null;
    }

    public function hasReachedTimeLimit(): bool
    {
        return $this->limitEndsAt()?->isPast() ?? false;
    }

    public function joinUrl(): string
    {
        return route('meetings.show', ['current_team' => $this->team->slug, 'meeting' => $this->uuid]);
    }

    public function isRecording(): bool
    {
        return $this->recording_egress_id !== null;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_participants' => 'integer',
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'reminded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'calendar_sequence' => 'integer',
            'time_limit_minutes' => 'integer',
            'notes_updated_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'recording_started_at' => 'datetime',
        ];
    }
}
