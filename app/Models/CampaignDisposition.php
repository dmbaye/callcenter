<?php

namespace App\Models;

use App\Enums\DispositionOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $campaign_id
 * @property string $label
 * @property DispositionOutcome $outcome
 * @property int|null $retry_minutes
 * @property int $sort
 * @property-read Campaign $campaign
 */
#[Fillable(['label', 'outcome', 'retry_minutes', 'sort'])]
class CampaignDisposition extends Model
{
    /** Wrap-up codes a new campaign starts with. */
    public const DEFAULTS = [
        ['label' => 'Intéressé / vente', 'outcome' => 'success'],
        ['label' => 'Rendez-vous pris', 'outcome' => 'success'],
        ['label' => 'Pas intéressé', 'outcome' => 'final'],
        ['label' => 'Mauvais numéro', 'outcome' => 'final'],
        ['label' => 'Rappeler plus tard', 'outcome' => 'callback'],
        ['label' => 'Absent, réessayer', 'outcome' => 'retry', 'retry_minutes' => 120],
        ['label' => 'Ne plus appeler', 'outcome' => 'dnc'],
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CampaignAttempt::class, 'disposition_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => DispositionOutcome::class,
            'retry_minutes' => 'integer',
            'sort' => 'integer',
        ];
    }
}
