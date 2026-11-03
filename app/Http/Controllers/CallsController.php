<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskQueueMembers;
use App\Services\CallRecordings;
use App\Services\PlanLimits;
use App\Services\TeamAgents;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CallsController extends Controller
{
    public function index(Request $request, Team $currentTeam, AsteriskQueueMembers $members, TeamAgents $teamAgents, PlanLimits $limits, CallRecordings $recordings): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:ANSWERED,NO ANSWER,BUSY,FAILED'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'agent' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $team = $currentTeam;

        // Whoever may monitor agents (supervisor and up) sees every agent of the team; agents only themselves.
        $canViewTeam = $user->hasTeamPermission($team, TeamPermission::MonitorAgents);

        $agents = $canViewTeam
            ? $teamAgents->for($team)->map(fn (User $member) => [
                'name' => $member->name,
                'extension' => $member->phoneExtensionFor($team)->extension,
            ])->values()->all()
            : [];

        $extensions = $canViewTeam
            ? array_column($agents, 'extension')
            : array_values(array_filter([$user->phoneExtensionFor($team)?->extension]));

        if ($canViewTeam && ($filters['agent'] ?? null) && in_array($filters['agent'], $extensions, true)) {
            $extensions = [$filters['agent']];
        }

        $names = array_column($agents, 'name', 'extension');

        // Call history retention depends on the plan.
        $historyStart = $limits->historyStart($team);
        $prefix = 'PJSIP/'.$team->asterisk_key.'_';

        $calls = $members->connection()->table('cdr')
            ->where('accountcode', $team->asterisk_key)
            // Only calls that involve one of the visible agents; none visible = no calls.
            ->where(function ($q) use ($extensions, $prefix) {
                if ($extensions === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                foreach ($extensions as $extension) {
                    $q->orWhere('channel', 'like', "{$prefix}{$extension}-%")
                        ->orWhere('dstchannel', 'like', "{$prefix}{$extension}-%");
                }
            })
            ->when($filters['search'] ?? null, function ($q, $v) {
                $like = '%'.strtolower(addcslashes($v, '%_\\')).'%';

                $q->where(fn ($q) => $q
                    ->whereRaw("LOWER(src) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(dst) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->when($historyStart, fn ($q, $v) => $q->where('start', '>=', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('disposition', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('start', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('start', '<', Carbon::parse($v)->addDay()->startOfDay()))
            ->orderByDesc('start')
            ->paginate(15);

        // Recordings of the calls on this page (uploaded by Asterisk after each recorded call).
        $recorded = $recordings->forCdrRows($team, $calls->items());

        $calls = $calls
            ->through(function ($call) use ($extensions, $names, $prefix, $recorded, $team) {
                $legs = $call->channel.' '.$call->dstchannel;
                $agent = collect($extensions)->first(fn ($e) => str_contains($legs, $prefix.$e.'-'));
                $recording = $recorded[(string) $call->uniqueid] ?? null;

                $call->agent = $agent;
                $call->agent_name = $agent ? ($names[$agent] ?? null) : null;
                $call->recording = $recording ? [
                    'url' => route('calls.recordings.show', [$team, $recording]),
                    'duration' => $recording->duration,
                ] : null;

                return $call;
            })
            ->withQueryString();

        return Inertia::render('calls/Index', [
            'calls' => $calls,
            'filters' => $filters,
            'agents' => $agents,
            'canViewTeam' => $canViewTeam,
            'historyMonths' => $limits->limits($team)['history_months'],
            'outcomes' => $user->callOutcomes()
                ->where('team_id', $team->id)
                ->latest('started_at')
                ->paginate(15, pageName: 'outcomes_page')
                ->withQueryString(),
        ]);
    }
}
