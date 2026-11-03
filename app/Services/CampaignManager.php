<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Models\Campaign;
use App\Models\CampaignDisposition;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates and changes campaigns together with what they own in Asterisk:
 * a dedicated queue (answered customers wait there for the campaign
 * agents) whose members are the campaign agents. Agents stay blended:
 * inbound queues and campaign queue at the same time.
 */
class CampaignManager
{
    public function __construct(
        private AsteriskQueues $queues,
        private AsteriskQueueMembers $queueMembers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated campaign fields
     * @param  list<int>  $agentIds
     * @param  list<array{id?: int|null, label: string, outcome: string, retry_minutes?: int|null}>|null  $dispositions
     */
    public function create(
        Team $team,
        User $creator,
        array $data,
        array $agentIds,
        ?array $dispositions,
    ): Campaign {
        $campaign = $team->campaigns()->create([
            ...$data,
            'status' => CampaignStatus::Draft,
            'created_by' => $creator->id,
        ]);
        $campaign->setRelation('team', $team);

        try {
            $queue = $this->queues->create($team, [
                'name' => $this->queueName($team, $campaign),
                'strategy' => 'leastrecent',
                'timeout' => 15,
                'wrapuptime' => $campaign->wrapup_seconds,
                'joinempty' => 'yes',
                'leavewhenempty' => 'no',
                'ringinuse' => 'no',
            ]);
        } catch (Throwable $exception) {
            $campaign->delete();

            throw $exception;
        }

        $campaign->update(['call_queue_id' => $queue->id]);

        $this->syncDispositions($campaign, $dispositions ?? CampaignDisposition::DEFAULTS);
        $this->syncAgents($campaign, $agentIds);

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $agentIds
     * @param  list<array{id?: int|null, label: string, outcome: string, retry_minutes?: int|null}>  $dispositions
     */
    public function update(
        Campaign $campaign,
        array $data,
        array $agentIds,
        array $dispositions,
    ): Campaign {
        $campaign->update($data);

        if ($campaign->queue && ! $campaign->isCallbackList()) {
            try {
                $this->queues->update($campaign->team, $campaign->queue->id, [
                    'name' => $campaign->queue->name,
                    'wrapuptime' => $campaign->wrapup_seconds,
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->syncDispositions($campaign, $dispositions);

        // A callback list's agents are the inbound queue's members: managed with the queue.
        if (! $campaign->isCallbackList()) {
            $this->syncAgents($campaign, $agentIds);
        }

        return $campaign->refresh();
    }

    public function delete(Campaign $campaign): void
    {
        if ($campaign->isRunning()) {
            throw ValidationException::withMessages(['campaign' => __('Pause the campaign before deleting it.')]);
        }

        $queueId = $campaign->call_queue_id;
        $team = $campaign->team;

        DB::transaction(fn () => $campaign->delete());

        if ($queueId !== null && ! $campaign->isCallbackList()) {
            try {
                $this->queues->delete($team, $queueId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Agents of the campaign = members of its queue. Online agents are
     * re-joined right away so the change applies without a new login.
     *
     * @param  list<int>  $userIds
     */
    public function syncAgents(Campaign $campaign, array $userIds): void
    {
        if ($campaign->queue === null) {
            return;
        }

        $campaign->queue->setRelation('team', $campaign->team);
        $this->queueMembers->syncQueueAgents($campaign->queue, array_fill_keys($userIds, 0));
    }

    /**
     * Keep ids of codes already used by calls (history stays readable);
     * codes removed from the form are deleted.
     *
     * @param  list<array{id?: int|null, label: string, outcome: string, retry_minutes?: int|null}>  $dispositions
     */
    public function syncDispositions(Campaign $campaign, array $dispositions): void
    {
        $keep = [];

        foreach (array_values($dispositions) as $index => $row) {
            $values = [
                'label' => trim((string) $row['label']),
                'outcome' => $row['outcome'],
                'retry_minutes' => isset($row['retry_minutes']) && $row['retry_minutes'] !== '' ? (int) $row['retry_minutes'] : null,
                'sort' => $index,
            ];

            $existing = isset($row['id']) ? $campaign->dispositions()->whereKey($row['id'])->first() : null;

            if ($existing) {
                $existing->update($values);
                $keep[] = $existing->id;
            } else {
                $keep[] = $campaign->dispositions()->create($values)->id;
            }
        }

        $campaign->dispositions()->whereNotIn('id', $keep)->delete();
    }

    public function start(Campaign $campaign): void
    {
        $errors = [];

        if (! $campaign->contacts()->whereIn('status', array_map(fn (ContactStatus $s) => $s->value, ContactStatus::open()))->exists()) {
            $errors[] = __('Import contacts first.');
        }

        if (! $campaign->queue || ! $campaign->queue->members()->exists()) {
            $errors[] = __('Assign at least one agent.');
        }

        if (! $campaign->dispositions()->exists()) {
            $errors[] = __('Add at least one wrap-up code.');
        }

        if (app(OutboundTrunk::class)->name() === '') {
            $errors[] = __('The platform outbound line is not configured (Administration → Plateforme).');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['campaign' => $errors]);
        }

        $campaign->update([
            'status' => CampaignStatus::Running,
            'started_at' => $campaign->started_at ?? now(),
            'completed_at' => null,
        ]);
    }

    /** Exhausted contacts get a new round of attempts. */
    public function recycle(Campaign $campaign): int
    {
        return $campaign->contacts()
            ->where('status', ContactStatus::Exhausted)
            ->update([
                'status' => ContactStatus::Pending,
                'attempts' => 0,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function queueName(Team $team, Campaign $campaign): string
    {
        $base = Str::limit('Campagne '.$campaign->name, 50, '');
        $name = $base;
        $i = 2;

        while ($team->callQueues()->where('name', $name)->exists()) {
            $name = $base.' ('.$i++.')';
        }

        return $name;
    }
}
