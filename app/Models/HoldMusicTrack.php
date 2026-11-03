<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hold-music file of a team (8 kHz mono WAV).
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property int|null $duration
 * @property int $position
 * @property int|null $uploaded_by
 * @property-read Team $team
 */
#[Fillable(['team_id', 'name', 'disk', 'path', 'size', 'duration', 'position', 'uploaded_by'])]
class HoldMusicTrack extends Model
{
    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Proof, in the URL Asterisk fetches, that the app handed it out. */
    public function signature(): string
    {
        return substr(hash_hmac('sha256', 'moh:'.$this->id.':'.$this->path, (string) config('app.key')), 0, 40);
    }
}
