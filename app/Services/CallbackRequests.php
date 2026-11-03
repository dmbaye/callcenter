<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\DialMode;
use App\Models\CallQueue;
use App\Models\Campaign;
use App\Models\CampaignContact;
use Illuminate\Support\Facades\DB;

/**
 * "Press 1 to be called back" (Genesys in-queue callback, RingCentral queue
 * callback). Each inbound queue gets a callback list: a preview campaign of
 * kind "callback" whose agents are the queue's own members. Requests show up
 * in the agents' workspace and use the same dialing, retries, wrap-up codes
 * and Do-Not-Call rules as campaigns.
 */
class CallbackRequests
{
    public const DISPOSITIONS = [
        ['label' => 'Demande traitée', 'outcome' => 'success'],
        ['label' => 'Information donnée', 'outcome' => 'final'],
        ['label' => 'Pas de réponse, réessayer', 'outcome' => 'retry', 'retry_minutes' => 30],
        ['label' => 'Rappel planifié', 'outcome' => 'callback'],
        ['label' => 'Mauvais numéro', 'outcome' => 'final'],
    ];

    public function __construct(private CampaignManager $campaigns) {}

    /** @return CampaignContact|null null when the caller id is unusable or on the DNC list */
    public function register(CallQueue $queue, string $callerNumber): ?CampaignContact
    {
        $phone = PhoneNumbers::normalize($callerNumber);
        $team = $queue->team;

        if ($phone === null || $team->dncNumbers()->where('phone', $phone)->exists()) {
            return null;
        }

        $campaign = $this->listFor($queue);

        return DB::transaction(function () use ($campaign, $phone, $queue): CampaignContact {
            /** @var CampaignContact|null $contact */
            $contact = $campaign->contacts()->where('phone', $phone)->lockForUpdate()->first();
            $data = ['file' => $queue->name, 'demande' => now()->setTimezone($campaign->timezone)->format('d/m/Y H:i')];

            if ($contact === null) {
                return $campaign->contacts()->create([
                    'phone' => $phone,
                    'status' => ContactStatus::Pending,
                    'priority' => 50,
                    'data' => $data,
                ]);
            }

            $open = in_array($contact->status, ContactStatus::open(), true);

            $contact->update([
                'data' => [...(array) $contact->data, ...$data],
                'priority' => 50,
                'next_attempt_at' => null,
                ...($open ? [] : [
                    'status' => ContactStatus::Pending,
                    'attempts' => 0,
                    'disposition_id' => null,
                    'last_result' => null,
                ]),
            ]);

            return $contact;
        });
    }

    /** The queue's callback list, created on first use and always running. */
    public function listFor(CallQueue $queue): Campaign
    {
        $campaign = Campaign::query()
            ->where('team_id', $queue->team_id)
            ->where('kind', Campaign::KIND_CALLBACK)
            ->where('call_queue_id', $queue->id)
            ->first();

        if ($campaign !== null) {
            if ($campaign->status !== CampaignStatus::Running) {
                $campaign->update(['status' => CampaignStatus::Running, 'completed_at' => null]);
            }

            return $campaign;
        }

        $campaign = $queue->team->campaigns()->create([
            'kind' => Campaign::KIND_CALLBACK,
            'call_queue_id' => $queue->id,
            'name' => 'Rappels — '.$queue->name,
            'description' => 'Demandes de rappel laissées par les appelants de la file '.$queue->name.'.',
            'status' => CampaignStatus::Running,
            'started_at' => now(),
            'dial_mode' => DialMode::Preview,
            'max_attempts' => 3,
            'retry_minutes' => ['no_answer' => 30, 'busy' => 10, 'failed' => 15, 'cancelled' => 0],
            'timezone' => 'Africa/Bamako',
            'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '07:00', 'end' => '21:00'],
            'script' => "Bonjour, ici {file}. Vous avez demandé à être rappelé le {demande}. Que puis-je faire pour vous ?",
        ]);
        $campaign->setRelation('team', $queue->team);

        $this->campaigns->syncDispositions($campaign, self::DISPOSITIONS);

        return $campaign;
    }
}
