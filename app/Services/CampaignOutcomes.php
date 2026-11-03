<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Enums\ContactStatus;
use App\Enums\DispositionOutcome;
use App\Events\CampaignAttemptEnded;
use App\Models\CampaignAttempt;
use App\Models\CampaignContact;
use App\Models\CampaignDisposition;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What happens to a contact after a call result (from Asterisk) or a
 * wrap-up code (from the agent). The single place for retry rules.
 */
class CampaignOutcomes
{
    /**
     * Close an attempt with its network/queue result. Idempotent: an
     * attempt is only ended once (events can repeat or race).
     */
    public function finish(CampaignAttempt $attempt, AttemptResult $result): bool
    {
        $finished = DB::transaction(function () use ($attempt, $result): bool {
            /** @var CampaignAttempt|null $locked */
            $locked = CampaignAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isEnded()) {
                return false;
            }

            $locked->update([
                'status' => CampaignAttempt::ENDED,
                'result' => $result,
                'ended_at' => now(),
                'connected_at' => $result === AttemptResult::Connected ? ($locked->connected_at ?? now()) : $locked->connected_at,
            ]);

            $contact = CampaignContact::query()->with('campaign')->lockForUpdate()->find($locked->campaign_contact_id);

            if ($contact !== null) {
                $this->afterResult($contact, $locked, $result);
            }

            $attempt->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($finished && $attempt->user_id !== null) {
            $this->broadcast(new CampaignAttemptEnded($attempt));
        }

        return $finished;
    }

    /** Must run inside the transaction holding the contact lock. */
    private function afterResult(
        CampaignContact $contact,
        CampaignAttempt $attempt,
        AttemptResult $result,
    ): void {
        $contact->last_result = $result;
        $contact->last_attempt_at = $attempt->created_at;

        // A wrap-up code that arrived first already decided (agent hung up and qualified fast).
        if ($attempt->disposition_id !== null) {
            $contact->save();

            return;
        }

        if ($result === AttemptResult::Connected) {
            $contact->status = ContactStatus::Wrapup;
            $contact->save();

            return;
        }

        if ($result === AttemptResult::Cancelled) {
            // The agent did not pick up: not the contact's fault, the attempt does not count.
            $contact->attempts = max(0, $contact->attempts - 1);
        }

        $this->scheduleRetry($contact, $contact->campaign->retryMinutesFor($result));
        $contact->save();
    }

    /**
     * Apply the agent's wrap-up code.
     */
    public function dispose(
        CampaignAttempt $attempt,
        CampaignDisposition $disposition,
        User $agent,
        ?string $notes = null,
        ?CarbonInterface $callbackAt = null,
    ): void {
        if ($disposition->campaign_id !== $attempt->campaign_id) {
            throw ValidationException::withMessages(['disposition_id' => __('This wrap-up code belongs to another campaign.')]);
        }

        if ($disposition->outcome === DispositionOutcome::Callback && ($callbackAt === null || $callbackAt->isPast())) {
            throw ValidationException::withMessages(['callback_at' => __('Choose a callback time in the future.')]);
        }

        DB::transaction(function () use ($attempt, $disposition, $agent, $notes, $callbackAt): void {
            /** @var CampaignAttempt $locked */
            $locked = CampaignAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->dispositioned_at !== null) {
                throw ValidationException::withMessages(['disposition_id' => __('This call has already been qualified.')]);
            }

            $locked->update([
                'disposition_id' => $disposition->id,
                'dispositioned_at' => now(),
                'notes' => $notes,
                'user_id' => $locked->user_id ?? $agent->id,
            ]);

            /** @var CampaignContact $contact */
            $contact = CampaignContact::query()->with('campaign')->lockForUpdate()->findOrFail($locked->campaign_contact_id);

            $contact->disposition_id = $disposition->id;
            $contact->reserved_by = null;
            $contact->reserved_until = null;
            $contact->callback_user_id = null;

            if ($notes !== null && trim($notes) !== '') {
                $contact->notes = trim(($contact->notes ? $contact->notes."\n" : '').now()->format('d/m H:i').' '.$agent->name.' : '.trim($notes));
            }

            match ($disposition->outcome) {
                DispositionOutcome::Success, DispositionOutcome::Final => $this->close($contact),
                DispositionOutcome::Retry => $this->scheduleRetry($contact, (int) ($disposition->retry_minutes ?? 60)),
                DispositionOutcome::Callback => $this->scheduleCallback($contact, $agent, $callbackAt),
                DispositionOutcome::Dnc => $this->doNotCall($contact, $agent),
            };

            $contact->save();
            $attempt->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Connected calls nobody qualified: close them so the agent is free again. */
    public function autoClose(CampaignAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $locked = CampaignAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null || $locked->dispositioned_at !== null) {
                return;
            }

            $locked->update(['dispositioned_at' => now(), 'notes' => trim(($locked->notes ?? '').' [non qualifié]')]);

            $contact = CampaignContact::query()->lockForUpdate()->find($locked->campaign_contact_id);

            if ($contact !== null && $contact->status === ContactStatus::Wrapup) {
                $this->close($contact);
                $contact->save();
            }
        });
    }

    private function close(CampaignContact $contact): void
    {
        $contact->status = ContactStatus::Completed;
        $contact->next_attempt_at = null;
    }

    private function scheduleRetry(CampaignContact $contact, int $minutes): void
    {
        $contact->reserved_by = null;
        $contact->reserved_until = null;

        if ($contact->attempts >= $contact->campaign->max_attempts) {
            $contact->status = ContactStatus::Exhausted;
            $contact->next_attempt_at = null;

            return;
        }

        $contact->status = ContactStatus::Pending;
        $contact->next_attempt_at = now()->addMinutes(max(0, $minutes));
    }

    /** A personal callback: the same agent gets the contact at that time, attempts or not. */
    private function scheduleCallback(
        CampaignContact $contact,
        User $agent,
        ?CarbonInterface $at,
    ): void {
        $contact->status = ContactStatus::Callback;
        $contact->next_attempt_at = $at;
        $contact->callback_user_id = $agent->id;
    }

    private function doNotCall(CampaignContact $contact, User $agent): void
    {
        $contact->status = ContactStatus::Dnc;
        $contact->next_attempt_at = null;

        $contact->campaign->team->dncNumbers()->firstOrCreate(
            ['phone' => $contact->phone],
            ['reason' => __('Asked during campaign :name', ['name' => $contact->campaign->name]), 'added_by' => $agent->id],
        );
    }

    private function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
