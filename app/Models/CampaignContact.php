<?php

namespace App\Models;

use App\Enums\AttemptResult;
use App\Enums\ContactStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $campaign_id
 * @property string $phone
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company
 * @property string|null $email
 * @property array<string, string>|null $data
 * @property ContactStatus $status
 * @property int $priority
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $last_attempt_at
 * @property AttemptResult|null $last_result
 * @property int|null $disposition_id
 * @property int|null $reserved_by
 * @property Carbon|null $reserved_until
 * @property int|null $callback_user_id
 * @property string|null $notes
 * @property-read Campaign $campaign
 * @property-read CampaignDisposition|null $disposition
 */
#[Fillable([
    'phone', 'first_name', 'last_name', 'company', 'email', 'data', 'status', 'priority', 'attempts',
    'next_attempt_at', 'last_attempt_at', 'last_result', 'disposition_id', 'reserved_by', 'reserved_until',
    'callback_user_id', 'notes',
])]
class CampaignContact extends Model
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function disposition(): BelongsTo
    {
        return $this->belongsTo(CampaignDisposition::class);
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(CampaignAttempt::class)->latest('id');
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    /** Placeholders available in the agent script: {first_name}, {company}, any imported column… */
    public function placeholders(): array
    {
        return [
            ...(array) ($this->data ?? []),
            'first_name' => (string) $this->first_name,
            'last_name' => (string) $this->last_name,
            'full_name' => $this->fullName(),
            'company' => (string) $this->company,
            'email' => (string) $this->email,
            'phone' => $this->phone,
        ];
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'name' => $this->fullName() ?: $this->phone,
            'company' => $this->company,
            'email' => $this->email,
            'data' => (object) ($this->data ?? []),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'attempts' => $this->attempts,
            'lastResult' => $this->last_result?->value,
            'lastResultLabel' => $this->last_result?->label(),
            'nextAttemptAt' => $this->next_attempt_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'status' => ContactStatus::class,
            'priority' => 'integer',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_result' => AttemptResult::class,
            'reserved_until' => 'datetime',
        ];
    }
}
