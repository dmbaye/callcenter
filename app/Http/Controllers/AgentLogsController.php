<?php

namespace App\Http\Controllers;

use App\Enums\AgentState;
use App\Models\AgentStateLog;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agent status log for owners, admins and supervisors: every period an
 * agent spent in Prêt, Non prêt (with its reason), Post-appel, Ne pas
 * déranger or Déconnecté, with time-in-state totals per agent and per
 * pause reason over the selected days.
 */
class AgentLogsController extends Controller
{
    /** Longest range the totals are computed over, in days. */
    private const MAX_RANGE_DAYS = 92;

    public function index(Request $request, Team $currentTeam): Response
    {
        Gate::authorize('monitorAgents', $currentTeam);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'agent' => ['nullable', 'integer', Rule::exists('team_members', 'user_id')->where('team_id', $currentTeam->id)],
            'state' => ['nullable', Rule::enum(AgentState::class)],
        ]);

        $today = CarbonImmutable::today();
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->startOfDay() : $today;
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->startOfDay() : $today;

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
            $from = $to->subDays(self::MAX_RANGE_DAYS - 1);
        }

        $start = $from;
        $end = $to->addDay();
        $now = CarbonImmutable::now();

        $query = fn (): Builder => AgentStateLog::query()
            ->where('team_id', $currentTeam->id)
            // Periods that overlap the range, including the ones still running.
            ->where('started_at', '<', $end)
            ->where(fn (Builder $q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->when($filters['agent'] ?? null, fn (Builder $q, $agent) => $q->where('user_id', $agent))
            ->when($filters['state'] ?? null, fn (Builder $q, $state) => $q->where('state', $state));

        $logs = $query()
            ->with('user:id,name')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AgentStateLog $log) => [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $log->user?->name,
                'state' => $log->state->value,
                'state_label' => $log->state->label(),
                'reason' => $log->reason,
                'started_at' => $log->started_at->toIso8601String(),
                'ended_at' => $log->ended_at?->toIso8601String(),
                'duration' => (int) $log->started_at->diffInSeconds($log->ended_at ?? $now),
                'ongoing' => $log->ended_at === null,
            ]);

        return Inertia::render('agent-logs/Index', [
            'logs' => $logs,
            ...$this->totals($query(), $start, $end, $now),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'agent' => isset($filters['agent']) ? (int) $filters['agent'] : null,
                'state' => $filters['state'] ?? null,
            ],
            'agents' => $currentTeam->members()->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values(),
            'states' => collect(AgentState::cases())
                ->map(fn (AgentState $state) => ['value' => $state->value, 'label' => $state->label()])
                ->values(),
            'maxRangeDays' => self::MAX_RANGE_DAYS,
        ]);
    }

    /**
     * Time in each state per agent and time per pause reason. Periods are
     * clipped to the range, so a pause that started yesterday only counts
     * the part spent today.
     *
     * @return array{summary: array<string, int>, perAgent: list<array<string, mixed>>, reasons: list<array<string, mixed>>}
     */
    private function totals(Builder $query, CarbonInterface $start, CarbonInterface $end, CarbonInterface $now): array
    {
        $agents = [];
        $reasons = [];
        $summary = ['pause_seconds' => 0, 'pauses' => 0, 'longest_pause' => 0, 'agents' => 0];

        $query->with('user:id,name')
            ->lazyById(500)
            ->each(function (AgentStateLog $log) use (&$agents, &$reasons, &$summary, $start, $end, $now) {
                $periodStart = $log->started_at->max($start);
                $periodEnd = ($log->ended_at ?? $now)->min($end)->min($now);
                $seconds = max(0, (int) $periodStart->diffInSeconds($periodEnd, false));
                $state = $log->state;

                $agents[$log->user_id] ??= [
                    'user_id' => $log->user_id,
                    'name' => $log->user?->name ?? '—',
                    'seconds' => array_fill_keys(array_map(fn (AgentState $s) => $s->value, AgentState::cases()), 0),
                    'pauses' => 0,
                    'longest_pause' => 0,
                ];
                $agents[$log->user_id]['seconds'][$state->value] += $seconds;

                if ($state === AgentState::NotReady) {
                    $agents[$log->user_id]['pauses']++;
                    $agents[$log->user_id]['longest_pause'] = max($agents[$log->user_id]['longest_pause'], $seconds);

                    $label = $log->reason ?: AgentState::NotReady->label();
                    $reasons[$label] ??= ['reason' => $label, 'count' => 0, 'seconds' => 0];
                    $reasons[$label]['count']++;
                    $reasons[$label]['seconds'] += $seconds;

                    $summary['pauses']++;
                    $summary['pause_seconds'] += $seconds;
                    $summary['longest_pause'] = max($summary['longest_pause'], $seconds);
                }
            });

        $summary['agents'] = count($agents);

        return [
            'summary' => $summary,
            'perAgent' => collect($agents)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'reasons' => collect($reasons)->sortByDesc('seconds')->values()->all(),
        ];
    }
}
