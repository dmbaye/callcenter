<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $team_id
 * @property int $user_id
 * @property \Carbon\CarbonImmutable $week_start
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon $approved_at
 */
#[Fillable(['team_id', 'user_id', 'week_start', 'approved_by', 'approved_at'])]
class TimesheetApproval extends Model
{
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return ['week_start' => 'immutable_date', 'approved_at' => 'datetime'];
    }
}
