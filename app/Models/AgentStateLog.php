<?php

namespace App\Models;

use App\Enums\AgentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentStateLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['team_id', 'user_id', 'state', 'reason', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'state' => AgentState::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
