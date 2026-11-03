<?php

namespace App\Http\Controllers;

use App\Enums\AgentState;
use App\Enums\ContactStatus;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\AgentStateLog;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\AsteriskQueueMembers;
use App\Services\CallStats;
use App\Services\TeamAgents;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role-based dashboards. Every number comes from CallStats over the same
 * CDR legs (both directions, counted per call), so an agent's own figures
 * match the admin and supervisor rosters.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Team $currentTeam, AsteriskQueueMembers $members, CallStats $stats, TeamAgents $agents): Response
    {
        $user = $request->user();
        $role = $user->teamRole($currentTeam);
        $shared = ['pendingInvitations' => $this->pendingInvitations($user)];
        $wantsPersonal = $request->query('view') === 'agent';

        // Internal calls and meetings only: a directory, not call-center figures.
        if ($currentTeam->isInternalOnly()) {
            return $this->internal($user, $currentTeam, $shared);
        }

        if (! $wantsPersonal && $role?->isAtLeast(TeamRole::Admin)) {
            return $this->admin($currentTeam, $members, $stats, $agents, $shared);
        }

        if (! $wantsPersonal && $role === TeamRole::Supervisor) {
            return $this->supervisor($currentTeam, $members, $stats, $agents, $shared);
        }

        return $this->agent($user, $currentTeam, $stats, $shared + [
            'teamViewAvailable' => (bool) $role?->hasPermission(TeamPermission::MonitorAgents),
        ]);
    }

    /** @param  array<string, mixed>  $shared */
    private function admin(Team $team, AsteriskQueueMembers $members, CallStats $stats, TeamAgents $agents, array $shared): Response
    {
        $agentMembers = $agents->members($team);
        $withExtension = $agentMembers->filter(fn (User $user) => $user->phoneExtensionFor($team) !== null);

        $weekLegs = $stats->legs($team, now()->subDays(6)->startOfDay(), now()->endOfDay());
        $todayStart = now()->startOfDay()->toDateTimeString();
        $todayLegs = $weekLegs->filter(fn (array $leg) => $leg['start'] >= $todayStart)->values();

        $memberships = $team->memberships()->get(['id', 'role']);

        return Inertia::render('dashboard/Admin', $shared + [
            'today' => $stats->summary($todayLegs),
            'week' => $stats->summary($weekLegs),
            'daily' => $stats->daily($weekLegs, 7),
            'roster' => $this->roster($team, $withExtension, $todayLegs, $members, $stats),
            'roles' => collect(TeamRole::cases())->mapWithKeys(fn (TeamRole $r) => [
                $r->value => $memberships->where('role', $r)->count(),
            ])->all(),
            'setup' => [
                'members' => $memberships->count(),
                'without_extension' => $agentMembers->diff($withExtension)->pluck('name')->values()->all(),
                'without_queue' => $withExtension
                    ->filter(fn (User $user) => $user->teamQueues($team)->isEmpty())
                    ->pluck('name')->values()->all(),
            ],
            'queues' => $team->callQueues()->orderBy('name')->pluck('name')->values()->all(),
            'csat' => $this->csat($team),
            'callbacksWaiting' => CampaignContact::query()
                ->whereIn('campaign_id', $team->campaigns()->where('kind', Campaign::KIND_CALLBACK)->select('id'))
                ->whereIn('status', [ContactStatus::Pending->value, ContactStatus::Reserved->value, ContactStatus::Callback->value])
                ->count(),
        ]);
    }

    /** @param  array<string, mixed>  $shared */
    private function supervisor(Team $team, AsteriskQueueMembers $members, CallStats $stats, TeamAgents $agents, array $shared): Response
    {
        $todayLegs = $stats->legs($team, now()->startOfDay(), now()->endOfDay());

        return Inertia::render('dashboard/Supervisor', $shared + [
            'today' => $stats->summary($todayLegs),
            'roster' => $this->roster($team, $agents->for($team), $todayLegs, $members, $stats),
            'missedList' => $stats->missedCalls($todayLegs, 10),
        ]);
    }

    /** @param  array<string, mixed>  $shared */
    private function agent(User $user, Team $team, CallStats $stats, array $shared): Response
    {
        $extension = $user->phoneExtensionFor($team)?->extension;
        $legs = $extension !== null
            ? $stats->legs($team, now()->startOfDay(), now()->endOfDay(), $extension)
            : collect();
        $summary = $stats->summary($legs);
        $inbound = $legs->where('direction', 'inbound')->values();

        return Inertia::render('dashboard/Agent', $shared + [
            'totalCalls' => $summary['total'],
            'answeredCalls' => $summary['answered'],
            // Unanswered calls the agent placed are not "missed": only inbound ones need a call back.
            'missedCalls' => $stats->summary($inbound)['missed'],
            'avgTalkSeconds' => $summary['avg_talk_seconds'],
            'recentCalls' => $stats->recentCalls($inbound, 6),
            'missedList' => $stats->missedCalls($inbound, 10),
            'talkSeconds' => (int) $legs->where('answered', true)->sum('billsec'),
            'hasExtension' => $extension !== null,
            'myDay' => $this->stateTotals($user, $team),
            'meetings' => $this->todaysMeetings($user, $team),
        ]);
    }

    /**
     * Time spent in each status since midnight, longest first.
     *
     * @return list<array{state: string, label: string, seconds: int}>
     */
    private function stateTotals(User $user, Team $team): array
    {
        $dayStart = now()->startOfDay();
        $now = now();

        return AgentStateLog::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('started_at', '<', $now)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $dayStart))
            ->get()
            ->groupBy(fn (AgentStateLog $log) => $log->state->value)
            ->map(fn (Collection $logs, string $state) => [
                'state' => $state,
                'label' => AgentState::from($state)->label(),
                'seconds' => (int) $logs->sum(fn (AgentStateLog $log) => max(0, (int) $log->started_at->max($dayStart)
                    ->diffInSeconds(($log->ended_at ?? $now)->min($now)))),
            ])
            ->filter(fn (array $row) => $row['seconds'] > 0)
            ->sortByDesc('seconds')
            ->values()
            ->all();
    }

    /**
     * Internal-only organizations: colleagues to call (extension to
     * extension) and today's meetings.
     *
     * @param  array<string, mixed>  $shared
     */
    private function internal(User $user, Team $team, array $shared): Response
    {
        $members = $team->members()
            ->with(['phoneExtensions' => fn ($query) => $query->where('team_id', $team->id)])
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'users.avatar_path', 'users.job_title', 'users.department', 'users.phone']);

        return Inertia::render('dashboard/Internal', $shared + [
            'me' => [
                'extension' => $user->phoneExtensionFor($team)?->extension,
            ],
            'colleagues' => $members
                ->reject(fn (User $member) => $member->is($user))
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar,
                    'jobTitle' => $member->job_title,
                    'department' => $member->department,
                    'role' => $member->pivot->role instanceof TeamRole ? $member->pivot->role->label() : TeamRole::tryFrom((string) $member->pivot->role)?->label(),
                    'extension' => $member->phoneExtensions->first()?->extension,
                ])
                ->values()
                ->all(),
            'meetings' => $this->todaysMeetings($user, $team),
            'canManage' => (bool) $user->teamRole($team)?->isAtLeast(TeamRole::Admin),
        ]);
    }

    /**
     * Today's meetings the user organises or is invited to: live ones first, then planned ones not over yet.
     *
     * @return list<array{uuid: string, title: string, scheduledAt: string|null, durationMinutes: int|null, live: bool, participants: int}>
     */
    private function todaysMeetings(User $user, Team $team): array
    {
        return $team->meetings()
            ->withCount('invitees')
            ->whereNull('ended_at')
            ->whereNull('cancelled_at')
            ->where(fn ($query) => $query->where('created_by', $user->id)
                ->orWhereHas('invitees', fn ($invitee) => $invitee->where('users.id', $user->id)))
            ->where(fn ($query) => $query->whereNotNull('started_at')
                ->orWhereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()]))
            ->orderByRaw('started_at is null')
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get()
            ->filter(fn (Meeting $meeting) => $meeting->isLive() || $meeting->plannedEnd()?->isFuture())
            ->take(4)
            ->map(fn (Meeting $meeting) => [
                'uuid' => $meeting->uuid,
                'title' => $meeting->title,
                'scheduledAt' => $meeting->scheduled_at?->toIso8601String(),
                'durationMinutes' => $meeting->duration_minutes,
                'live' => $meeting->isLive(),
                'participants' => $meeting->invitees_count + 1,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  iterable<User>  $users  agents loaded by TeamAgents (pivot role, extension, queues)
     * @param  Collection<int, array<string, mixed>>  $legs
     * @return list<array<string, mixed>>
     */
    private function roster(Team $team, iterable $users, Collection $legs, AsteriskQueueMembers $members, CallStats $stats): array
    {
        $users = collect($users)->values();
        $presence = $members->statusesFor($users, $team);

        $rows = $users->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'extension' => $user->phoneExtensionFor($team)->extension,
            'role' => $user->pivot->role instanceof TeamRole ? $user->pivot->role->value : (string) $user->pivot->role,
        ])->all();

        return array_map(fn (array $row) => [
            ...$row,
            'presence' => $presence[$row['id']] ?? AsteriskQueueMembers::OFFLINE,
        ], $stats->perAgent($legs, $rows));
    }

    /** @return array{average: float|null, responses: int, satisfied: int} */
    private function csat(Team $team): array
    {
        $row = $team->surveys()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('count(*) as responses, avg(score) as average, sum(case when score >= 4 then 1 else 0 end) as satisfied')
            ->first();

        $responses = (int) ($row->responses ?? 0);

        return [
            'average' => $responses > 0 ? round((float) $row->average, 1) : null,
            'responses' => $responses,
            'satisfied' => $responses > 0 ? (int) round(((int) $row->satisfied) * 100 / $responses) : 0,
        ];
    }

    private function pendingInvitations(User $user)
    {
        return TeamInvitation::query()->with(['inviter:id,name', 'team:id,name,slug'])
            ->where('email', $user->email)->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->latest()->get()->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => ['name' => $invitation->team->name, 'slug' => $invitation->team->slug],
            ]);
    }
}
