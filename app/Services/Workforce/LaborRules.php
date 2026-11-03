<?php

namespace App\Services\Workforce;

use App\Models\LeaveRequest;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkforceSetting;
use Carbon\CarbonImmutable;

/**
 * Checks an agent's schedule once some shifts are added (and others
 * removed): no overlap, no stretch of back-to-back shifts longer than
 * max_consecutive_hours, enough rest between stretches (more after a
 * double shift), weekly cap, not on approved leave.
 *
 * Only problems involving the new shifts are reported: an existing
 * violation elsewhere in the schedule never blocks an unrelated change.
 */
class LaborRules
{
    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $adding  periods to add for this agent
     * @param  list<int>  $removing  assignment ids that go away in the same change
     * @return array{blocking: list<string>, warnings: list<string>}
     *                                                               blocking: always refused (overlap, leave);
     *                                                               warnings: refused only when the team enforces its rules
     */
    public function check(Team $team, User $user, array $adding, array $removing = []): array
    {
        if ($adding === []) {
            return ['blocking' => [], 'warnings' => []];
        }

        $settings = $team->workforceSetting ?? new WorkforceSetting;
        $from = collect($adding)->min(fn (array $p) => $p[0])->subDays(8);
        $to = collect($adding)->max(fn (array $p) => $p[1])->addDays(8);

        $existing = ShiftAssignment::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->whereNotIn('id', $removing)
            ->where('ends_at', '>', $from)
            ->where('starts_at', '<', $to)
            ->get(['id', 'starts_at', 'ends_at']);

        /** @var list<array{start: CarbonImmutable, end: CarbonImmutable, new: bool}> $intervals */
        $intervals = [];

        foreach ($existing as $assignment) {
            $intervals[] = ['start' => $assignment->starts_at, 'end' => $assignment->ends_at, 'new' => false];
        }

        foreach ($adding as [$start, $end]) {
            $intervals[] = ['start' => $start, 'end' => $end, 'new' => true];
        }

        usort($intervals, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        $blocking = [];
        $warnings = [];

        // Overlap: two shifts at the same time (always refused).
        for ($i = 1, $n = count($intervals); $i < $n; $i++) {
            $previous = $intervals[$i - 1];
            $current = $intervals[$i];

            if (($previous['new'] || $current['new']) && $current['start']->lt($previous['end'])) {
                $blocking[] = __('Overlaps another shift on :date (:from–:to).', [
                    'date' => $this->day($current['start']),
                    'from' => $previous['start']->format('H:i'),
                    'to' => $previous['end']->format('H:i'),
                ]);
            }
        }

        // Approved leave (always refused).
        foreach ($adding as [$start, $end]) {
            $leave = LeaveRequest::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->where('status', LeaveRequest::APPROVED)
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->get()
                ->first(function (LeaveRequest $leave) use ($start, $end): bool {
                    [$off, $back] = $leave->period();

                    return $start->lt($back) && $end->gt($off);
                });

            if ($leave !== null) {
                $blocking[] = __(':name is on approved leave on :date.', ['name' => $user->name, 'date' => $this->day($start)]);
            }
        }

        if ($blocking !== []) {
            return ['blocking' => array_values(array_unique($blocking)), 'warnings' => []];
        }

        // Stretches of back-to-back shifts.
        $blocks = [];

        foreach ($intervals as $interval) {
            $last = $blocks === [] ? null : $blocks[array_key_last($blocks)];

            if ($last !== null && $interval['start']->lte($last['end'])) {
                $blocks[array_key_last($blocks)]['end'] = $interval['end']->max($last['end']);
                $blocks[array_key_last($blocks)]['new'] = $last['new'] || $interval['new'];
            } else {
                $blocks[] = $interval;
            }
        }

        $maxHours = (int) $settings->rule('max_consecutive_hours');
        $minRest = (int) $settings->rule('min_rest_hours');
        $minRestLong = (int) $settings->rule('min_rest_after_long_hours');
        $singleShift = (float) config('workforce.hours_per_day', 8);

        foreach ($blocks as $index => $block) {
            $hours = $block['start']->diffInMinutes($block['end']) / 60;

            if ($block['new'] && $maxHours > 0 && $hours > $maxHours) {
                $warnings[] = __(':hours h of work in a row on :date (maximum :max h).', [
                    'hours' => $this->number($hours), 'date' => $this->day($block['start']), 'max' => $maxHours,
                ]);
            }

            $next = $blocks[$index + 1] ?? null;

            if ($next === null || ! ($block['new'] || $next['new'])) {
                continue;
            }

            $rest = $block['end']->diffInMinutes($next['start']) / 60;
            $required = $hours > $singleShift ? max($minRest, $minRestLong) : $minRest;

            if ($required > 0 && $rest < $required) {
                $warnings[] = __('Only :rest h of rest before :date (minimum :min h).', [
                    'rest' => $this->number($rest), 'date' => $this->day($next['start']), 'min' => $required,
                ]);
            }
        }

        // Weekly cap, for the weeks the new shifts fall in.
        $maxWeekly = (int) $settings->rule('max_weekly_hours');

        if ($maxWeekly > 0) {
            $weeks = collect($adding)->map(fn (array $p) => $p[0]->startOfWeek(CarbonImmutable::MONDAY)->toDateString())->unique();

            foreach ($weeks as $week) {
                $weekStart = CarbonImmutable::parse($week, 'UTC');
                $weekEnd = $weekStart->addWeek();
                $minutes = 0;

                foreach ($intervals as $interval) {
                    $start = $interval['start']->max($weekStart);
                    $end = $interval['end']->min($weekEnd);
                    $minutes += $end->gt($start) ? $start->diffInMinutes($end) : 0;
                }

                if ($minutes > $maxWeekly * 60) {
                    $warnings[] = __(':hours h scheduled in the week of :date (maximum :max h).', [
                        'hours' => $this->number($minutes / 60), 'date' => $this->day($weekStart), 'max' => $maxWeekly,
                    ]);
                }
            }
        }

        return ['blocking' => [], 'warnings' => array_values(array_unique($warnings))];
    }

    private function day(CarbonImmutable $date): string
    {
        return $date->locale(app()->getLocale())->translatedFormat('D j M');
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
