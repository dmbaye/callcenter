<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A call recording uploaded by Asterisk (MixMonitor) once the call ended.
 * Matched to CDR rows by uniqueid, or linkedid for the other legs.
 *
 * @property int $id
 * @property int $team_id
 * @property string $uniqueid
 * @property string|null $linkedid
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property int|null $duration
 * @property-read Team $team
 */
#[Fillable(['uniqueid', 'linkedid', 'disk', 'path', 'size', 'duration'])]
class CallRecording extends Model
{
    protected static function booted(): void
    {
        // The file goes with the row (team deletion, retention, manual delete).
        static::deleted(function (CallRecording $recording) {
            Storage::disk($recording->disk)->delete($recording->path);
        });
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
