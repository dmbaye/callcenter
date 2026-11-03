<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DID: the number the SIP provider sends, routed to a team destination.
 *
 * @property int $id
 * @property int $team_id
 * @property string $number
 * @property string $source 'pool' (lent by the platform) or 'own' (the customer's number)
 * @property string|null $label
 * @property string $destination_type
 * @property string|null $destination_target
 * @property-read Team $team
 */
#[Fillable(['number', 'source', 'label', 'destination_type', 'destination_target'])]
class InboundNumber extends Model
{
    public const SOURCE_POOL = 'pool';

    public const SOURCE_OWN = 'own';

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function fromPool(): bool
    {
        return $this->source === self::SOURCE_POOL;
    }
}
