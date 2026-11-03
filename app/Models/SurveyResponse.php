<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The answers of one contact to a campaign questionnaire.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $campaign_contact_id
 * @property int|null $campaign_attempt_id
 * @property int|null $user_id
 * @property array<string, mixed> $answers
 * @property Carbon|null $completed_at
 * @property-read CampaignContact $contact
 * @property-read User|null $agent
 */
#[Fillable(['campaign_id', 'campaign_contact_id', 'campaign_attempt_id', 'user_id', 'answers', 'completed_at'])]
class SurveyResponse extends Model
{
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CampaignContact::class, 'campaign_contact_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
