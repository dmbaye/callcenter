<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\DialMode;
use App\Models\Campaign;
use App\Models\CampaignAttempt;
use App\Models\CampaignContact;
use App\Models\PhoneExtension;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Outbound dialing, in the spirit of Genesys Cloud / RingCX outbound:
 *
 * - Preview: an agent reserves the next contact, reads it, then clicks Call;
 *   Asterisk rings the agent first and dials the customer when they answer.
 * - Progressive: one customer call per free agent of the campaign queue.
 * - Power: `dial_ratio` calls per free agent. When the abandon rate of the
 *   last hour goes above `max_abandon_rate`, pacing falls back to 1:1 until
 *   it recovers (answered customers with nobody to talk to are abandons).
 *
 * tick() runs every couple of seconds in `campaigns:dial`.
 */
class CampaignDialer
{
    /** AMI QueueMember Status: AST_DEVICE_NOT_INUSE. */
    private const MEMBER_NOT_IN_USE = '1';

    /** Written by every round of `campaigns:dial`; the agent desktop warns when it is missing. */
    public const HEARTBEAT_KEY = 'campaigns:dialer:heartbeat';

    public function __construct(
        private AsteriskAmi $ami,
        private CampaignOutcomes $outcomes,
        private AsteriskDialplan $dialplan,
    ) {}

    /**
     * One pacing round for every running automatic campaign, plus housekeeping.
     *
     * @return array<int, int> calls placed per campaign id
     */
    public function tick(): array
    {
        Cache::put(self::HEARTBEAT_KEY, now()->timestamp, now()->addMinutes(5));

        $this->housekeeping();

        $campaigns = Campaign::query()
            ->with(['team.subscription', 'queue'])
            ->where('status', CampaignStatus::Running)
            ->whereIn('dial_mode', [DialMode::Progressive, DialMode::Power])
            ->get()
            ->filter(fn (Campaign $campaign) => $this->mayDial($campaign));

        if ($campaigns->isEmpty()) {
            return [];
        }

        try {
            $members = collect($this->ami->snapshot()['queues']['members'] ?? []);
        } catch (RuntimeException $exception) {
            // No live view of the agents = no automatic dialing.
            report($exception);

            return [];
        }

        $placed = [];

        foreach ($campaigns as $campaign) {
            $placed[$campaign->id] = $this->pace($campaign, $members);
        }

        return $placed;
    }

    /** Is `campaigns:dial` running (a round in the last 30 seconds)? */
    public static function isRunning(): bool
    {
        $last = Cache::get(self::HEARTBEAT_KEY);

        return $last !== null && now()->timestamp - (int) $last <= 30;
    }

    /**
     * Why an automatic campaign places no call right now, in the order to
     * fix things (empty list = it should be dialling).
     *
     * @param  Collection<int, array<string, string>>|null  $members  AMI QueueMember entries (null = AMI unreachable)
     * @return list<string>
     */
    public function diagnose(Campaign $campaign, ?Collection $members): array
    {
        $campaign->loadMissing(['team.subscription', 'queue']);
        $reasons = [];
        $team = $campaign->team;

        if ($campaign->status !== CampaignStatus::Running) {
            $reasons[] = __('The campaign is not started (status: :status).', ['status' => $campaign->status->label()]);
        }

        if (! $campaign->dial_mode->dialsAutomatically()) {
            $reasons[] = __('Preview mode: agents place each call with the Call button.');
        }

        if ($team !== null && $team->isInternalOnly()) {
            $reasons[] = __('The organization is set to internal calls and meetings only.');
        }

        if ($team === null || ! $team->hasUsableSubscription()) {
            $reasons[] = __('The team subscription has expired.');
        } elseif (! $team->plan()->allows('campaigns')) {
            $reasons[] = __('The team plan does not include campaigns.');
        }

        if (! $campaign->isWithinCallingHours()) {
            $reasons[] = __('Outside the calling hours of the campaign (:tz).', ['tz' => $campaign->timezone]);
        }

        if ($campaign->queue === null) {
            $reasons[] = __('The campaign has no queue.');
        }

        if (app(OutboundTrunk::class)->name() === '') {
            $reasons[] = __('No outbound trunk: choose one in Administration → Plateforme.');
        }

        if ($this->callable($campaign)->doesntExist()) {
            $reasons[] = __('No contact to call now (list done, retries not due yet, or Do-Not-Call).');
        }

        if ($members === null) {
            $reasons[] = __('The AMI listener is unreachable: start `php artisan asterisk:ami-listen`.');
        } elseif ($campaign->queue !== null) {
            $inQueue = $members->filter(fn (array $m) => ($m['Queue'] ?? null) === $campaign->queue->asterisk_name);

            if ($inQueue->isEmpty()) {
                $reasons[] = __('No agent is logged into queue :queue (agents join it when their softphone is online and they are a member of the queue).', ['queue' => $campaign->queue->asterisk_name]);
            } elseif ($this->freeAgents($campaign, $members) === 0) {
                $states = $inQueue->map(fn (array $m) => ($m['Name'] ?? $m['Interface'] ?? '?').': '.$this->memberState($m))->implode(', ');
                $reasons[] = __('No free agent in the queue (:states).', ['states' => $states]);
            }
        }

        return $reasons;
    }

    /** @param  array<string, string>  $member */
    private function memberState(array $member): string
    {
        if (($member['Paused'] ?? '0') === '1') {
            return __('en pause');
        }

        if (($member['InCall'] ?? '0') === '1') {
            return __('en appel');
        }

        return match ($member['Status'] ?? '') {
            '1' => __('libre (en qualification ?)'),
            '2', '3', '6', '7' => __('occupé / sonne'),
            '4' => __('poste invalide'),
            '5' => __('softphone non enregistré'),
            default => __('état').' '.($member['Status'] ?? '?'),
        };
    }

    /** Team still paying, plan includes campaigns, inside the calling hours. */
    public function mayDial(Campaign $campaign): bool
    {
        $team = $campaign->team;

        return $team !== null
            && $team->isCallCenter()
            && $team->hasUsableSubscription()
            && $team->plan()->allows('campaigns')
            && $campaign->isWithinCallingHours();
    }

    /**
     * @param  Collection<int, array<string, string>>  $members  AMI QueueMember entries
     */
    public function pace(Campaign $campaign, Collection $members): int
    {
        $free = $this->freeAgents($campaign, $members);
        $inFlight = $this->inFlight($campaign);
        $lines = (int) floor($free * $this->effectiveRatio($campaign)) - $inFlight;

        if ($campaign->max_lines !== null) {
            $lines = min($lines, $campaign->max_lines - $this->activeCalls($campaign));
        }

        $lines = min($lines, (int) config('asterisk.campaigns.max_calls_per_tick', 20));

        if ($lines <= 0) {
            return 0;
        }

        $placed = 0;
        $claimed = $this->claim($campaign, $lines)->values();

        foreach ($claimed as $index => $contact) {
            try {
                $this->placeCustomerCall($campaign, $contact);
                $placed++;
            } catch (RuntimeException $exception) {
                report($exception);

                // Asterisk unreachable: give back the contacts not tried yet.
                $rest = $claimed->slice($index + 1)->pluck('id')->all();

                if ($rest !== []) {
                    CampaignContact::query()->whereIn('id', $rest)->where('status', ContactStatus::Dialing->value)
                        ->update(['status' => ContactStatus::Pending, 'updated_at' => now()]);
                }

                break;
            }
        }

        return $placed;
    }

    /**
     * Agents ready for a campaign call: in the campaign queue, not paused,
     * idle, and not still qualifying their previous campaign call.
     *
     * @param  Collection<int, array<string, string>>  $members
     */
    public function freeAgents(Campaign $campaign, Collection $members): int
    {
        $queueName = $campaign->queue?->asterisk_name;

        if ($queueName === null) {
            return 0;
        }

        $idle = $members
            ->filter(fn (array $member) => ($member['Queue'] ?? null) === $queueName
                && ($member['Paused'] ?? '0') !== '1'
                && ($member['Status'] ?? '') === self::MEMBER_NOT_IN_USE
                && ($member['InCall'] ?? '0') !== '1')
            ->map(fn (array $member) => $member['StateInterface'] ?? $member['Location'] ?? $member['Interface'] ?? '')
            ->unique()
            ->values();

        if ($idle->isEmpty()) {
            return 0;
        }

        $inWrapup = $this->agentsInWrapup($campaign);

        return $idle->reject(function (string $interface) use ($inWrapup): bool {
            $extension = preg_match('#^PJSIP/[a-z0-9]{10}_(\d{2,20})$#', $interface, $m) ? $m[1] : null;

            return $extension !== null && in_array($extension, $inWrapup, true);
        })->count();
    }

    /** Extensions of agents with a connected campaign call not yet qualified (team-wide: one wrap-up at a time). */
    private function agentsInWrapup(Campaign $campaign): array
    {
        $userIds = CampaignAttempt::query()
            ->where('team_id', $campaign->team_id)
            ->whereNotNull('user_id')
            ->whereNotNull('connected_at')
            ->whereNull('dispositioned_at')
            ->where('created_at', '>=', now()->subMinutes((int) config('asterisk.campaigns.wrapup_auto_close_minutes', 30)))
            ->pluck('user_id')
            ->unique()
            ->all();

        return $userIds === [] ? [] : PhoneExtension::query()
            ->where('team_id', $campaign->team_id)
            ->whereIn('user_id', $userIds)
            ->pluck('extension')
            ->all();
    }

    /** Customer calls ringing or waiting for an agent. */
    public function inFlight(Campaign $campaign): int
    {
        return $campaign->attempts()
            ->whereIn('status', [CampaignAttempt::DIALING, CampaignAttempt::ANSWERED])
            ->count();
    }

    private function activeCalls(Campaign $campaign): int
    {
        return $campaign->attempts()->where('status', '!=', CampaignAttempt::ENDED)->count();
    }

    /** Abandoned / (connected + abandoned) over the last hour, in %. */
    public function abandonRate(Campaign $campaign): float
    {
        $counts = $campaign->attempts()
            ->where('ended_at', '>=', now()->subHour())
            ->whereIn('result', [AttemptResult::Connected->value, AttemptResult::Abandoned->value])
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $abandoned = (int) ($counts[AttemptResult::Abandoned->value] ?? 0);
        $total = $abandoned + (int) ($counts[AttemptResult::Connected->value] ?? 0);

        return $total === 0 ? 0.0 : round($abandoned * 100 / $total, 1);
    }

    public function effectiveRatio(Campaign $campaign): float
    {
        if ($campaign->dial_mode !== DialMode::Power) {
            return 1.0;
        }

        // Too many abandons: dial 1:1 until the hourly rate recovers.
        if ($this->abandonRate($campaign) > $campaign->max_abandon_rate) {
            return 1.0;
        }

        return max(1.0, min(5.0, $campaign->dial_ratio));
    }

    /**
     * Contacts that may be dialled now, best first: due campaign-wide
     * callbacks, then priority, then the longest waiting. Personal callbacks
     * and Do-Not-Call numbers are never auto-dialled.
     *
     * @return Builder<CampaignContact>
     */
    public function callable(Campaign $campaign): Builder
    {
        return CampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where(fn (Builder $query) => $query
                ->where('status', ContactStatus::Pending)
                ->orWhere(fn (Builder $query) => $query->where('status', ContactStatus::Callback)->whereNull('callback_user_id')))
            ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where('attempts', '<', $campaign->max_attempts)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('dnc_numbers')
                ->whereColumn('dnc_numbers.phone', 'campaign_contacts.phone')
                ->where('dnc_numbers.team_id', $campaign->team_id))
            ->orderByRaw("case when status = 'callback' then 0 else 1 end")
            ->orderByDesc('priority')
            // Same order on every database: due retries first, then never-called contacts.
            ->orderByRaw('case when next_attempt_at is null then 1 else 0 end')
            ->orderBy('next_attempt_at')
            ->orderBy('id');
    }

    /**
     * Atomically take up to $count contacts (several dialers/agents can race).
     *
     * @return Collection<int, CampaignContact>
     */
    public function claim(
        Campaign $campaign,
        int $count,
        ContactStatus $as = ContactStatus::Dialing,
        ?User $agent = null,
    ): Collection {
        $claimed = collect();

        foreach ($this->callable($campaign)->limit($count * 2)->pluck('id') as $id) {
            if ($claimed->count() >= $count) {
                break;
            }

            $taken = CampaignContact::query()
                ->whereKey($id)
                ->whereIn('status', [ContactStatus::Pending->value, ContactStatus::Callback->value])
                ->update([
                    'status' => $as,
                    'reserved_by' => $agent?->id,
                    'reserved_until' => $agent ? now()->addMinutes((int) config('asterisk.campaigns.reservation_minutes', 15)) : null,
                    'updated_at' => now(),
                ]);

            if ($taken === 1) {
                $claimed->push(CampaignContact::query()->find($id));
            }
        }

        return $claimed;
    }

    /** Progressive / power: call the customer; answered calls enter the campaign queue. */
    public function placeCustomerCall(Campaign $campaign, CampaignContact $contact): CampaignAttempt
    {
        $attempt = $this->newAttempt($campaign, $contact, null);

        $this->originate($attempt, [
            'attempt_id' => $attempt->id,
            'channel' => $this->customerChannel($campaign, $contact),
            'exten' => 's',
            'caller_id' => $this->callerId($campaign),
            'account' => $campaign->team->asterisk_key,
            'timeout' => $campaign->ring_timeout * 1000,
            'variables' => [
                'CC_ATTEMPT' => $attempt->id,
                'CC_ACCOUNT' => $campaign->team->asterisk_key,
                'CC_QUEUE' => (string) $campaign->queue?->asterisk_name,
                'CC_WAIT' => $campaign->queue_timeout,
                'CC_AMD' => $campaign->amd_enabled ? 1 : 0,
                'CC_VOICEMAIL' => (string) $campaign->voicemail_sound,
                'CC_SORRY' => (string) $campaign->abandon_sound,
                'CC_NUMBER' => $contact->phone,
                'CC_CONTACT' => $this->callerName($contact),
                ...$this->recordingVariables($campaign),
            ],
        ], $contact);

        return $attempt;
    }

    /**
     * Preview and callbacks: ring the agent's softphone, then dial the
     * customer once the agent answers.
     */
    public function placePreviewCall(
        Campaign $campaign,
        CampaignContact $contact,
        User $agent,
    ): CampaignAttempt {
        $extension = $agent->phoneExtensionFor($campaign->team);

        if ($extension === null) {
            throw ValidationException::withMessages(['contact' => __('No SIP extension assigned to your account.')]);
        }

        $attempt = $this->newAttempt($campaign, $contact, $agent);

        $this->originate($attempt, [
            'attempt_id' => $attempt->id,
            'channel' => 'PJSIP/'.$extension->asteriskName(),
            'exten' => 'preview',
            'caller_id' => sprintf('"%s" <%s>', $this->callerName($contact), $contact->phone),
            'account' => $campaign->team->asterisk_key,
            'timeout' => 20000,
            'variables' => [
                'CC_ATTEMPT' => $attempt->id,
                'CC_ACCOUNT' => $campaign->team->asterisk_key,
                'CC_DIAL' => $this->customerChannel($campaign, $contact),
                'CC_RING' => $campaign->ring_timeout,
                'CC_CLI' => $this->callerId($campaign),
                // The agent leg is recorded; the notice is for customers, not played here.
                ...array_merge($this->recordingVariables($campaign), ['CC_REC_NOTICE' => '']),
            ],
        ], $contact);

        return $attempt;
    }

    /**
     * CC_RECORD / CC_REC_NOTICE for cc-campaign: the campaign queue's
     * recording setting, if the team's plan includes call recording.
     *
     * @return array{CC_RECORD: int, CC_REC_NOTICE: string}
     */
    private function recordingVariables(Campaign $campaign): array
    {
        $queue = $campaign->queue;
        $records = $this->dialplan->recordsCalls($campaign->team, $queue);

        return [
            'CC_RECORD' => $records ? 1 : 0,
            'CC_REC_NOTICE' => $records && $queue !== null ? $this->dialplan->recordingNotice($queue) : '',
        ];
    }

    private function newAttempt(
        Campaign $campaign,
        CampaignContact $contact,
        ?User $agent,
    ): CampaignAttempt {
        return DB::transaction(function () use ($campaign, $contact, $agent): CampaignAttempt {
            $contact->update([
                'status' => ContactStatus::Dialing,
                'attempts' => $contact->attempts + 1,
                'last_attempt_at' => now(),
            ]);

            return $campaign->attempts()->create([
                'campaign_contact_id' => $contact->id,
                'team_id' => $campaign->team_id,
                'user_id' => $agent?->id,
                'mode' => $agent ? DialMode::Preview : $campaign->dial_mode,
                'phone' => $contact->phone,
                'status' => CampaignAttempt::DIALING,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $call
     */
    private function originate(
        CampaignAttempt $attempt,
        array $call,
        CampaignContact $contact,
    ): void {
        try {
            $this->ami->originateCampaignCall($call);
        } catch (RuntimeException $exception) {
            // Nothing was dialled: undo the attempt, the contact goes back to the list.
            DB::transaction(function () use ($attempt, $contact): void {
                $attempt->delete();
                $contact->update([
                    'status' => $contact->callback_user_id ? ContactStatus::Callback : ContactStatus::Pending,
                    'attempts' => max(0, $contact->attempts - 1),
                ]);
            });

            throw $exception;
        }
    }

    private function customerChannel(Campaign $campaign, CampaignContact $contact): string
    {
        // Every campaign goes out through the platform's single trunk.
        $trunk = app(OutboundTrunk::class)->name();

        if ($trunk === '') {
            throw new RuntimeException('No outbound trunk configured (Administration → Plateforme).');
        }

        $prefix = $campaign->dial_prefix ?? (string) config('asterisk.campaigns.dial_prefix', '');

        return 'PJSIP/'.PhoneNumbers::dialable($contact->phone, $prefix).'@'.$trunk;
    }

    /** The campaign's number, else the team's main number (platform pool or its own). */
    private function callerId(Campaign $campaign): string
    {
        return (string) ($campaign->caller_id ?: $campaign->team->mainNumber());
    }

    private function callerName(CampaignContact $contact): string
    {
        $name = $contact->fullName() ?: ($contact->company ?? '');

        return trim((string) preg_replace('/[^\pL\pN .\'-]/u', '', $name)) ?: $contact->phone;
    }

    /**
     * Safety nets for lost events and idle reservations, then campaigns
     * whose list is done.
     */
    public function housekeeping(): void
    {
        // Calls that never reported back (daemon restart, lost event).
        CampaignAttempt::query()
            ->with('campaign')
            ->whereIn('status', [CampaignAttempt::DIALING, CampaignAttempt::ANSWERED])
            ->where('created_at', '<', now()->subMinutes(5))
            ->limit(200)
            ->get()
            ->each(function (CampaignAttempt $attempt): void {
                $limit = $attempt->campaign->ring_timeout + $attempt->campaign->queue_timeout + 120;

                if ($attempt->created_at->diffInSeconds(now()) > $limit) {
                    $this->outcomes->finish($attempt, $attempt->status === CampaignAttempt::ANSWERED ? AttemptResult::Abandoned : AttemptResult::Failed);
                }
            });

        // Connected calls stuck without end event for hours.
        CampaignAttempt::query()
            ->where('status', CampaignAttempt::CONNECTED)
            ->where('connected_at', '<', now()->subHours(4))
            ->limit(200)
            ->get()
            ->each(fn (CampaignAttempt $attempt) => $this->outcomes->finish($attempt, AttemptResult::Connected));

        // Wrap-ups nobody filled in.
        CampaignAttempt::query()
            ->whereNotNull('connected_at')
            ->whereNull('dispositioned_at')
            ->where('status', CampaignAttempt::ENDED)
            ->where('ended_at', '<', now()->subMinutes((int) config('asterisk.campaigns.wrapup_auto_close_minutes', 30)))
            ->limit(200)
            ->get()
            ->each(fn (CampaignAttempt $attempt) => $this->outcomes->autoClose($attempt));

        // Preview reservations left open.
        CampaignContact::query()
            ->where('status', ContactStatus::Reserved)
            ->where('reserved_until', '<', now())
            ->update(['status' => ContactStatus::Pending, 'reserved_by' => null, 'reserved_until' => null, 'updated_at' => now()]);

        // Running campaigns with nothing left to do.
        Campaign::query()
            ->where('status', CampaignStatus::Running)
            ->whereDoesntHave('contacts', fn (Builder $query) => $query->whereIn('status', array_map(
                fn (ContactStatus $status) => $status->value,
                ContactStatus::open(),
            )))
            ->whereHas('contacts')
            ->get()
            ->each(function (Campaign $campaign): void {
                try {
                    $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}
