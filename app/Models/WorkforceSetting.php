<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Labor rules of a team; null columns fall back to config/workforce.php.
 *
 * @property int $team_id
 * @property int|null $max_consecutive_hours
 * @property int|null $min_rest_hours
 * @property int|null $min_rest_after_long_hours
 * @property int|null $max_weekly_hours
 * @property string|null $enforcement
 * @property bool $auto_approve_swaps
 * @property int|null $late_tolerance_minutes
 */
#[Fillable(['team_id', 'max_consecutive_hours', 'min_rest_hours', 'min_rest_after_long_hours', 'max_weekly_hours', 'enforcement', 'auto_approve_swaps', 'late_tolerance_minutes'])]
class WorkforceSetting extends Model
{
    public const RULES = ['max_consecutive_hours', 'min_rest_hours', 'min_rest_after_long_hours', 'max_weekly_hours', 'enforcement', 'late_tolerance_minutes'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** The team's rules merged over the defaults. */
    public function rule(string $key): mixed
    {
        return $this->{$key} ?? config("workforce.rules.{$key}");
    }

    public function blocks(): bool
    {
        return $this->rule('enforcement') !== 'warn';
    }

    protected function casts(): array
    {
        return ['auto_approve_swaps' => 'boolean'];
    }
}
