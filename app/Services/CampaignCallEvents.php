<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Enums\DialMode;
use App\Events\CampaignCallConnected;
use App\Models\CampaignAttempt;
use App\Models\PhoneExtension;
use Throwable;

/**
 * Turns AMI events about campaign calls into attempt state. Called by the
 * AMI daemon (asterisk:ami-listen) for:
 *
 * - OriginateResponse (ActionID cc-camp-{attempt}-…): the call failed before
 *   anyone answered (no answer, busy, network), or was answered.
 * - UserEvent CcCampaign (State: answered | agent | connected) from the
 *   campaign dialplan.
 * - AgentConnect: a queue member took an answered customer call (screen pop).
 * - UserEvent CcCampaignEnd: hangup, with AMD / queue / dial results.
 */
class CampaignCallEvents
{
    /** OriginateResponse Reason codes (Asterisk AST_CONTROL_*). */
    private const ORIGINATE_REASONS = [
        '0' => AttemptResult::Failed,
        '1' => AttemptResult::NoAnswer,   // hung up before answer
        '3' => AttemptResult::NoAnswer,   // ring timeout
        '5' => AttemptResult::Busy,
        '8' => AttemptResult::Failed,     // congestion
    ];

    public function __construct(private CampaignOutcomes $outcomes) {}

    /** @param  array<string, string>  $event */
    public function handle(array $event): void
    {
        match ($event['Event'] ?? null) {
            'OriginateResponse' => $this->originateResponse($event),
            'UserEvent' => ($event['UserEvent'] ?? '') === 'CcCampaignEnd' ? $this->ended($event) : $this->progress($event),
            'AgentConnect' => $this->agentConnect($event),
            default => null,
        };
    }

    /** @param  array<string, string>  $event */
    private function originateResponse(array $event): void
    {
        if (! preg_match('/^cc-camp-(\d+)-/', $event['ActionID'] ?? '', $matches)) {
            return;
        }

        $attempt = CampaignAttempt::query()->find((int) $matches[1]);

        if ($attempt === null || $attempt->isEnded()) {
            return;
        }

        if (($event['Response'] ?? '') === 'Success') {
            if ($attempt->uniqueid === null && ($event['Uniqueid'] ?? '') !== '') {
                $attempt->update(['uniqueid' => $event['Uniqueid']]);
            }

            return;
        }

        // Agent-first call that failed: the agent did not pick up (or is unreachable).
        $result = $attempt->mode === DialMode::Preview
            ? AttemptResult::Cancelled
            : (self::ORIGINATE_REASONS[$event['Reason'] ?? '0'] ?? AttemptResult::Failed);

        $this->outcomes->finish($attempt, $result);
    }

    /** @param  array<string, string>  $event */
    private function progress(array $event): void
    {
        $attempt = $this->attempt($event);

        if ($attempt === null || $attempt->isEnded()) {
            return;
        }

        match (strtolower($event['State'] ?? '')) {
            'answered' => $attempt->update([
                'status' => CampaignAttempt::ANSWERED,
                'answered_at' => now(),
                'uniqueid' => $event['Uniqueid'] ?? $attempt->uniqueid,
            ]),
            'agent' => $attempt->update(['uniqueid' => $event['Uniqueid'] ?? $attempt->uniqueid]),
            // Preview: the customer picked up the call placed for the agent.
            'connected' => $attempt->update([
                'status' => CampaignAttempt::CONNECTED,
                'answered_at' => $attempt->answered_at ?? now(),
                'connected_at' => now(),
            ]),
            default => null,
        };
    }

    /**
     * A queue member took a customer: record the agent and pop the contact
     * on their screen. AgentConnect carries the customer's Uniqueid.
     *
     * @param  array<string, string>  $event
     */
    private function agentConnect(array $event): void
    {
        $uniqueid = $event['Uniqueid'] ?? '';

        if ($uniqueid === '') {
            return;
        }

        $attempt = CampaignAttempt::query()
            ->where('uniqueid', $uniqueid)
            ->whereIn('status', [CampaignAttempt::DIALING, CampaignAttempt::ANSWERED])
            ->first();

        if ($attempt === null) {
            return;
        }

        $agentId = $this->userForInterface($event['Interface'] ?? $event['DestChannel'] ?? '', $attempt->team_id);

        $attempt->update([
            'status' => CampaignAttempt::CONNECTED,
            'connected_at' => now(),
            'answered_at' => $attempt->answered_at ?? now(),
            'user_id' => $agentId ?? $attempt->user_id,
        ]);

        if ($attempt->user_id !== null) {
            try {
                event(new CampaignCallConnected($attempt->load('campaign.team', 'contact')));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @param  array<string, string>  $event */
    private function ended(array $event): void
    {
        $attempt = $this->attempt($event);

        if ($attempt === null || $attempt->isEnded()) {
            return;
        }

        $this->outcomes->finish($attempt, $this->resultOf($attempt, $event));
    }

    /** @param  array<string, string>  $event */
    public function resultOf(CampaignAttempt $attempt, array $event): AttemptResult
    {
        if ($attempt->mode === DialMode::Preview) {
            return match (strtoupper($event['Dial'] ?? '')) {
                'ANSWER' => AttemptResult::Connected,
                'BUSY' => AttemptResult::Busy,
                'NOANSWER' => AttemptResult::NoAnswer,
                'CONGESTION', 'CHANUNAVAIL' => AttemptResult::Failed,
                // The agent hung up before the customer was dialled/answered.
                default => $attempt->connected_at !== null ? AttemptResult::Connected : AttemptResult::Cancelled,
            };
        }

        if (strtoupper($event['Amd'] ?? '') === 'MACHINE') {
            return AttemptResult::Machine;
        }

        if (($event['Member'] ?? '') !== '' || $attempt->connected_at !== null) {
            return AttemptResult::Connected;
        }

        return ($event['Answered'] ?? '') === '1' ? AttemptResult::Abandoned : AttemptResult::NoAnswer;
    }

    /** @param  array<string, string>  $event */
    private function attempt(array $event): ?CampaignAttempt
    {
        $id = (int) ($event['Attempt'] ?? 0);

        return $id > 0 ? CampaignAttempt::query()->find($id) : null;
    }

    /** "PJSIP/{asterisk_key}_{extension}" (or a channel name "…-0000001a") → user id in that team. */
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
}
