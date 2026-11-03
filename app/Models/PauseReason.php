<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PauseReason extends Model
{
    protected $fillable = ['name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Agents whose current state points at this reason. */
    public function agentStatuses(): HasMany
    {
        return $this->hasMany(AgentStatus::class);
    }
}
