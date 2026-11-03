<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shift block (A 08:00–16:00, B 16:00–00:00…). An end not after the
 * start means the shift ends the next day.
 *
 * @property int $id
 * @property int $team_id
 * @property string $code
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property int $min_agents
 * @property string $color
 * @property int $position
 * @property bool $active
 */
#[Fillable(['code', 'name', 'start_time', 'end_time', 'break_minutes', 'min_agents', 'color', 'position', 'active'])]
class ShiftTemplate extends Model
{
    public const COLORS = ['blue', 'violet', 'green', 'orange', 'pink', 'teal', 'gray'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Start and end of this shift on a day (wall clock, workforce timezone).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodOn(string $date): array
    {
        $start = CarbonImmutable::parse($date.' '.substr($this->start_time, 0, 5));
        $end = CarbonImmutable::parse($date.' '.substr($this->end_time, 0, 5));

        if ($end->lte($start)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    public function hours(): float
    {
        [$start, $end] = $this->periodOn('2000-01-03');

        return $start->diffInMinutes($end) / 60;
    }

    /** "08:00–16:00" */
    public function window(): string
    {
        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'startTime' => substr($this->start_time, 0, 5),
            'endTime' => substr($this->end_time, 0, 5),
            'window' => $this->window(),
            'hours' => $this->hours(),
            'breakMinutes' => $this->break_minutes,
            'minAgents' => $this->min_agents,
            'color' => $this->color,
            'active' => $this->active,
        ];
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
