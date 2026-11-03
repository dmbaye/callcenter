<?php

namespace App\Services\Workforce;

use App\Models\LeaveRequest;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use App\Models\TimesheetApproval;
use App\Models\User;
use App\Models\WorkforceSetting;
use App\Notifications\Workforce\WorkforceNotification;
use App\Support\Workforce;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Timesheets: the scheduled shifts of each day next to what was clocked
 * (in, out, breaks), the variance, and the agent's corrections once a
 * supervisor approved them. A validated week is frozen for payroll.
 */
class Timesheets
{
    public function __construct(private Planner $planner) {}

    /* ---------------------------------------------------------------
     | Clock
     * ------------------------------------------------------------- */

    public function openEntry(Team $team, User $user): ?TimeEntry
    {
        return TimeEntry::query()
            ->with('breaks')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();
    }

    public function clockIn(Team $team, User $user): TimeEntry
    {
        if ($this->openEntry($team, $user) !== null) {
            throw ValidationException::withMessages(['clock' => __('You are already clocked in.')]);
        }

        $now = Workforce::now();
        $assignment = $this->currentAssignment($team, $user, $now);
        $this->ensureWeekOpen($team, $user, $assignment?->date ?? $now);

        return $team->timeEntries()->create([
            'user_id' => $user->id,
            'shift_assignment_id' => $assignment?->id,
            // A night shift's session belongs to the day it started on.
            'date' => ($assignment?->date ?? $now)->toDateString(),
            'clock_in_at' => $now,
        ]);
    }

    public function clockOut(Team $team, User $user): TimeEntry
    {
        $entry = $this->openEntry($team, $user) ?? throw ValidationException::withMessages(['clock' => __('You are not clocked in.')]);
        $now = Workforce::now();

        DB::transaction(function () use ($entry, $now): void {
            $entry->breaks()->whereNull('ended_at')->update(['ended_at' => $now]);
            $entry->update(['clock_out_at' => $now]);
        });

        return $entry->refresh();
    }

    public function startBreak(Team $team, User $user): TimeEntry
    {
        $entry = $this->openEntry($team, $user) ?? throw ValidationException::withMessages(['clock' => __('Clock in before taking a break.')]);

        if ($entry->openBreak() !== null) {
            throw ValidationException::withMessages(['clock' => __('You are already on a break.')]);
        }

        $entry->breaks()->create(['started_at' => Workforce::now()]);

        return $entry->load('breaks');
    }

    public function endBreak(Team $team, User $user): TimeEntry
    {
        $entry = $this->openEntry($team, $user);
        $break = $entry?->openBreak() ?? throw ValidationException::withMessages(['clock' => __('You are not on a break.')]);

        $break->update(['ended_at' => Workforce::now()]);

        return $entry->load('breaks');
    }

    /** The shift the agent is clocking in for: running, or starting within 2 h. */
    private function currentAssignment(Team $team, User $user, CarbonImmutable $now): ?ShiftAssignment
    {
        return ShiftAssignment::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('starts_at', '<=', $now->addHours(2))
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->first();
    }

    /* ---------------------------------------------------------------
     | Sheets
     * ------------------------------------------------------------- */

    /**
     * Day by day, scheduled vs clocked, for one agent.
     *
     * @return array{days: list<array<string, mixed>>, totals: array<string, int>, approval: array<string, mixed>|null}
     */
    public function sheet(Team $team, User $user, CarbonImmutable $weekStart): array
    {
        $from = $weekStart;
        $to = $weekStart->addDays(6);
        $tolerance = (int) (($team->workforceSetting ?? new WorkforceSetting)->rule('late_tolerance_minutes') ?? 0);
        $now = Workforce::now();

        $assignments = ShiftAssignment::query()->with('template')
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('starts_at')->get()
            ->groupBy(fn (ShiftAssignment $a) => $a->date->toDateString());

        $entries = TimeEntry::query()->with('breaks')
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('clock_in_at')->get()
            ->groupBy(fn (TimeEntry $e) => $e->date->toDateString());

        $leaves = LeaveRequest::query()->with('type')
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        $pending = TimeAdjustment::query()
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->where('status', TimeAdjustment::PENDING)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()->groupBy(fn (TimeAdjustment $a) => $a->date->toDateString());

        $days = [];
        $totals = ['scheduled' => 0, 'worked' => 0, 'breaks' => 0, 'leave' => 0, 'variance' => 0, 'late' => 0, 'absences' => 0];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            /** @var Collection<int, ShiftAssignment> $shifts */
            $shifts = $assignments->get($date, collect());
            /** @var Collection<int, TimeEntry> $sessions */
            $sessions = $entries->get($date, collect());

            $scheduled = (int) $shifts->sum(fn (ShiftAssignment $a) => $a->scheduledMinutes());
            $worked = (int) $sessions->sum(fn (TimeEntry $e) => $e->workedMinutes($now));
            $breaks = (int) $sessions->sum(fn (TimeEntry $e) => $e->breakMinutes($now));
            $leaveMinutes = $this->leaveMinutes($leaves, $shifts, $day);
            $expected = max(0, $scheduled - $leaveMinutes);
            $past = $day->addDay()->lte($now);

            $flags = [];
            $firstShift = $shifts->first();
            $firstIn = $sessions->first()?->clock_in_at;

            if ($firstShift && $firstIn && $firstIn->gt($firstShift->starts_at->addMinutes($tolerance))) {
                $flags[] = ['key' => 'late', 'label' => __('Late by :min min', ['min' => (int) $firstShift->starts_at->diffInMinutes($firstIn)])];
                $totals['late']++;
            }

            if ($expected > 0 && $sessions->isEmpty() && $past) {
                $flags[] = ['key' => 'absent', 'label' => __('Absent')];
                $totals['absences']++;
            }

            if ($shifts->isEmpty() && $sessions->isNotEmpty()) {
                $flags[] = ['key' => 'unscheduled', 'label' => __('Not scheduled')];
            }

            if ($sessions->contains(fn (TimeEntry $e) => $e->clock_out_at === null) && $past) {
                $flags[] = ['key' => 'open', 'label' => __('Clock-out missing')];
            }

            $lastShift = $shifts->last();
            $lastOut = $sessions->last()?->clock_out_at;

            if ($lastShift && $lastOut && $lastOut->lt($lastShift->ends_at->subMinutes($tolerance))) {
                $flags[] = ['key' => 'early', 'label' => __('Left :min min early', ['min' => (int) $lastOut->diffInMinutes($lastShift->ends_at)])];
            }

            $variance = ($past || $sessions->isNotEmpty()) ? $worked - $expected : 0;

            $days[] = [
                'date' => $date,
                'shifts' => $shifts->map(fn (ShiftAssignment $a) => $a->toCard())->values()->all(),
                'entries' => $sessions->map(fn (TimeEntry $e) => [
                    'id' => $e->id,
                    'clockIn' => $e->clock_in_at->format('Y-m-d H:i'),
                    'clockOut' => $e->clock_out_at?->format('Y-m-d H:i'),
                    'breakMinutes' => $e->breakMinutes($now),
                    'workedMinutes' => $e->workedMinutes($now),
                    'source' => $e->source,
                ])->values()->all(),
                'leaveMinutes' => $leaveMinutes,
                'scheduledMinutes' => $scheduled,
                'expectedMinutes' => $expected,
                'workedMinutes' => $worked,
                'breakMinutes' => $breaks,
                'varianceMinutes' => $variance,
                'flags' => $flags,
                'pendingAdjustments' => $pending->get($date, collect())->count(),
            ];

            $totals['scheduled'] += $scheduled;
            $totals['worked'] += $worked;
            $totals['breaks'] += $breaks;
            $totals['leave'] += $leaveMinutes;
            $totals['variance'] += $variance;
        }

        $approval = TimesheetApproval::query()->with('approver:id,name')
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->whereDate('week_start', $weekStart->toDateString())->first();

        return [
            'days' => $days,
            'totals' => $totals,
            'approval' => $approval ? ['by' => $approval->approver?->name, 'at' => $approval->approved_at->toIso8601String()] : null,
        ];
    }

    /**
     * One line per agent for a week (supervisor view and payroll export).
     *
     * @return list<array<string, mixed>>
     */
    /** @param  list<int>|null  $only  limit to these user ids (null = everyone) */
    public function teamWeek(Team $team, CarbonImmutable $weekStart, ?array $only = null): array
    {
        return $this->planner->agents($team)
            ->when($only !== null, fn ($agents) => $agents->whereIn('id', $only))
            ->map(function (User $user) use ($team, $weekStart) {
                $sheet = $this->sheet($team, $user, $weekStart);

                return [
                    'user' => ['id' => $user->id, 'name' => $user->name],
                    'totals' => $sheet['totals'],
                    'approval' => $sheet['approval'],
                    'days' => $sheet['days'],
                ];
            })
            // People with nothing that week are left out.
            ->filter(fn (array $row) => $row['totals']['scheduled'] > 0 || $row['totals']['worked'] > 0 || $row['totals']['leave'] > 0)
            ->values()
            ->all();
    }

    /** Minutes of approved leave that fall on the scheduled shifts of a day (or a full day without shift). */
    private function leaveMinutes(Collection $leaves, Collection $shifts, CarbonImmutable $day): int
    {
        $minutes = 0;

        foreach ($leaves as $leave) {
            [$off, $back] = $leave->period();

            foreach ($shifts as $shift) {
                $start = $shift->starts_at->max($off);
                $end = $shift->ends_at->min($back);
                $minutes += $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
            }
        }

        return $minutes;
    }

    /* ---------------------------------------------------------------
     | Adjustments
     * ------------------------------------------------------------- */

    public function requestAdjustment(Team $team, User $user, ?TimeEntry $entry, array $data): TimeAdjustment
    {
        if ($entry !== null) {
            abort_unless($entry->team_id === $team->id && $entry->user_id === $user->id, 403);
        }

        $date = CarbonImmutable::parse($data['date'], 'UTC');
        $in = CarbonImmutable::parse($data['date'].' '.$data['clock_in'], 'UTC');
        $out = CarbonImmutable::parse($data['date'].' '.$data['clock_out'], 'UTC');

        // 16:00 → 00:30 ends the next day.
        if ($out->lte($in)) {
            $out = $out->addDay();
        }

        if ($in->diffInHours($out) > 24) {
            throw ValidationException::withMessages(['clock_out' => __('A session cannot be longer than 24 hours.')]);
        }

        if ($in->gt(Workforce::now())) {
            throw ValidationException::withMessages(['clock_in' => __('A correction cannot be in the future.')]);
        }

        $this->ensureWeekOpen($team, $user, $date);

        $adjustment = TimeAdjustment::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'time_entry_id' => $entry?->id,
            'date' => $date->toDateString(),
            'clock_in_at' => $in,
            'clock_out_at' => $out,
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'reason' => $data['reason'],
            'status' => TimeAdjustment::PENDING,
        ]);

        $this->planner->safely(fn () => Notification::send($this->planner->reviewersFor($team, $user), new WorkforceNotification(
            'timesheet.adjustment',
            'Timesheet correction to review',
            ':name asks to correct their time of :date: :from–:to.',
            route('workforce.requests', ['current_team' => $team->slug]),
            ['name' => $user->name, 'date' => $date->format('d/m/Y'), 'from' => $in->format('H:i'), 'to' => $out->format('H:i')],
            'Review',
        )));

        return $adjustment;
    }

    public function reviewAdjustment(Team $team, TimeAdjustment $adjustment, User $supervisor, bool $approve, ?string $note): TimeAdjustment
    {
        abort_unless($adjustment->team_id === $team->id && $this->planner->canManage($team, $supervisor, $adjustment->user_id), 403);

        if ($adjustment->status !== TimeAdjustment::PENDING) {
            throw ValidationException::withMessages(['adjustment' => __('This correction was already reviewed.')]);
        }

        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['review_note' => __('Give the reason of the refusal.')]);
        }

        DB::transaction(function () use ($team, $adjustment, $supervisor, $approve, $note): void {
            $previous = null;

            if ($approve) {
                $entry = $adjustment->time_entry_id ? TimeEntry::query()->with('breaks')->find($adjustment->time_entry_id) : null;

                if ($entry !== null) {
                    $previous = [
                        'clock_in' => $entry->clock_in_at->format('Y-m-d H:i'),
                        'clock_out' => $entry->clock_out_at?->format('Y-m-d H:i'),
                        'break_minutes' => $entry->breakMinutes(),
                    ];
                } else {
                    $entry = new TimeEntry(['team_id' => $team->id, 'user_id' => $adjustment->user_id]);
                }

                $entry->fill([
                    'date' => $adjustment->date->toDateString(),
                    'clock_in_at' => $adjustment->clock_in_at,
                    'clock_out_at' => $adjustment->clock_out_at,
                    'source' => 'manual',
                    'shift_assignment_id' => $entry->shift_assignment_id ?? $this->currentAssignment($team, $adjustment->user, $adjustment->clock_in_at)?->id,
                ])->save();

                // The approved break replaces the clocked ones (kept in `previous`).
                $entry->breaks()->delete();

                if ($adjustment->break_minutes > 0) {
                    $start = $adjustment->clock_in_at->addMinutes((int) max(0, ($adjustment->clock_in_at->diffInMinutes($adjustment->clock_out_at) - $adjustment->break_minutes) / 2));
                    $entry->breaks()->create(['started_at' => $start, 'ended_at' => $start->addMinutes($adjustment->break_minutes)]);
                }

                $adjustment->time_entry_id = $entry->id;
            }

            $adjustment->fill([
                'status' => $approve ? TimeAdjustment::APPROVED : TimeAdjustment::REJECTED,
                'previous' => $previous,
                'reviewed_by' => $supervisor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
        });

        $this->planner->safely(fn () => $adjustment->user->notify(new WorkforceNotification(
            $approve ? 'timesheet.approved' : 'timesheet.rejected',
            $approve ? 'Time correction approved' : 'Time correction refused',
            $approve ? 'Your correction of :date is applied to your timesheet.' : 'Your correction of :date was refused: :note',
            route('workforce.timesheets', ['current_team' => $team->slug, 'week' => Workforce::weekStart($adjustment->date)->toDateString()]),
            ['date' => $adjustment->date->format('d/m/Y'), 'note' => (string) $note],
            'See my timesheet',
        )));

        return $adjustment;
    }

    /* ---------------------------------------------------------------
     | Weekly validation
     * ------------------------------------------------------------- */

    public function approveWeek(Team $team, User $agent, CarbonImmutable $weekStart, User $supervisor): void
    {
        $open = TimeEntry::query()->where('team_id', $team->id)->where('user_id', $agent->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->whereNull('clock_out_at')->exists();

        if ($open) {
            throw ValidationException::withMessages(['week' => __('A session of this week has no clock-out yet.')]);
        }

        TimesheetApproval::query()->updateOrCreate(
            ['team_id' => $team->id, 'user_id' => $agent->id, 'week_start' => $weekStart->toDateString()],
            ['approved_by' => $supervisor->id, 'approved_at' => now()],
        );
    }

    public function reopenWeek(Team $team, User $agent, CarbonImmutable $weekStart): void
    {
        TimesheetApproval::query()
            ->where('team_id', $team->id)->where('user_id', $agent->id)
            ->whereDate('week_start', $weekStart->toDateString())->delete();
    }

    public function ensureWeekOpen(Team $team, User $user, CarbonImmutable $date): void
    {
        $validated = TimesheetApproval::query()
            ->where('team_id', $team->id)->where('user_id', $user->id)
            ->whereDate('week_start', Workforce::weekStart($date)->toDateString())->exists();

        if ($validated) {
            throw ValidationException::withMessages(['week' => __('This week is validated: ask a supervisor to reopen it.')]);
        }
    }

    /* ---------------------------------------------------------------
     | Export
     * ------------------------------------------------------------- */

    /**
     * Payroll lines: one per agent and day with something scheduled, worked or on leave.
     *
     * @return array{0: list<string>, 1: list<list<string|int|float>>}
     */
    /** @param  list<int>|null  $among  team export limited to these user ids */
    public function exportRows(Team $team, CarbonImmutable $weekStart, ?User $only = null, ?array $among = null): array
    {
        $header = [
            __('Agent'), __('Date'), __('Scheduled shifts'), __('Clock in'), __('Clock out'),
            __('Scheduled (h)'), __('Leave (h)'), __('Breaks (h)'), __('Worked (h)'), __('Variance (h)'), __('Notes'), __('Validated'),
        ];
        $rows = [];
        $lines = $only !== null
            ? [['user' => ['name' => $only->name], ...$this->sheet($team, $only, $weekStart)]]
            : $this->teamWeek($team, $weekStart, $among);

        foreach ($lines as $line) {
            foreach ($line['days'] as $day) {
                if ($day['scheduledMinutes'] === 0 && $day['workedMinutes'] === 0 && $day['leaveMinutes'] === 0) {
                    continue;
                }

                $rows[] = [
                    $line['user']['name'],
                    $day['date'],
                    collect($day['shifts'])->map(fn (array $s) => $s['code'].' '.$s['window'])->implode(', '),
                    collect($day['entries'])->pluck('clockIn')->map(fn ($v) => substr((string) $v, 11))->first() ?? '',
                    collect($day['entries'])->pluck('clockOut')->filter()->map(fn ($v) => substr((string) $v, 11))->last() ?? '',
                    round($day['scheduledMinutes'] / 60, 2),
                    round($day['leaveMinutes'] / 60, 2),
                    round($day['breakMinutes'] / 60, 2),
                    round($day['workedMinutes'] / 60, 2),
                    round($day['varianceMinutes'] / 60, 2),
                    collect($day['flags'])->pluck('label')->implode(' · '),
                    $line['approval'] ? __('Yes') : __('No'),
                ];
            }
        }

        return [$header, $rows];
    }
}
