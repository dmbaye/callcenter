<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\AgentStates;
use App\Services\AsteriskAmi;
use App\Services\AsteriskQueueMembers;
use App\Services\CallStats;
use App\Services\TeamAgents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SupervisorMonitoringController extends Controller
{
    public function __construct(
        private AsteriskQueueMembers $members,
        private AsteriskAmi $ami,
        private CallStats $callStats,
        private TeamAgents $teamAgents,
        private AgentStates $agentStates,
    ) {}

    public function index(Team $currentTeam): Response
    {
        Gate::authorize('monitorAgents', $currentTeam);

        $snapshot = $this->snapshot($currentTeam);

        return Inertia::render('monitoring/Index', [
            ...$snapshot,
            'amiAvailable' => $snapshot['live'],
        ]);
    }

    public function status(Team $currentTeam): JsonResponse
    {
        Gate::authorize('monitorAgents', $currentTeam);

        $snapshot = $this->snapshot($currentTeam);

        return response()->json($snapshot, $snapshot['live'] ? 200 : 503);
    }

    public function listen(Request $request, Team $currentTeam): JsonResponse
    {
        $this->startMonitoring($request, $currentTeam, whisper: false);

        return response()->json(['message' => __('Your phone is being connected to the agent call. Answer the incoming call to listen.')]);
    }

    public function whisper(Request $request, Team $currentTeam): JsonResponse
    {
        $this->startMonitoring($request, $currentTeam, whisper: true);

        return response()->json(['message' => __('Your phone is being connected in whisper mode. Answer the incoming call to speak privately to the agent.')]);
    }

    /**
     * Everything the monitoring screen shows, from one consistent read:
     * agents (free / ringing / in call / paused / offline), callers waiting
     * in the team's queues and per-queue counters.
     *
     * @return array{live: bool, agents: list<array<string, mixed>>, agentCallDetails: list<array<string, mixed>>, waiting: list<array<string, mixed>>, queues: list<array<string, mixed>>, summary: array<string, int>}
     */
    private function snapshot(Team $team): array
    {
        $agents = $this->teamAgents->for($team);
        $teamQueues = $team->callQueues()->orderBy('name')->get(['name', 'asterisk_name']);
        $queueLabels = $teamQueues->pluck('name', 'asterisk_name')->all();
        $queueNames = $teamQueues->pluck('asterisk_name')->all();

        try {
            $ami = $this->ami->snapshot();
            $updatedAt = (int) ($ami['queues']['updated_at'] ?? 0);
            // The daemon keeps serving its last snapshot while AMI is down: only a fresh one is live.
            $live = $updatedAt > 0
                && now()->getTimestamp() - $updatedAt <= max(5, (int) config('asterisk.ami.snapshot_interval', 2) * 3);
            $channels = $ami['channels'] ?? [];
            $visible = array_fill_keys($queueNames, true);
            $queueState = [
                'entries' => array_values(array_filter(
                    $ami['queues']['entries'] ?? [],
                    fn (array $entry) => isset($visible[$entry['Queue'] ?? '']),
                )),
                'queues' => array_intersect_key($ami['queues']['queues'] ?? [], $visible),
            ];
        } catch (RuntimeException $exception) {
            report($exception);
            $live = false;
            $channels = [];
            $queueState = ['entries' => [], 'queues' => []];
        }

        if (! $live) {
            // Stale or missing call state must not be shown as current.
            $channels = [];
            $queueState = ['entries' => [], 'queues' => []];
        }

        $channels = collect($channels)
            ->filter(fn (array $channel) => isset($channel['Channel']))
            ->groupBy(fn (array $channel) => $this->extensionFromChannel($channel['Channel'], $team->asterisk_key));

        $presence = $this->members->statusesFor($agents, $team);
        $chosen = $this->agentStates->forUsers($agents, $team);

        $agentRows = $agents->map(function (User $agent) use ($channels, $presence, $chosen, $team) {
            $extension = $agent->phoneExtensionFor($team)->extension;
            $own = $channels->get($extension, collect());
            $up = $own->first(fn (array $channel) => ($channel['ChannelStateDesc'] ?? '') === 'Up');
            $ringing = $up === null && $own->contains(
                fn (array $channel) => in_array($channel['ChannelStateDesc'] ?? '', ['Ring', 'Ringing'], true),
            );
            $queues = $agent->queueNames($team);

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'extension' => $extension,
                'queue' => $queues[0] ?? '',
                'queues' => $queues,
                'status' => match (true) {
                    $up !== null => 'busy',
                    $ringing => 'ringing',
                    default => $presence[$agent->id] ?? AsteriskQueueMembers::OFFLINE,
                },
                // What the agent chose in the status menu (Non prêt – Déjeuner, Post-appel…) and since when.
                'state' => $chosen[$agent->id]->state->value,
                'stateLabel' => $chosen[$agent->id]->label(),
                'stateSince' => $chosen[$agent->id]->changed_at?->toIso8601String(),
                'call' => $up !== null ? [
                    'channel' => $up['Channel'] ?? null,
                    'state' => $up['ChannelStateDesc'] ?? null,
                    'caller' => $up['CallerIDNum'] ?? null,
                    'duration' => $this->seconds($up['Duration'] ?? null),
                ] : null,
            ];
        })->values();

        $waiting = collect($queueState['entries'])
            ->map(fn (array $entry) => [
                'queue' => $queueLabels[$entry['Queue'] ?? ''] ?? ($entry['Queue'] ?? ''),
                'position' => (int) ($entry['Position'] ?? 0),
                'caller' => $entry['CallerIDNum'] ?? '',
                'name' => $entry['CallerIDName'] ?? '',
                'wait' => (int) ($entry['Wait'] ?? 0),
            ])
            ->sortBy([['queue', 'asc'], ['position', 'asc']])
            ->values();

        $agentsByQueue = $agentRows
            ->flatMap(fn (array $agent) => collect($agent['queues'])->map(fn (string $queue) => ['queue' => $queue, 'agent' => $agent]))
            ->groupBy('queue')
            ->map(fn ($rows) => $rows->pluck('agent')->unique('id')->values());
        $waitingByQueue = $waiting->groupBy('queue');

        $trackServiceLevel = (bool) $team->service_level_enabled;

        $queues = collect($queueNames)->map(function (string $name) use ($agentsByQueue, $waitingByQueue, $queueState, $queueLabels, $trackServiceLevel) {
            $label = $queueLabels[$name] ?? $name;
            $mine = $agentsByQueue->get($label, collect());
            $queued = $waitingByQueue->get($label, collect());
            $params = $queueState['queues'][$name] ?? [];

            return [
                'name' => $label,
                'asterisk_name' => $name,
                'waiting' => $queued->count(),
                'longest_wait' => (int) $queued->max('wait'),
                'free' => $mine->where('status', AsteriskQueueMembers::AVAILABLE)->count(),
                'in_call' => $mine->where('status', 'busy')->count(),
                'paused' => $mine->where('status', AsteriskQueueMembers::PAUSED)->count(),
                'offline' => $mine->where('status', AsteriskQueueMembers::OFFLINE)->count(),
                'completed' => (int) ($params['Completed'] ?? 0),
                'abandoned' => (int) ($params['Abandoned'] ?? 0),
                // Asterisk: % of answered calls picked up within ServiceLevel seconds
                // (counters since the queue was loaded).
                'service_level' => $trackServiceLevel && isset($params['ServicelevelPerf']) ? [
                    'percent' => round((float) $params['ServicelevelPerf'], 1),
                    'threshold' => (int) ($params['ServiceLevel'] ?? 0),
                ] : null,
            ];
        })->values()->all();

        // Whole team: queues weighted by their answered calls.
        $serviceLevel = null;

        if ($trackServiceLevel) {
            $measured = collect($queues)->filter(fn (array $q) => $q['service_level'] !== null && $q['completed'] > 0);
            $answered = $measured->sum('completed');

            $serviceLevel = [
                'target' => (int) $team->service_level_target,
                'percent' => $answered > 0
                    ? round($measured->sum(fn (array $q) => $q['service_level']['percent'] * $q['completed']) / $answered, 1)
                    : null,
            ];
        }

        $statusCounts = $agentRows->countBy('status');
        $count = fn (string $status) => (int) ($statusCounts[$status] ?? 0);

        $agentCallDetails = [];

        try {
            $todayLegs = $this->callStats->legs($team, now()->startOfDay(), now()->endOfDay());
            $agentCallDetails = $this->callStats->perAgentDetails($todayLegs, $agentRows->map(fn (array $agent) => [
                'id' => $agent['id'],
                'name' => $agent['name'],
                'extension' => $agent['extension'],
            ])->all());
        } catch (\Throwable $exception) {
            report($exception);
        }

        return [
            'live' => $live,
            'agents' => $agentRows->all(),
            'agentCallDetails' => $agentCallDetails,
            'waiting' => $waiting->all(),
            'queues' => $queues,
            'serviceLevel' => $serviceLevel,
            'summary' => [
                'agents' => $agentRows->count(),
                'waiting' => $waiting->count(),
                'longest_wait' => (int) $waiting->max('wait'),
                'free' => $count(AsteriskQueueMembers::AVAILABLE),
                'in_call' => $count('busy'),
                'ringing' => $count('ringing'),
                'paused' => $count(AsteriskQueueMembers::PAUSED),
                'offline' => $count(AsteriskQueueMembers::OFFLINE),
            ],
        ];
    }

    /**
     * "HH:MM:SS" (as reported by CoreShowChannels) to seconds.
     */
    private function seconds(?string $duration): ?int
    {
        if ($duration === null || ! preg_match('/^(\d+):(\d{2}):(\d{2})$/', $duration, $m)) {
            return null;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    private function startMonitoring(Request $request, Team $team, bool $whisper): void
    {
        Gate::authorize('monitorAgents', $team);

        $validated = $request->validate(['agent' => ['required', 'integer']]);
        $supervisor = $request->user();
        $agent = $this->teamAgents->for($team)->firstWhere('id', (int) $validated['agent']);

        abort_if($agent === null, 404);
        abort_if($supervisor->is($agent), 422, __('You cannot monitor your own call.'));

        $supervisorExtension = $supervisor->phoneExtensionFor($team);
        abort_if($supervisorExtension === null, 422, __('A SIP extension is missing for this team.'));

        try {
            $this->ami->startSpy($supervisorExtension->extension, $agent->phoneExtensionFor($team)->extension, $whisper, $team->asterisk_key);
        } catch (RuntimeException $exception) {
            report($exception);
            abort(422, $exception->getMessage());
        }
    }

    private function extensionFromChannel(string $channel, string $asteriskKey): ?string
    {
        if (preg_match('#^PJSIP/'.preg_quote($asteriskKey, '#').'_(\d{2,20})-#', $channel, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
