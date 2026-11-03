<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $call_queue_id
 * @property int|null $user_id
 * @property string|null $uniqueid
 * @property string|null $caller
 * @property int $score
 */
#[Fillable(['call_queue_id', 'user_id', 'uniqueid', 'caller', 'score'])]
class CallSurvey extends Model
{
    public function queue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class, 'call_queue_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
