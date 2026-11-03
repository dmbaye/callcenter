<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A DID of the platform's SIP trunk, free or lent to a team.
 *
 * @property int $id
 * @property string $number
 * @property string|null $note
 * @property int|null $team_id
 * @property Carbon|null $assigned_at
 * @property-read Team|null $team
 */
#[Fillable(['number', 'note'])]
class PoolNumber extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @param  Builder<PoolNumber>  $query */
    public function scopeFree(Builder $query): void
    {
        $query->whereNull('team_id');
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }
}
