<?php

namespace App\Services;

use App\Models\CampaignAttempt;
use App\Models\OutboundRate;
use App\Models\Team;
use App\Services\Concerns\UsesAsteriskConnection;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Metered outside calls, read from the Asterisk CDR.
 *
 * An outside call is the carrier leg of a call: a PJSIP channel that is not
 * an agent softphone (`{team key}_{extension}`), either
 *  - dialed by an agent (manual call, campaign preview): it is the dstchannel;
 *  - originated by the campaign dialer (progressive, power): it is the
 *    channel, and the CDR runs in the campaign context.
 * Calls coming in from the carrier are not metered.
 *
 * Its talk time runs from the first answer to the last hang-up of that
 * carrier channel (a progressive call has one CDR row per queue attempt).
 * It is billed per started minute at the rate in force when it was answered;
 * the plan's `outbound_minutes` are free each month, oldest calls first.
 */
class OutboundUsage
{
    use UsesAsteriskConnection;

    private const AGENT_CHANNEL = '#^PJSIP/([a-z0-9]{10})_(\d{2,20})-#';

    /**
     * Outside calls of one team in a period, newest first, priced.
     *
     * @return list<array{id: string, start: string, seconds: int, minutes: int, number: string, extension: ?string, campaign: bool, rate: int, free_minutes: int, cost: int}>
     */
    public function calls(Team $team, CarbonInterface $from, CarbonInterface $to): array
    {
        $included = (int) ($team->plan()->limit('outbound_minutes') ?? 0);

        return $this->price(
            $this->legs([$team->asterisk_key], $from, $to)->get($team->asterisk_key, collect()),
            $team,
            $included,
        );
    }

    /**
     * Totals of one team in a period.
     *
     * @param  list<array<string, mixed>>|null  $calls  already computed by calls()
     * @return array{calls: int, seconds: int, minutes: int, included_minutes: int, free_minutes: int, billable_minutes: int, amount: int}
     */
    public function summary(Team $team, CarbonInterface $from, CarbonInterface $to, ?array $calls = null): array
    {
        $calls ??= $this->calls($team, $from, $to);
        $calls = collect($calls);
        $minutes = (int) $calls->sum('minutes');
        $free = (int) $calls->sum('free_minutes');

        return [
            'calls' => $calls->count(),
            'seconds' => (int) $calls->sum('seconds'),
            'minutes' => $minutes,
            'included_minutes' => (int) ($team->plan()->limit('outbound_minutes') ?? 0),
            'free_minutes' => $free,
            'billable_minutes' => $minutes - $free,
            'amount' => (int) $calls->sum('cost'),
        ];
    }

    /**
     * Totals for every team with outside calls in the period (platform view),
     * biggest amount first.
     *
     * @return list<array{team_id: int, team: string, calls: int, minutes: int, billable_minutes: int, amount: int}>
     */
    public function perTeam(CarbonInterface $from, CarbonInterface $to): array
    {
        $teams = Team::query()->whereNotNull('asterisk_key')->get()->keyBy('asterisk_key');

        if ($teams->isEmpty()) {
            return [];
        }

        $legs = $this->legs($teams->keys()->all(), $from, $to);

        return $legs->map(function (Collection $calls, string $key) use ($teams) {
            /** @var Team $team */
            $team = $teams[$key];
            $priced = collect($this->price($calls, $team, (int) ($team->plan()->limit('outbound_minutes') ?? 0)));
            $minutes = (int) $priced->sum('minutes');

            return [
                'team_id' => $team->id,
                'team' => $team->name,
                'calls' => $priced->count(),
                'minutes' => $minutes,
                'billable_minutes' => $minutes - (int) $priced->sum('free_minutes'),
                'amount' => (int) $priced->sum('cost'),
            ];
        })->sortByDesc('amount')->values()->all();
    }

    /**
     * Carrier legs grouped by account (team key), one entry per carrier channel.
     *
     * @param  list<string>  $accounts
     * @return Collection<string, Collection<int, array{id: string, uniqueids: list<string>, answer: CarbonInterface, seconds: int, number: string, extension: ?string, campaign: bool}>>
     */
    private function legs(array $accounts, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $campaignContext = (string) config('asterisk.campaigns.context', 'cc-campaign');

        $rows = $this->connection()->table('cdr')
            ->whereIn('accountcode', $accounts)
            ->whereBetween('start', [$from, $to])
            ->where('disposition', 'ANSWERED')
            ->where('billsec', '>', 0)
            ->where(fn ($query) => $query->where('dstchannel', 'like', 'PJSIP/%')->orWhere('channel', 'like', 'PJSIP/%'))
            ->get(['uniqueid', 'linkedid', 'src', 'dst', 'channel', 'dstchannel', 'dcontext', 'answer', 'billsec', 'accountcode']);

        $carrier = function (string $channel): bool {
            return str_starts_with($channel, 'PJSIP/') && preg_match(self::AGENT_CHANNEL, $channel) !== 1;
        };

        $legs = [];

        foreach ($rows as $row) {
            $channel = (string) $row->channel;
            $dstchannel = (string) $row->dstchannel;
            $inCampaign = ($row->dcontext ?? '') === $campaignContext;

            if ($carrier($dstchannel) && (preg_match(self::AGENT_CHANNEL, $channel) === 1 || $inCampaign)) {
                // Agent → carrier: manual call or campaign preview.
                $carrierChannel = $dstchannel;
                $agentChannel = $channel;
                $number = (string) $row->dst;
            } elseif ($carrier($channel) && $inCampaign) {
                // Customer leg originated by the dialer (progressive, power).
                $carrierChannel = $channel;
                $agentChannel = $dstchannel;
                $number = (string) $row->src;
            } else {
                continue; // incoming from the carrier, or internal
            }

            if (empty($row->answer)) {
                continue;
            }

            $answer = Date::parse((string) $row->answer);
            $end = $answer->copy()->addSeconds((int) $row->billsec);
            $key = $row->accountcode.'|'.$carrierChannel;
            $extension = preg_match(self::AGENT_CHANNEL, $agentChannel, $m) === 1 ? $m[2] : null;

            if (! isset($legs[$key])) {
                $legs[$key] = [
                    'account' => (string) $row->accountcode,
                    'id' => (string) $row->uniqueid,
                    'uniqueids' => [],
                    'answer' => $answer,
                    'end' => $end,
                    'number' => $number,
                    'extension' => $extension,
                    'campaign' => $inCampaign,
                ];
            }

            $leg = &$legs[$key];
            $leg['uniqueids'][] = (string) $row->uniqueid;
            $leg['uniqueids'][] = (string) $row->linkedid;
            $leg['answer'] = $answer->lt($leg['answer']) ? $answer : $leg['answer'];
            $leg['end'] = $end->gt($leg['end']) ? $end : $leg['end'];
            $leg['extension'] ??= $extension;
            unset($leg);
        }

        return collect($legs)
            ->map(fn (array $leg) => [
                ...$leg,
                'uniqueids' => array_values(array_unique(array_filter($leg['uniqueids']))),
                'seconds' => max(1, (int) $leg['answer']->diffInSeconds($leg['end'])),
            ])
            ->groupBy('account');
    }

    /**
     * Minutes (per started minute), free minutes of the plan (oldest first)
     * and cost of each call; newest first.
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     * @return list<array{id: string, start: string, seconds: int, minutes: int, number: string, extension: ?string, campaign: bool, rate: int, free_minutes: int, cost: int}>
     */
    private function price(Collection $legs, Team $team, int $included): array
    {
        $schedule = OutboundRate::schedule();
        $numbers = $this->campaignNumbers($team, $legs->where('campaign', true));
        $remaining = $included;
        $priced = [];

        foreach ($legs->sortBy(fn (array $leg) => $leg['answer']->getTimestamp()) as $leg) {
            $minutes = (int) ceil($leg['seconds'] / 60);
            $free = min($minutes, $remaining);
            $remaining -= $free;
            $rate = OutboundRate::priceAt($schedule, $leg['answer']);
            $number = $leg['campaign']
                ? ($this->firstOf($numbers, $leg['uniqueids']) ?? $leg['number'])
                : $leg['number'];

            $priced[] = [
                'id' => $leg['id'],
                'start' => $leg['answer']->toIso8601String(),
                'seconds' => $leg['seconds'],
                'minutes' => $minutes,
                'number' => $this->display($number),
                'extension' => $leg['extension'],
                'campaign' => $leg['campaign'],
                'rate' => $rate,
                'free_minutes' => $free,
                'cost' => ($minutes - $free) * $rate,
            ];
        }

        return array_reverse($priced);
    }

    /**
     * Customer numbers of campaign calls, by Asterisk uniqueid (the CDR of a
     * preview call only knows the dialplan extension, not the number).
     *
     * @param  Collection<int, array<string, mixed>>  $legs
     * @return array<string, string>
     */
    private function campaignNumbers(Team $team, Collection $legs): array
    {
        $ids = $legs->pluck('uniqueids')->flatten()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return CampaignAttempt::query()
            ->where('team_id', $team->id)
            ->whereIn('uniqueid', $ids)
            ->pluck('phone', 'uniqueid')
            ->all();
    }

    /** @param  array<string, string>  $numbers */
    private function firstOf(array $numbers, array $uniqueids): ?string
    {
        foreach ($uniqueids as $id) {
            if (isset($numbers[$id])) {
                return $numbers[$id];
            }
        }

        return null;
    }

    /** 0022376123456 or 22376123456 → +22376123456 (short numbers as dialed). */
    private function display(string $number): string
    {
        if (preg_match('/^00(\d{6,})$/', $number, $m) === 1) {
            return '+'.$m[1];
        }

        return preg_match('/^\d{9,}$/', $number) === 1 ? '+'.$number : $number;
    }

    /**
     * A calendar month from `YYYY-MM` (current month when missing or invalid).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: string}
     */
    public static function month(?string $month): array
    {
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month) === 1
            ? Date::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00')
            : now()->startOfMonth();

        return [$start, $start->copy()->endOfMonth(), $start->format('Y-m')];
    }

    /**
     * The last months for a month picker, newest first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function recentMonths(int $count = 6): array
    {
        return collect(range(0, $count - 1))
            ->map(fn (int $i) => now()->startOfMonth()->subMonthsNoOverflow($i))
            ->map(fn (CarbonInterface $m) => ['value' => $m->format('Y-m'), 'label' => ucfirst($m->locale(app()->getLocale())->translatedFormat('F Y'))])
            ->all();
    }
}
