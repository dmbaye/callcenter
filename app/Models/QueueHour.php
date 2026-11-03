<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['call_queue_id', 'weekday', 'opens_at', 'closes_at'])]
class QueueHour extends Model
{
    use HasFactory;

    /** weekday 0 = Monday ... 6 = Sunday, as used by GotoIfTime(). */
    public const DAY_NAMES = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class, 'call_queue_id');
    }
}
