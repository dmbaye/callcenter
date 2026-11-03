<?php

namespace App\Services;

use App\Events\InboundCallOffered;
use App\Models\CallOutcome;
use App\Models\CallQueue;
use App\Models\CampaignAttempt;
use App\Models\CampaignContact;
use App\Models\PhoneExtension;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Inbound events from the AMI daemon:
 *
 * - UserEvent CcInbound (queue entry): remember queue, IVR choice and entry time.
 * - AgentCalled (an agent's phone rings): push the screen pop.
 * - UserEvent CcCallback: "press 1 to be called back".
 * - UserEvent CcSurvey: post-call satisfaction score.
 */
class InboundCallEvents
{
    private const CONTEXT_TTL = 4 * 3600;

    public function __construct(
        private CallbackRequests $callbacks,
        private AsteriskQueueMembers $queueMembers,
    ) {}

    /** @param  array<string, string>  $event */
    public function handle(array $event): void
    {
        match ($event['Event'] ?? null) {
            'AgentCalled' => $this->agentCalled($event),
            'UserEvent' => match ($event['UserEvent'] ?? null) {
                'CcInbound' => $this->entered($event),
                'CcCallback' => $this->callback($event),
                'CcSurvey' => $this->survey($event),
                default => null,
            },
            default => null,
        };
    }

    /** @param  array<string, string>  $event */
    private function entered(array $event): void
    {
        $uniqueid = $event['Uniqueid'] ?? '';

        if ($uniqueid === '') {
            return;
        }

        $known = Cache::get($this->key($uniqueid));

        // A transfer or an overflow re-enters a queue: keep the first entry time.
        Cache::put($this->key($uniqueid), [
            'queue' => $event['Queue'] ?? '',
            'number' => $event['Number'] ?? ($event['CallerIDNum'] ?? ''),
            'choice' => ($event['Choice'] ?? '') !== '' ? $event['Choice'] : ($known['choice'] ?? null),
            'did' => $known['did'] ?? null,
            'at' => $known['at'] ?? time(),
        ], self::CONTEXT_TTL);
    }

    /** @param  array<string, string>  $event */
    private function agentCalled(array $event): void
    {
        $uniqueid = $event['Uniqueid'] ?? '';

        // Campaign calls have their own screen pop.
        if ($uniqueid !== '' && CampaignAttempt::query()->where('uniqueid', $uniqueid)->exists()) {
            return;
        }

        $queue = CallQueue::query()->with('team')->where('asterisk_name', $event['Queue'] ?? '')->first();

        if ($queue === null) {
            return;
        }

        $userId = $this->userForInterface($event['Interface'] ?? $event['DestChannel'] ?? '', $queue->team_id);

        if ($userId === null) {
            return;
        }

        $context = $this->context($queue, $event);

        try {
            event(new InboundCallOffered($userId, $context));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What the agent sees while it rings.
     *
     * @param  array<string, string>  $event
     * @return array<string, mixed>
     */
    public function context(CallQueue $queue, array $event): array
    {
        $cached = Cache::get($this->key($event['Uniqueid'] ?? ''), []);
        $raw = (string) ($event['CallerIDNum'] ?? $cached['number'] ?? '');
        $phone = PhoneNumbers::normalize($raw);
        $team = $queue->team;

        $contact = $phone === null ? null : CampaignContact::query()
            ->whereIn('campaign_id', $team->campaigns()->select('id'))
            ->where('phone', $phone)
            ->with('campaign:id,name', 'disposition:id,label')
            ->latest('updated_at')
            ->first();

        // Same number in national or international form.
        $variants = array_values(array_unique(array_filter([$raw, $phone, $phone ? substr($phone, -8) : null])));

        $history = $variants === [] ? collect() : $this->queueMembers->connection()->table('cdr')
            ->where('accountcode', $team->asterisk_key)
            ->whereIn('src', $variants)
            ->where('start', '>=', now()->subDays(90))
            ->orderByDesc('start')
            ->limit(50)
            ->get(['start', 'disposition']);

        $lastOutcome = $variants === [] ? null : CallOutcome::query()
            ->where('team_id', $team->id)
            ->whereIn('caller', $variants)
            ->latest('started_at')
            ->first();

        return [
            'number' => $raw,
            'queue' => $queue->name,
            'choice' => $cached['choice'] ?? null,
            'waitSeconds' => isset($event['HoldTime']) ? (int) $event['HoldTime'] : (isset($cached['at']) ? max(0, time() - (int) $cached['at']) : null),
            'contact' => $contact ? [
                'name' => $contact->fullName() ?: null,
                'company' => $contact->company,
                'campaign' => $contact->campaign?->name,
                'lastDisposition' => $contact->disposition?->label,
            ] : null,
            'history' => [
                'calls90d' => $history->count(),
                'missed90d' => $history->where('disposition', '!=', 'ANSWERED')->count(),
                'lastCallAt' => $history->first()?->start ? (string) $history->first()->start : null,
                'lastOutcome' => $lastOutcome?->outcome,
                'lastNotes' => $lastOutcome?->notes,
            ],
        ];
    }

    /** @param  array<string, string>  $event */
    private function callback(array $event): void
    {
        $queue = $this->queueFor($event);

        if ($queue !== null) {
            $this->callbacks->register($queue, (string) ($event['Number'] ?? $event['CallerIDNum'] ?? ''));
        }
    }

    /** @param  array<string, string>  $event */
    private function survey(array $event): void
    {
        $queue = $this->queueFor($event);
        $score = (int) ($event['Score'] ?? 0);

        if ($queue === null || $score < 1 || $score > 5) {
            return;
        }

        $uniqueid = ($event['Uniqueid'] ?? '') !== '' ? $event['Uniqueid'] : null;

        $values = [
            'call_queue_id' => $queue->id,
            'user_id' => $this->userForInterface($event['Member'] ?? '', $queue->team_id),
            'caller' => substr((string) ($event['Number'] ?? ''), 0, 32) ?: null,
            'score' => $score,
        ];

        $uniqueid === null
            ? $queue->team->surveys()->create($values)
            : $queue->team->surveys()->firstOrCreate(['uniqueid' => $uniqueid], $values);
    }

    /** Queue named in the event, inside the tenant named by Account (never trust one alone). */
    private function queueFor(array $event): ?CallQueue
    {
        return CallQueue::query()
            ->with('team')
            ->where('asterisk_name', $event['Queue'] ?? '')
            ->whereHas('team', fn ($query) => $query->where('asterisk_key', $event['Account'] ?? ''))
            ->first();
    }

    private function userForInterface(string $interface, int $teamId): ?int
    {
        if (! preg_match('#^PJSIP/([a-z0-9]{10})_(\d{2,20})#', $interface, $matches)) {
            return null;
        }

        $userId = PhoneExtension::query()
            ->where('team_id', $teamId)
            ->where('extension', $matches[2])
            ->whereHas('team', fn ($query) => $query->where('asterisk_key', $matches[1]))
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    private function key(string $uniqueid): string
    {
        return 'inbound-call:'.$uniqueid;
    }
}
