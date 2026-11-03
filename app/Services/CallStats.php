<?php

namespace App\Services;

use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Call statistics computed from the Asterisk CDR for a set of agent extensions.
 *
 * A "leg" is one CDR row where Asterisk rang an agent's phone. A "call" is all
 * the legs sharing a linkedid: it counts as answered when any leg was answered.
 */
class CallStats
{
    public function __construct(private AsteriskQueueMembers $members) {}

    /**
     * CDR legs of the team between two dates, optionally for one extension.
     * `direction` is inbound when the agent's phone was the dialed side
     * (dstchannel), outbound when the agent placed the call (channel).
     * Campaign calls are outbound even though the dialer rings the agent
     * (their CDR runs in the campaign context).
     *
     * @return Collection<int, array{id: string, call_id: string, extension: ?string, direction: string, name: string, number: string, start: string, answered: bool, billsec: int, duration: int, answer_seconds: ?int}>
     */
    public function legs(
        Team $team,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $extension = null,
    ): Collection {
        $endpointPattern = 'PJSIP/'.$team->asterisk_key.'_'.($extension !== null ? $extension.'-' : '');
        $agentChannel = '#^PJSIP/'.preg_quote($team->asterisk_key, '#').'_(\d{2,20})-#';
        $campaignContext = (string) config('asterisk.campaigns.context', 'cc-campaign');

        return $this->members->connection()->table('cdr')
            ->where('accountcode', $team->asterisk_key)
            ->where(function ($query) use ($endpointPattern) {
                $query->where('dstchannel', 'like', $endpointPattern.'%')
                    ->orWhere('channel', 'like', $endpointPattern.'%');
            })
            ->whereBetween('start', [$from, $to])
            ->orderByDesc('start')
            ->get(['uniqueid', 'linkedid', 'clid', 'src', 'channel', 'dstchannel', 'dcontext', 'disposition', 'billsec', 'duration', 'start', 'answer'])
            ->map(function ($row) use ($agentChannel, $extension, $campaignContext) {
                $inbound = preg_match($agentChannel, (string) $row->dstchannel, $m) === 1
                    && ($extension === null || $m[1] === $extension);

                if (! $inbound) {
                    preg_match($agentChannel, (string) $row->channel, $m);
                }

                $answerSeconds = null;

                if (! empty($row->answer) && ! empty($row->start)) {
                    $answerSeconds = max(0, strtotime((string) $row->answer) - strtotime((string) $row->start));
                }

                return [
                    'id' => (string) $row->uniqueid,
                    'call_id' => (string) ($row->linkedid ?: $row->uniqueid),
                    'extension' => $m[1] ?? null,
                    'direction' => $inbound && ($row->dcontext ?? '') !== $campaignContext ? 'inbound' : 'outbound',
                    'name' => $this->callerName((string) $row->clid, (string) $row->src),
                    'number' => (string) $row->src,
                    'start' => (string) $row->start,
                    'answered' => $row->disposition === 'ANSWERED',
                    'billsec' => (int) $row->billsec,
                    'duration' => (int) $row->duration,
                    'answer_seconds' => $answerSeconds,
                ];
            });
    }

    /**
     * Latest calls (one row per call) for a dashboard list.
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     * @return list<array{id: string, name: string, number: string, started_at: string, callType: string}>
     */
    public function recentCalls(Collection $legs, int $limit = 6, bool $missedOnly = false): array
    {
        return $legs->groupBy('call_id')
            ->map(fn (Collection $group) => [
                'leg' => $group->first(),
                'answered' => $group->contains('answered', true),
            ])
            ->when($missedOnly, fn (Collection $calls) => $calls->reject(fn (array $call) => $call['answered']))
            ->take($limit)
            ->map(fn (array $call) => [
                'id' => $call['leg']['id'],
                'name' => $call['leg']['name'],
                'number' => $call['leg']['number'],
                'started_at' => $call['leg']['start'],
                'callType' => $call['answered'] ? 'received' : 'missed',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $legs
     * @return array{total: int, answered: int, missed: int, answer_rate: int, avg_talk_seconds: ?int}
     */
    public function summary(Collection $legs): array
    {
        $calls = $legs->groupBy('call_id');
        $total = $calls->count();
        $answered = $calls->filter(fn (Collection $group) => $group->contains('answered', true))->count();
        $talk = $legs->where('answered', true);

        return [
            'total' => $total,
            'answered' => $answered,
            'missed' => $total - $answered,
            'answer_rate' => $total === 0 ? 0 : (int) round($answered / $total * 100),
            'avg_talk_seconds' => $talk->isEmpty() ? null : (int) round($talk->avg('billsec')),
        ];
    }

    /**
     * One row per agent, ordered like $members.
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     * @param  iterable<array{id: int, name: string, extension: string}>  $members
     * @return list<array{id: int, name: string, extension: string, answered: int, missed: int, talk_seconds: int}>
     */
    public function perAgent(Collection $legs, iterable $members): array
    {
        $byExtension = $legs->groupBy('extension');

        return collect($members)->map(function (array $member) use ($byExtension) {
            $mine = $byExtension->get($member['extension'], collect());
            // Counted per call (linkedid), like summary(): a retried ring is one call.
            $summary = $this->summary($mine);

            return [
                ...$member,
                'answered' => $summary['answered'],
                'missed' => $summary['missed'],
                'talk_seconds' => (int) $mine->where('answered', true)->sum('billsec'),
            ];
        })->values()->all();
    }

    /**
     * Detailed per-agent call metrics, deduplicated by linkedid.
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     * @param  iterable<array{id:int,name:string,extension:string}>  $members
     * @return list<array<string, mixed>>
     */
    public function perAgentDetails(Collection $legs, iterable $members): array
    {
        $byExtension = $legs->filter(fn (array $leg) => $leg['extension'] !== null)->groupBy('extension');

        return collect($members)->map(function (array $member) use ($byExtension) {
            $calls = $byExtension->get($member['extension'], collect())->groupBy('call_id');
            $answeredCalls = $calls->filter(fn (Collection $group) => $group->contains('answered', true));
            $missedCalls = $calls->count() - $answeredCalls->count();
            $answerTimes = $answeredCalls->map(function (Collection $group) {
                return $group->pluck('answer_seconds')->filter(fn ($value) => $value !== null)->min();
            })->filter(fn ($value) => $value !== null);
            $durations = $answeredCalls->map(fn (Collection $group) => (int) $group->max('duration'));

            return [
                ...$member,
                'total_calls' => $calls->count(),
                'answered' => $answeredCalls->count(),
                'missed' => $missedCalls,
                'avg_answer_seconds' => $answerTimes->isEmpty() ? null : (int) round($answerTimes->avg()),
                'total_duration_seconds' => (int) $durations->sum(),
                'avg_duration_seconds' => $durations->isEmpty() ? null : (int) round($durations->avg()),
            ];
        })->values()->all();
    }

    /**
     * Calls per day for the last $days days (oldest first), including empty days.
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     * @return list<array{date: string, total: int, answered: int, missed: int}>
     */
    public function daily(Collection $legs, int $days): array
    {
        $byDay = $legs->groupBy(fn (array $leg) => substr($leg['start'], 0, 10));

        return collect(range($days - 1, 0))->map(function (int $ago) use ($byDay) {
            $date = now()->subDays($ago)->toDateString();
            $summary = $this->summary($byDay->get($date, collect()));

            return [
                'date' => $date,
                'total' => $summary['total'],
                'answered' => $summary['answered'],
                'missed' => $summary['missed'],
            ];
        })->all();
    }

    /**
     * Calls nobody answered, newest first.
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     * @return list<array{id: string, name: string, number: string, started_at: string, callType: string}>
     */
    public function missedCalls(Collection $legs, int $limit = 10): array
    {
        return $this->recentCalls($legs, $limit, missedOnly: true);
    }

    private function callerName(string $clid, string $src): string
    {
        preg_match('/^"?([^"<]*?)"?\s*</', $clid, $m);
        $name = trim($m[1] ?? '');

        return $name !== '' ? $name : $src;
    }
}
