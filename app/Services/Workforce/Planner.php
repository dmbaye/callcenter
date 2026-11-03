<?php

namespace App\Services\Workforce;

use App\Enums\TeamRole;
use App\Models\LeaveType;
use App\Models\Membership;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwap;
use App\Models\ShiftTemplate;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkforceSetting;
use App\Notifications\Workforce\WorkforceNotification;
use App\Support\Workforce;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The roster: shift templates, assignments with labor-rule checks,
 * coverage per shift, copying a week, and telling agents.
 */
class Planner
{
    /** @var array<int, array<int, array{role: TeamRole, supervisor_id: int|null}>> */
    private array $memberships = [];

    public function __construct(private LaborRules $rules) {}

    /** Default shifts, leave types and rules the first time a team opens Planning. */
    public function ensureSetup(Team $team): WorkforceSetting
    {
        if (! $team->shiftTemplates()->exists()) {
            foreach ((array) config('workforce.shift_templates') as $position => $template) {
                $team->shiftTemplates()->create([...$template, 'position' => $position]);
            }
        }

        if (! $team->leaveTypes()->exists()) {
            foreach ((array) config('workforce.leave_types') as $position => $type) {
                $team->leaveTypes()->create([...$type, 'position' => $position]);
            }
        }

        $settings = $team->workforceSetting()->firstOrCreate([]);
        $team->setRelation('workforceSetting', $settings);

        return $settings;
    }

    /**
     * Put an agent on a shift. Overlaps and leave are always refused; the
     * other rules too when the team enforces them (else they are kept as
     * warnings on the assignment).
     */
    public function assign(Team $team, User $agent, ShiftTemplate $template, string $date, ?User $actor = null, bool $notify = true): ShiftAssignment
    {
        abort_unless($template->team_id === $team->id, 404);
        $this->ensureMember($team, $agent);

        [$start, $end] = $template->periodOn($date);
        $warnings = $this->guard($team, $agent, [[$start, $end]]);

        $assignment = $team->shiftAssignments()->create([
            'user_id' => $agent->id,
            'shift_template_id' => $template->id,
            'date' => $date,
            'starts_at' => $start,
            'ends_at' => $end,
            'break_minutes' => $template->break_minutes,
            'warnings' => $warnings ?: null,
            'created_by' => $actor?->id,
        ]);

        if ($notify && $this->isSoon($start) && ! $agent->is($actor)) {
            $this->notifyAgent($team, $agent, 'roster.assigned', 'New shift: :shift on :date', 'You are scheduled on :shift (:window) on :date.', $assignment);
        }

        return $assignment->setRelation('template', $template);
    }

    public function unassign(ShiftAssignment $assignment, ?User $actor = null, bool $notify = true): void
    {
        $assignment->loadMissing(['template', 'user', 'team']);

        DB::transaction(function () use ($assignment): void {
            ShiftSwap::query()
                ->whereIn('status', ShiftSwap::OPEN)
                ->where(fn ($q) => $q->where('requester_assignment_id', $assignment->id)->orWhere('counterparty_assignment_id', $assignment->id))
                ->update(['status' => ShiftSwap::CANCELLED, 'review_note' => __('The shift was removed from the roster.')]);

            $assignment->delete();
        });

        if ($notify && $this->isSoon($assignment->starts_at) && ! $assignment->user->is($actor)) {
            $this->notifyAgent($assignment->team, $assignment->user, 'roster.removed', 'Shift removed: :shift on :date', 'Your :shift shift (:window) on :date was removed from the roster.', $assignment);
        }
    }

    /**
     * Run the rules and return the warnings to keep, or refuse.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $adding
     * @param  list<int>  $removing
     * @return list<string>
     */
    public function guard(Team $team, User $agent, array $adding, array $removing = [], string $field = 'shift'): array
    {
        $team->loadMissing('workforceSetting');
        $result = $this->rules->check($team, $agent, $adding, $removing);

        if ($result['blocking'] !== []) {
            throw ValidationException::withMessages([$field => $result['blocking']]);
        }

        if ($result['warnings'] !== [] && ($team->workforceSetting ?? new WorkforceSetting)->blocks()) {
            throw ValidationException::withMessages([$field => $result['warnings']]);
        }

        return $result['warnings'];
    }

    /**
     * Copy the assignments of a week onto another one. What would break a
     * rule or fall on leave is skipped and reported.
     *
     * @return array{copied: int, skipped: list<string>}
     */
    public function copyWeek(Team $team, CarbonImmutable $from, CarbonImmutable $to, User $actor): array
    {
        $days = (int) $from->diffInDays($to);
        $copied = 0;
        $skipped = [];

        $source = $team->shiftAssignments()
            ->with(['template', 'user'])
            ->whereBetween('date', [$from->toDateString(), $from->addDays(6)->toDateString()])
            ->whereNotNull('shift_template_id')
            ->orderBy('starts_at')
            ->get();

        foreach ($source as $assignment) {
            if ($assignment->template === null || ! $assignment->template->active || $assignment->user === null) {
                continue;
            }

            $date = $assignment->date->addDays($days)->toDateString();

            try {
                $this->assign($team, $assignment->user, $assignment->template, $date, $actor, notify: false);
                $copied++;
            } catch (ValidationException $exception) {
                $skipped[] = $assignment->user->name.' — '.$assignment->template->code.' '.$date.' : '.collect($exception->errors())->flatten()->first();
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Agents on each shift of each day, against the minimum staffing.
     *
     * @return array<string, array<int, array{assigned: int, min: int, short: bool}>> [date][template id]
     */
    public function coverage(Team $team, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $templates = $team->shiftTemplates()->where('active', true)->get();
        $counts = $team->shiftAssignments()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('shift_template_id')
            ->selectRaw('date, shift_template_id, count(*) as total')
            ->groupBy('date', 'shift_template_id')
            ->get()
            ->groupBy(fn ($row) => substr((string) $row->date, 0, 10));

        $coverage = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();

            foreach ($templates as $template) {
                $assigned = (int) ($counts->get($date)?->firstWhere('shift_template_id', $template->id)?->total ?? 0);
                $coverage[$date][$template->id] = [
                    'assigned' => $assigned,
                    'min' => $template->min_agents,
                    'short' => $template->min_agents > 0 && $assigned < $template->min_agents,
                ];
            }
        }

        return $coverage;
    }

    /** Tell every agent scheduled that week (the « Prévenir l'équipe » button). */
    public function notifyWeek(Team $team, CarbonImmutable $weekStart): int
    {
        $byAgent = $team->shiftAssignments()
            ->with(['user', 'template'])
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->orderBy('starts_at')
            ->get()
            ->groupBy('user_id');

        foreach ($byAgent as $assignments) {
            $agent = $assignments->first()->user;

            if ($agent === null) {
                continue;
            }

            $this->safely(fn () => $agent->notify(new WorkforceNotification(
                'roster.week',
                'Your schedule for the week of :date',
                ':count shift(s): :list.',
                route('workforce.me', ['current_team' => $team->slug, 'date' => $weekStart->toDateString()]),
                [
                    'date' => $weekStart->locale($agent->locale ?? app()->getLocale())->translatedFormat('j F'),
                    'count' => $assignments->count(),
                    'list' => $assignments->map(fn (ShiftAssignment $a) => $a->date->format('d/m').' '.($a->template?->code ?? '').' '.$a->starts_at->format('H:i').'–'.$a->ends_at->format('H:i'))->implode(', '),
                ],
                'See my schedule',
            )));
        }

        return $byAgent->count();
    }

    /** Supervisors and up of the team: who reviews swaps, leave and adjustments. */
    public function supervisors(Team $team): Collection
    {
        return $team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value, TeamRole::Supervisor->value, TeamRole::Planner->value])
            ->get();
    }

    /**
     * Members who can be put on a shift: everyone in the team, agents first.
     *
     * @return SupportCollection<int, User>
     */
    /**
     * Memberships of a team keyed by user id (role and supervisor), cached
     * for the request.
     *
     * @return array<int, array{role: TeamRole, supervisor_id: int|null}>
     */
    public function memberships(Team $team): array
    {
        return $this->memberships[$team->id] ??= Membership::query()
            ->where('team_id', $team->id)
            ->get(['user_id', 'role', 'supervisor_id'])
            ->mapWithKeys(fn (Membership $m) => [$m->user_id => ['role' => $m->role, 'supervisor_id' => $m->supervisor_id]])
            ->all();
    }

    /** Forget the cached memberships (after an assignment changes). */
    public function forgetMemberships(Team $team): void
    {
        unset($this->memberships[$team->id]);
    }

    /**
     * Whether the viewer reviews this agent's swaps, leave and timesheets:
     * the owner and administrators manage everyone, a supervisor manages
     * the members assigned to them and the members assigned to nobody.
     * Nobody reviews their own requests, except the owner.
     */
    public function canManage(Team $team, User $viewer, User|int $agent): bool
    {
        $agentId = $agent instanceof User ? $agent->id : $agent;
        $members = $this->memberships($team);
        $viewerRole = $members[$viewer->id]['role'] ?? null;
        $member = $members[$agentId] ?? null;

        if ($viewerRole === null || $member === null || ! $viewerRole->canPlan()) {
            return false;
        }

        if ($agentId === $viewer->id) {
            return $viewerRole === TeamRole::Owner;
        }

        if ($viewerRole->managesWorkforce()) {
            return true;
        }

        if ($member['supervisor_id'] !== null) {
            return $member['supervisor_id'] === $viewer->id;
        }

        // Unassigned: any supervisor, but supervisors and planners go to an administrator or a planner.
        return ! $member['role']->canPlan();
    }

    /**
     * User ids the viewer manages (see canManage).
     *
     * @return list<int>
     */
    public function managedIds(Team $team, User $viewer): array
    {
        return array_values(array_filter(
            array_keys($this->memberships($team)),
            fn (int $id) => $this->canManage($team, $viewer, $id),
        ));
    }

    /** The supervisor assigned to a member, if any. */
    public function supervisorOf(Team $team, User|int $agent): ?int
    {
        return $this->memberships($team)[$agent instanceof User ? $agent->id : $agent]['supervisor_id'] ?? null;
    }

    /**
     * Who is told about these agents' requests: their assigned supervisor,
     * or every supervisor who manages them when they have none.
     *
     * @return Collection<int, User>
     */
    public function reviewersFor(Team $team, User ...$agents): Collection
    {
        $supervisors = $this->supervisors($team);
        $ids = [];

        foreach ($agents as $agent) {
            $assigned = $this->supervisorOf($team, $agent);

            $candidates = $assigned !== null && $supervisors->contains('id', $assigned)
                ? [$assigned]
                : $supervisors->filter(fn (User $s) => $this->canManage($team, $s, $agent))->pluck('id')->all();

            foreach ($candidates as $id) {
                $ids[$id] = true;
            }
        }

        foreach ($agents as $agent) {
            unset($ids[$agent->id]);
        }

        return $supervisors->filter(fn (User $s) => isset($ids[$s->id]))->values();
    }

    public function agents(Team $team): SupportCollection
    {
        return $team->members()->orderBy('name')->get(['users.id', 'users.name', 'users.avatar_path', 'users.job_title']);
    }

    public function isSupervisor(User $user, Team $team): bool
    {
        return (bool) $user->teamRole($team)?->canPlan();
    }

    public function notifyAgent(Team $team, User $agent, string $kind, string $title, string $body, ShiftAssignment $assignment): void
    {
        $this->safely(fn () => $agent->notify(new WorkforceNotification(
            $kind,
            $title,
            $body,
            route('workforce.me', ['current_team' => $team->slug, 'date' => $assignment->date->toDateString()]),
            $this->assignmentReplacements($assignment, $agent),
            'See my schedule',
        )));
    }

    /** @return array<string, string> */
    public function assignmentReplacements(ShiftAssignment $assignment, ?User $reader = null): array
    {
        return [
            'shift' => $assignment->template?->name ?? __('Shift'),
            'window' => $assignment->starts_at->format('H:i').'–'.$assignment->ends_at->format('H:i'),
            'date' => $assignment->date->locale($reader?->locale ?? app()->getLocale())->translatedFormat('l j F'),
        ];
    }

    /** Notifications are best effort: Reverb or SMTP down never fails a planning change. */
    public function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Changes in the next 7 days are told right away; later ones with « Prévenir l'équipe ». */
    private function isSoon(CarbonImmutable $start): bool
    {
        return $start->lt(Workforce::now()->addDays(7)) && $start->gt(Workforce::now()->subHours(12));
    }

    private function ensureMember(Team $team, User $agent): void
    {
        if (! $agent->belongsToTeam($team)) {
            throw ValidationException::withMessages(['user_id' => __('This person is not a member of the team.')]);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function leaveTypeCards(Team $team): array
    {
        return $team->leaveTypes()->get()->map(fn (LeaveType $type) => [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->name,
            'paid' => $type->paid,
            'annualDays' => $type->annual_days,
            'active' => $type->active,
        ])->all();
    }
}
