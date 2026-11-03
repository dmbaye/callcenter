<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shift permutation: the requester gives a shift, and the colleague gives
 * one back (exchange) or simply takes it (counterparty_assignment_id null).
 *
 * @property int $id
 * @property int $team_id
 * @property int $requester_id
 * @property int $requester_assignment_id
 * @property int $counterparty_id
 * @property int|null $counterparty_assignment_id
 * @property string $status
 * @property string|null $message
 * @property string|null $review_note
 * @property bool $auto_approved
 * @property Carbon|null $reviewed_at
 * @property-read User $requester
 * @property-read User $counterparty
 * @property-read ShiftAssignment $requesterAssignment
 * @property-read ShiftAssignment|null $counterpartyAssignment
 */
#[Fillable(['team_id', 'requester_id', 'requester_assignment_id', 'counterparty_id', 'counterparty_assignment_id', 'status', 'message', 'counterparty_answered_at', 'reviewed_by', 'reviewed_at', 'review_note', 'auto_approved'])]
class ShiftSwap extends Model
{
    public const PENDING_COUNTERPARTY = 'pending_counterparty';

    public const PENDING_SUPERVISOR = 'pending_supervisor';

    public const DECLINED = 'declined';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const OPEN = [self::PENDING_COUNTERPARTY, self::PENDING_SUPERVISOR];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function requesterAssignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'requester_assignment_id');
    }

    public function counterpartyAssignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'counterparty_assignment_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::PENDING_COUNTERPARTY => __('Waiting for the colleague'),
            self::PENDING_SUPERVISOR => __('Waiting for the supervisor'),
            self::DECLINED => __('Declined by the colleague'),
            self::APPROVED => __('Approved'),
            self::REJECTED => __('Rejected'),
            self::CANCELLED => __('Cancelled'),
            default => __('Expired'),
        };
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'statusLabel' => self::statusLabel($this->status),
            'requester' => ['id' => $this->requester_id, 'name' => $this->requester?->name],
            'counterparty' => ['id' => $this->counterparty_id, 'name' => $this->counterparty?->name],
            'give' => $this->requesterAssignment?->toCard(),
            'take' => $this->counterpartyAssignment?->toCard(),
            'message' => $this->message,
            'reviewNote' => $this->review_note,
            'reviewer' => $this->reviewer?->name,
            'autoApproved' => $this->auto_approved,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function casts(): array
    {
        return [
            'counterparty_answered_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'auto_approved' => 'boolean',
        ];
    }
}
