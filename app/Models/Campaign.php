<?php

namespace App\Models;

use App\Enums\AttemptResult;
use App\Enums\CampaignStatus;
use App\Enums\DialMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An outbound campaign: a contact list, a dial mode, calling hours, retry
 * rules and wrap-up codes. Agents are the members of its call queue.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $call_queue_id
 * @property string $kind  outbound | callback (callback list of an inbound queue)
 * @property int|null $created_by
 * @property string $name
 * @property string|null $description
 * @property CampaignStatus $status
 * @property DialMode $dial_mode
 * @property float $dial_ratio
 * @property float $max_abandon_rate
 * @property int|null $max_lines
 * @property string|null $caller_id
 * @property string|null $dial_prefix
 * @property int $ring_timeout
 * @property int $queue_timeout
 * @property int $wrapup_seconds
 * @property bool $amd_enabled
 * @property string|null $voicemail_sound
 * @property string|null $abandon_sound
 * @property int $max_attempts
 * @property array<string, int>|null $retry_minutes
 * @property string $timezone
 * @property array{days?: list<int>, start?: string, end?: string}|null $schedule
 * @property string|null $script
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property-read Team $team
 * @property-read CallQueue|null $queue
 * @property-read Collection<int, CampaignDisposition> $dispositions
 * @property-read Collection<int, CampaignContact> $contacts
 * @property-read Collection<int, CampaignAttempt> $attempts
 */
#[Fillable([
    'kind', 'name', 'description', 'status', 'dial_mode', 'dial_ratio', 'max_abandon_rate', 'max_lines',
    'caller_id', 'dial_prefix', 'ring_timeout', 'queue_timeout', 'wrapup_seconds',
    'amd_enabled', 'voicemail_sound', 'abandon_sound', 'max_attempts', 'retry_minutes',
    'timezone', 'schedule', 'script', 'started_at', 'completed_at', 'created_by', 'call_queue_id',
])]
class Campaign extends Model
{
    /** Default minutes before retrying, per result. */
    public const DEFAULT_RETRY_MINUTES = [
        'no_answer' => 60,
        'busy' => 15,
        'failed' => 30,
        'machine' => 240,
        'abandoned' => 10,
        'cancelled' => 0,
    ];

    public const DEFAULT_SCHEDULE = ['days' => [1, 2, 3, 4, 5, 6], 'start' => '08:00', 'end' => '19:00'];

    public const KIND_OUTBOUND = 'outbound';

    public const KIND_CALLBACK = 'callback';

    public function isCallbackList(): bool
    {
        return $this->kind === self::KIND_CALLBACK;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class, 'call_queue_id');
    }

    public function dispositions(): HasMany
    {
        return $this->hasMany(CampaignDisposition::class)->orderBy('sort')->orderBy('id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CampaignAttempt::class);
    }

    public function retryMinutesFor(AttemptResult $result): int
    {
        $configured = (array) ($this->retry_minutes ?? []);

        return (int) ($configured[$result->retryKey()] ?? self::DEFAULT_RETRY_MINUTES[$result->retryKey()] ?? 60);
    }

    /** @return array{days: list<int>, start: string, end: string} */
    public function scheduleWindow(): array
    {
        return [...self::DEFAULT_SCHEDULE, ...(array) ($this->schedule ?? [])];
    }

    /** Inside the calling hours, in the campaign's timezone (ISO days 1 = Monday). */
    public function isWithinCallingHours(?CarbonInterface $at = null): bool
    {
        $window = $this->scheduleWindow();
        $local = ($at ?? now())->copy()->setTimezone($this->timezone ?: 'Africa/Bamako');

        if (! in_array($local->dayOfWeekIso, array_map('intval', $window['days']), true)) {
            return false;
        }

        $time = $local->format('H:i');

        return $time >= $window['start'] && $time < $window['end'];
    }

    public function isRunning(): bool
    {
        return $this->status === CampaignStatus::Running;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'dial_mode' => DialMode::class,
            'dial_ratio' => 'float',
            'max_abandon_rate' => 'float',
            'max_lines' => 'integer',
            'ring_timeout' => 'integer',
            'queue_timeout' => 'integer',
            'wrapup_seconds' => 'integer',
            'amd_enabled' => 'boolean',
            'max_attempts' => 'integer',
            'retry_minutes' => 'array',
            'schedule' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<SurveyQuestion, $this> */
    public function surveyQuestions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<SurveyResponse, $this> */
    public function surveyResponses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

}
