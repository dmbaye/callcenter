<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property string $code
 * @property string $name
 * @property bool $paid
 * @property float|null $annual_days  null = no balance
 * @property bool $active
 */
#[Fillable(['code', 'name', 'paid', 'annual_days', 'active', 'position'])]
class LeaveType extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function hasBalance(): bool
    {
        return $this->annual_days !== null;
    }

    protected function casts(): array
    {
        return ['paid' => 'boolean', 'active' => 'boolean', 'annual_days' => 'float'];
    }
}
