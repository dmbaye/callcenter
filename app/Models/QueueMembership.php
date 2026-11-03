<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['call_queue_id', 'user_id', 'penalty'])]
class QueueMembership extends Model
{
    protected $table = 'queue_memberships';

    public function queue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class, 'call_queue_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
