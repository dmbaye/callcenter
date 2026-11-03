<?php

namespace App\Services\Workforce;

use App\Models\ShiftAssignment;
use App\Models\ShiftSwap;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Workforce\WorkforceNotification;
use App\Support\Workforce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Shift permutation: agent → colleague accepts → supervisor approves (or
 * the team auto-approves) → the roster changes. Each step re-checks that
 * both resulting schedules respect the labor rules.
 */
class ShiftSwaps
{
    public function __construct(private Planner $planner, private LaborRules $rules) {}

    public function request(
        Team $team,
        User $requester,
        ShiftAssignment $give,
        User $counterparty,
        ?ShiftAssignment $take,
        ?string $message,
    ): ShiftSwap {
        abort_unless($give->team_id === $team->id && $give->user_id === $requester->id, 403);

        if ($requester->is($counterparty) || ! $counterparty->belongsToTeam($team)) {
            throw ValidationException::withMessages(['counterparty_id' => __('Choose a colleague of the team.')]);
        }

        if ($take !== null && ($take->team_id !== $team->id || $take->user_id !== $counterparty->id)) {
            throw ValidationException::withMessages(['counterparty_assignment_id' => __('This shift is not the colleague\'s.')]);
        }

        foreach (array_filter([$give, $take]) as $assignment) {
            if ($assignment->starts_at->lte(Workforce::now())) {
                throw ValidationException::withMessages(['requester_assignment_id' => __('A shift that has started can no longer be swapped.')]);
            }
        }

        $busy = ShiftSwap::query()
            ->whereIn('status', ShiftSwap::OPEN)
            ->where(function ($query) use ($give, $take) {
                $ids = array_filter([$give->id, $take?->id]);
                $query->whereIn('requester_assignment_id', $ids)->orWhereIn('counterparty_assignment_id', $ids);
            })
            ->exists();

        if ($busy) {
            throw ValidationException::withMessages(['requester_assignment_id' => __('A swap is already open for this shift.')]);
        }

        if (($reason = $this->qualificationProblem($team, $requester, $counterparty)) !== null) {
            throw ValidationException::withMessages(['counterparty_id' => $reason]);
        }

        $this->checkRules($team, $requester, $give, $counterparty, $take, 'counterparty_id');

        $swap = $team->shiftSwaps()->create([
            'requester_id' => $requester->id,
            'requester_assignment_id' => $give->id,
            'counterparty_id' => $counterparty->id,
            'counterparty_assignment_id' => $take?->id,
            'status' => ShiftSwap::PENDING_COUNTERPARTY,
            'message' => $message,
        ]);

        $this->planner->safely(fn () => $counterparty->notify($this->notification($team, $swap, 'swap.requested',
            ':name asks to swap a shift', ':name offers you their :shift shift of :date:take.', $counterparty, 'Answer')));

        return $swap;
    }

    /** The colleague accepts (then supervisor review) or declines. */
    public function respond(Team $team, ShiftSwap $swap, User $counterparty, bool $accept): ShiftSwap
    {
        abort_unless($swap->team_id === $team->id && $swap->counterparty_id === $counterparty->id, 403);
        $this->ensureStatus($swap, ShiftSwap::PENDING_COUNTERPARTY);

        if (! $accept) {
            $swap->update(['status' => ShiftSwap::DECLINED, 'counterparty_answered_at' => now()]);
            $this->planner->safely(fn () => $swap->requester->notify($this->notification($team, $swap, 'swap.declined',
                ':counterparty declined your swap', 'Your :shift shift of :date stays on your schedule.', $swap->requester)));

            return $swap;
        }

        $swap->loadMissing(['requester', 'counterparty', 'requesterAssignment.template', 'counterpartyAssignment.template']);
        $this->checkRules($team, $swap->requester, $swap->requesterAssignment, $counterparty, $swap->counterpartyAssignment, 'swap');
        $swap->update(['status' => ShiftSwap::PENDING_SUPERVISOR, 'counterparty_answered_at' => now()]);

        // Auto-approval: the team allows it and no labor rule is even bent.
        $settings = $this->planner->ensureSetup($team);

        if ($settings->auto_approve_swaps && $this->ruleWarnings($team, $swap) === []) {
            return $this->approve($team, $swap, null, auto: true);
        }

        $this->planner->safely(fn () => Notification::send($this->planner->reviewersFor($team, $swap->requester, $swap->counterparty), $this->notification($team, $swap, 'swap.pending',
            'Shift swap to approve', ':name and :counterparty agreed to swap the :shift shift of :date.', null, 'Review')));

        return $swap;
    }

    public function review(Team $team, ShiftSwap $swap, User $supervisor, bool $approve, ?string $note): ShiftSwap
    {
        abort_unless($swap->team_id === $team->id && ($this->planner->canManage($team, $supervisor, $swap->requester_id) || $this->planner->canManage($team, $supervisor, $swap->counterparty_id)), 403);
        $this->ensureStatus($swap, ShiftSwap::PENDING_SUPERVISOR);

        if ($approve) {
            return $this->approve($team, $swap, $supervisor, auto: false, note: $note);
        }

        if (blank($note)) {
            throw ValidationException::withMessages(['review_note' => __('Give the reason of the refusal.')]);
        }

        $swap->update(['status' => ShiftSwap::REJECTED, 'reviewed_by' => $supervisor->id, 'reviewed_at' => now(), 'review_note' => $note]);

        foreach ([$swap->requester, $swap->counterparty] as $agent) {
            $this->planner->safely(fn () => $agent->notify($this->notification($team, $swap, 'swap.rejected',
                'Shift swap refused', 'The swap of the :shift shift of :date was refused: :note', $agent)));
        }

        return $swap;
    }

    public function cancel(Team $team, ShiftSwap $swap, User $requester): void
    {
        abort_unless($swap->team_id === $team->id && $swap->requester_id === $requester->id, 403);

        if (! $swap->isOpen()) {
            throw ValidationException::withMessages(['swap' => __('This swap is already closed.')]);
        }

        $swap->update(['status' => ShiftSwap::CANCELLED]);
    }

    /** Swaps whose shift has started without a final answer. */
    public function expireStarted(): int
    {
        $ids = ShiftSwap::query()
            ->whereIn('status', ShiftSwap::OPEN)
            ->whereHas('requesterAssignment', fn ($q) => $q->where('starts_at', '<=', Workforce::now()))
            ->pluck('id');

        return ShiftSwap::query()->whereKey($ids)->update(['status' => ShiftSwap::EXPIRED]);
    }

    private function approve(Team $team, ShiftSwap $swap, ?User $supervisor, bool $auto, ?string $note = null): ShiftSwap
    {
        DB::transaction(function () use ($team, $swap, $supervisor, $auto, $note): void {
            /** @var ShiftAssignment $give */
            $give = ShiftAssignment::query()->lockForUpdate()->findOrFail($swap->requester_assignment_id);
            $take = $swap->counterparty_assignment_id ? ShiftAssignment::query()->lockForUpdate()->findOrFail($swap->counterparty_assignment_id) : null;

            if ($give->starts_at->lte(Workforce::now()) || ($take !== null && $take->starts_at->lte(Workforce::now()))) {
                $swap->update(['status' => ShiftSwap::EXPIRED]);

                throw ValidationException::withMessages(['swap' => __('A shift that has started can no longer be swapped.')]);
            }

            $warnings = $this->checkRules($team, $swap->requester, $give, $swap->counterparty, $take, 'swap');

            // Swap the owners; each assignment keeps its date and times.
            $give->update(['user_id' => $swap->counterparty_id, 'warnings' => $warnings ?: null]);
            $take?->update(['user_id' => $swap->requester_id, 'warnings' => $warnings ?: null]);

            $swap->update([
                'status' => ShiftSwap::APPROVED,
                'reviewed_by' => $supervisor?->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'auto_approved' => $auto,
            ]);
        });

        foreach ([$swap->requester, $swap->counterparty] as $agent) {
            $this->planner->safely(fn () => $agent->notify($this->notification($team, $swap, 'swap.approved',
                'Shift swap approved', 'The swap of the :shift shift of :date is approved: your schedule is updated.', $agent, 'See my schedule')));
        }

        return $swap;
    }

    /**
     * After the swap: the colleague works `give`, the requester works `take`.
     * Overlaps and leave are always refused; other rules when enforced.
     *
     * @return list<string> warnings kept (enforcement = warn)
     */
    private function checkRules(Team $team, User $requester, ShiftAssignment $give, User $counterparty, ?ShiftAssignment $take, string $field): array
    {
        $warnings = $this->planner->guard($team, $counterparty, [[$give->starts_at, $give->ends_at]], array_filter([$take?->id]), $field);

        if ($take !== null) {
            $warnings = [...$warnings, ...$this->planner->guard($team, $requester, [[$take->starts_at, $take->ends_at]], [$give->id], $field)];
        }

        return array_values(array_unique($warnings));
    }

    /** @return list<string> rule warnings, even when the team only warns */
    private function ruleWarnings(Team $team, ShiftSwap $swap): array
    {
        $give = $swap->requesterAssignment;
        $take = $swap->counterpartyAssignment;
        $warnings = $this->rules->check($team, $swap->counterparty, [[$give->starts_at, $give->ends_at]], array_filter([$take?->id]))['warnings'];

        if ($take !== null) {
            $warnings = [...$warnings, ...$this->rules->check($team, $swap->requester, [[$take->starts_at, $take->ends_at]], [$give->id])['warnings']];
        }

        return $warnings;
    }

    /**
     * « Qualified »: when the requester answers call queues, the colleague
     * must answer at least one of them and have a phone extension.
     */
    private function qualificationProblem(Team $team, User $requester, User $counterparty): ?string
    {
        if ($team->isInternalOnly()) {
            return null;
        }

        $queues = $requester->teamQueues($team)->pluck('id');

        if ($queues->isEmpty()) {
            return null;
        }

        if ($counterparty->phoneExtensionFor($team) === null) {
            return __(':name has no phone extension: they cannot take your calls.', ['name' => $counterparty->name]);
        }

        if ($counterparty->teamQueues($team)->pluck('id')->intersect($queues)->isEmpty()) {
            return __(':name does not answer any of your queues.', ['name' => $counterparty->name]);
        }

        return null;
    }

    private function ensureStatus(ShiftSwap $swap, string $status): void
    {
        if ($swap->status !== $status) {
            throw ValidationException::withMessages(['swap' => __('This swap is no longer waiting for this answer.')]);
        }
    }

    private function notification(Team $team, ShiftSwap $swap, string $kind, string $title, string $body, ?User $reader, ?string $action = null): WorkforceNotification
    {
        $swap->loadMissing(['requester', 'counterparty', 'requesterAssignment.template', 'counterpartyAssignment.template']);
        $give = $swap->requesterAssignment;
        $take = $swap->counterpartyAssignment;
        $replace = [
            ...$this->planner->assignmentReplacements($give, $reader),
            'name' => $swap->requester->name,
            'counterparty' => $swap->counterparty->name,
            'note' => (string) $swap->review_note,
            'take' => $take ? ' '.__('against yours of :date', ['date' => $this->planner->assignmentReplacements($take, $reader)['date']]) : '',
        ];
        $url = route($reader === null ? 'workforce.requests' : 'workforce.me', ['current_team' => $team->slug]);

        return new WorkforceNotification($kind, $title, $body, $url, $replace, $action);
    }
}
