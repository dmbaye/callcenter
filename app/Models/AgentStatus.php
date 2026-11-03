<?php

namespace App\Models;

use App\Enums\AgentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The state an agent chose in a team (one row per team member who ever
 * changed it; no row means Prêt).
 */
class AgentStatus extends Model
{
    protected $table = 'agent_states';

    protected $fillable = ['team_id', 'user_id', 'state', 'pause_reason_id', 'reason', 'changed_at'];

    protected function casts(): array
    {
        return [
            'state' => AgentState::class,
            'changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return $this->state->labelWith($this->reason);
    }
}
