<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Enums\ContactStatus;
use App\Enums\DispositionOutcome;
use App\Models\Campaign;
use App\Models\CampaignAttempt;
use App\Models\User;

/**
 * Campaign reporting: list penetration, results, wrap-up codes, agents.
 * Everything is counted in SQL (grouped), so it holds with large lists.
 */
class CampaignStats
{
    public function __construct(private CampaignDialer $dialer) {}

    /** @return array<string, mixed> */
    public function summary(Campaign $campaign): array
    {
        $contacts = $campaign->contacts()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $results = $campaign->attempts()
            ->whereNotNull('result')
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result')
            ->map(fn ($total) => (int) $total);

        $dispositions = $campaign->dispositions()->withCount('attempts')->get();

        $totalContacts = $contacts->sum();
        $dialled = $results->sum();
        $connected = (int) ($results[AttemptResult::Connected->value] ?? 0);
        $successes = $dispositions->where('outcome', DispositionOutcome::Success)->sum('attempts_count');
        $done = ($contacts[ContactStatus::Completed->value] ?? 0) + ($contacts[ContactStatus::Exhausted->value] ?? 0) + ($contacts[ContactStatus::Dnc->value] ?? 0);

        return [
            'contacts' => [
                'total' => $totalContacts,
                'byStatus' => collect(ContactStatus::cases())->map(fn (ContactStatus $status) => [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'count' => (int) ($contacts[$status->value] ?? 0),
                ])->values()->all(),
                'penetration' => $totalContacts > 0 ? round($done * 100 / $totalContacts, 1) : 0,
                'callableNow' => $this->dialer->callable($campaign)->count(),
            ],
            'attempts' => [
                'total' => $dialled,
                'byResult' => collect(AttemptResult::cases())->map(fn (AttemptResult $result) => [
                    'result' => $result->value,
                    'label' => $result->label(),
                    'count' => (int) ($results[$result->value] ?? 0),
                ])->values()->all(),
                'connectRate' => $dialled > 0 ? round($connected * 100 / $dialled, 1) : 0,
                'conversionRate' => $connected > 0 ? round($successes * 100 / $connected, 1) : 0,
                'abandonRateHour' => $this->dialer->abandonRate($campaign),
                'avgTalkSeconds' => $this->averageTalk($campaign),
            ],
            'dispositions' => $dispositions->map(fn ($disposition) => [
                'id' => $disposition->id,
                'label' => $disposition->label,
                'outcome' => $disposition->outcome->value,
                'count' => (int) $disposition->attempts_count,
            ])->values()->all(),
            'live' => [
                'inFlight' => $this->dialer->inFlight($campaign),
                'connected' => $campaign->attempts()->where('status', CampaignAttempt::CONNECTED)->count(),
                'effectiveRatio' => $this->dialer->effectiveRatio($campaign),
                'withinHours' => $campaign->isWithinCallingHours(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> per agent: calls, connected, successes, talk time */
    public function agents(Campaign $campaign): array
    {
        $rows = $campaign->attempts()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as calls, sum(case when result = ? then 1 else 0 end) as connected', [AttemptResult::Connected->value])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $successes = $campaign->attempts()
            ->whereNotNull('user_id')
            ->join('campaign_dispositions', 'campaign_dispositions.id', '=', 'campaign_attempts.disposition_id')
            ->where('campaign_dispositions.outcome', DispositionOutcome::Success->value)
            ->selectRaw('campaign_attempts.user_id, count(*) as total')
            ->groupBy('campaign_attempts.user_id')
            ->pluck('total', 'user_id');

        $names = User::query()->whereIn('id', $rows->keys())->pluck('name', 'id');

        return $rows->map(fn ($row, $userId) => [
            'id' => (int) $userId,
            'name' => $names[$userId] ?? '—',
            'calls' => (int) $row->calls,
            'connected' => (int) $row->connected,
            'successes' => (int) ($successes[$userId] ?? 0),
        ])->sortByDesc('successes')->values()->all();
    }

    private function averageTalk(Campaign $campaign): ?int
    {
        $durations = $campaign->attempts()
            ->where('result', AttemptResult::Connected->value)
            ->whereNotNull('connected_at')
            ->whereNotNull('ended_at')
            ->latest('id')
            ->limit(2000)
            ->get(['connected_at', 'ended_at'])
            ->map(fn (CampaignAttempt $attempt) => $attempt->talkSeconds());

        return $durations->isEmpty() ? null : (int) round($durations->avg());
    }
}
