<?php

namespace App\Models;

use Database\Factories\CallOutcomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Call outcome (wrap-up) entered by an agent after a call.
 *
 * @property int $id
 * @property int $user_id
 * @property string $caller
 * @property string $direction inbound|outbound
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration seconds
 * @property string $outcome
 * @property string|null $notes
 */
#[Fillable(['team_id', 'user_id', 'caller', 'direction', 'started_at', 'ended_at', 'duration', 'outcome', 'notes'])]
class CallOutcome extends Model
{
    /** @use HasFactory<CallOutcomeFactory> */
    use HasFactory;

    /** @var list<string> */
    public const OUTCOMES = [
        'resolved',
        'callback',
        'voicemail',
        'sale',
        'no_sale',
        'wrong_number',
        'other',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
