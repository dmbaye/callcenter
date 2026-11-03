<?php

namespace App\Models;

use App\Enums\AttemptResult;
use App\Enums\DialMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dial of one contact. status: dialing → answered → connected → ended.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $campaign_contact_id
 * @property int $team_id
 * @property int|null $user_id
 * @property DialMode $mode
 * @property string $phone
 * @property string $status
 * @property AttemptResult|null $result
 * @property string|null $uniqueid
 * @property Carbon|null $answered_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $ended_at
 * @property int|null $disposition_id
 * @property Carbon|null $dispositioned_at
 * @property string|null $notes
 * @property Carbon $created_at
 * @property-read Campaign $campaign
 * @property-read CampaignContact $contact
 * @property-read User|null $agent
 * @property-read CampaignDisposition|null $disposition
 */
#[Fillable([
    'campaign_id', 'campaign_contact_id', 'team_id', 'user_id', 'mode', 'phone', 'status', 'result',
    'uniqueid', 'answered_at', 'connected_at', 'ended_at', 'disposition_id', 'dispositioned_at', 'notes',
])]
class CampaignAttempt extends Model
{
    public const DIALING = 'dialing';

    public const ANSWERED = 'answered';

    public const CONNECTED = 'connected';

    public const ENDED = 'ended';

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CampaignContact::class, 'campaign_contact_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function disposition(): BelongsTo
    {
        return $this->belongsTo(CampaignDisposition::class);
    }

    public function isEnded(): bool
    {
        return $this->status === self::ENDED;
    }

    public function talkSeconds(): ?int
    {
        return $this->connected_at && $this->ended_at
            ? (int) $this->connected_at->diffInSeconds($this->ended_at)
            : null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => DialMode::class,
            'result' => AttemptResult::class,
            'answered_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
            'dispositioned_at' => 'datetime',
        ];
    }
}
