<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Full days (start_date → end_date) or part of one day (start_time → end_time).
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int $leave_type_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property float $days
 * @property string|null $reason
 * @property string $status
 * @property string|null $review_note
 * @property-read User $user
 * @property-read LeaveType $type
 */
#[Fillable(['team_id', 'user_id', 'leave_type_id', 'start_date', 'end_date', 'start_time', 'end_time', 'days', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note'])]
class LeaveRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPartial(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    /**
     * The time off, wall clock: whole days, or the partial window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(): array
    {
        if ($this->isPartial()) {
            $day = $this->start_date->toDateString();
            $start = CarbonImmutable::parse($day.' '.substr($this->start_time, 0, 5), 'UTC');
            $end = CarbonImmutable::parse($day.' '.substr($this->end_time, 0, 5), 'UTC');

            return [$start, $end->lte($start) ? $end->addDay() : $end];
        }

        return [$this->start_date->startOfDay(), $this->end_date->addDay()->startOfDay()];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::PENDING => __('Pending'),
            self::APPROVED => __('Approved'),
            self::REJECTED => __('Rejected'),
            default => __('Cancelled'),
        };
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'user' => ['id' => $this->user_id, 'name' => $this->user?->name],
            'type' => ['id' => $this->leave_type_id, 'name' => $this->type?->name, 'paid' => $this->type?->paid],
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date->toDateString(),
            'startTime' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'endTime' => $this->end_time ? substr($this->end_time, 0, 5) : null,
            'partial' => $this->isPartial(),
            'days' => $this->days,
            'reason' => $this->reason,
            'status' => $this->status,
            'statusLabel' => self::statusLabel($this->status),
            'reviewer' => $this->reviewer?->name,
            'reviewNote' => $this->review_note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'days' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }
}
