<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\Team;
use App\Services\Concerns\UsesAsteriskConnection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Team-scoped queues: the Laravel call_queues row is the source of truth,
 * the Asterisk realtime `queues` row holds the PBX settings, and the queue's
 * entry extension lives in the team dialplan context.
 */
class AsteriskQueues
{
    use UsesAsteriskConnection;

    /** Settings an admin may edit. `context` is not one of them: it is always the team context. */
    public const EDITABLE = [
        'strategy', 'servicelevel', 'timeout', 'retry', 'wrapuptime',
        'maxlen', 'weight', 'announce_frequency', 'musiconhold', 'joinempty',
        'leavewhenempty', 'ringinuse',
        // Caller and agent announcements ("Attente et débordement" tab).
        'announce_position', 'announce_holdtime', 'periodic_announce', 'periodic_announce_frequency', 'announce',
    ];

    public function __construct(private AsteriskDialplan $dialplan) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(Team $team): array
    {
        $queues = $team->callQueues()->orderBy('name')->get();

        $rows = $queues->isEmpty() ? collect() : $this->connection()->table('queues')
            ->whereIn($this->identifierColumn(), $queues->pluck('asterisk_name')->all())
            ->get()
            ->keyBy($this->identifierColumn());

        return $queues->map(function (CallQueue $queue) use ($rows): array {
            $row = ['id' => $queue->id, 'name' => $queue->name, 'asterisk_name' => $queue->asterisk_name];
            $asterisk = $rows->get($queue->asterisk_name);

            return $asterisk ? [...(array) $asterisk, ...$row] : $row;
        })->all();
    }

    /**
     * @return list<string> immutable Asterisk queue names of the team
     */
    public function names(Team $team): array
    {
        return $team->callQueues()->orderBy('name')->pluck('asterisk_name')->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Team $team, array $data): CallQueue
    {
        $displayName = trim((string) $data['name']);
        $slug = Str::slug($displayName) ?: 'queue';

        $queue = $team->callQueues()->create([
            'name' => $displayName,
            'asterisk_name' => $this->uniqueAsteriskName($team, $slug),
        ]);
        $queue->setRelation('team', $team);

        try {
            $values = $this->onlyEditable($data);
            $values[$this->identifierColumn()] = $queue->asterisk_name;
            // Never offer a queue call to an agent who is already on a call.
            if (in_array('ringinuse', $this->asteriskColumns('queues'), true)) {
                $values['ringinuse'] ??= 'no';
            }

            // Service level tracked by the team: never leave the threshold at 0.
            if ($team->service_level_enabled && in_array('servicelevel', $this->asteriskColumns('queues'), true)) {
                $values['servicelevel'] ??= 20;
            }

            if (in_array('context', $this->asteriskColumns('queues'), true)) {
                $values['context'] = $team->asteriskContext();
            }

            $this->connection()->table('queues')->insert($values);
            $this->dialplan->syncQueue($queue);
        } catch (\Throwable $exception) {
            $this->connection()->table('queues')->where($this->identifierColumn(), $queue->asterisk_name)->delete();
            $queue->delete();

            throw $exception;
        }

        return $queue;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Team $team, int $id, array $data): CallQueue
    {
        $queue = $team->callQueues()->findOrFail($id);
        $values = $this->onlyEditable($data);
        $newName = trim((string) ($data['name'] ?? $queue->name));

        if ($newName !== $queue->name && $team->callQueues()->where('name', $newName)->whereKeyNot($queue->id)->exists()) {
            throw new RuntimeException("Queue [{$newName}] already exists in this team.");
        }

        $queue->update(['name' => $newName]);

        if ($values !== []) {
            $updated = $this->connection()->table('queues')
                ->where($this->identifierColumn(), $queue->asterisk_name)
                ->update($values);

            if ($updated === 0 && ! $this->connection()->table('queues')->where($this->identifierColumn(), $queue->asterisk_name)->exists()) {
                throw new RuntimeException("Asterisk queue [{$queue->asterisk_name}] not found.");
            }
        }

        return $queue->refresh();
    }

    /**
     * Write editable columns as given, empty values included (null clears a
     * setting, which update() cannot do). Unknown columns are ignored.
     *
     * @param  array<string, mixed>  $values
     */
    public function setColumns(CallQueue $queue, array $values): void
    {
        $columns = array_flip(array_intersect(self::EDITABLE, $this->asteriskColumns('queues')));
        $values = array_map(fn ($value) => $value === '' ? null : $value, array_intersect_key($values, $columns));

        if ($values !== []) {
            $this->connection()->table('queues')
                ->where($this->identifierColumn(), $queue->asterisk_name)
                ->update($values);
        }
    }

    public function delete(Team $team, int $id): void
    {
        $queue = $team->callQueues()->findOrFail($id);
        $queue->setRelation('team', $team);

        $this->connection()->table('queue_members')->where('queue_name', $queue->asterisk_name)->delete();
        $this->connection()->table('queues')->where($this->identifierColumn(), $queue->asterisk_name)->delete();
        $this->dialplan->removeQueue($queue);

        // queue_memberships and queue_hours cascade with the call_queues row.
        $queue->delete();
    }

    /**
     * Remove every Asterisk queue (and its members) of a tenant.
     */
    public function deleteTeam(Team $team): void
    {
        $names = $team->callQueues()->pluck('asterisk_name')->all();

        if ($names === []) {
            return;
        }

        $this->connection()->table('queue_members')->whereIn('queue_name', $names)->delete();
        $this->connection()->table('queues')->whereIn($this->identifierColumn(), $names)->delete();
    }

    private function uniqueAsteriskName(Team $team, string $slug): string
    {
        $base = substr($team->asterisk_key.'_'.(trim($slug, '-_') ?: 'queue'), 0, 80);

        $candidate = $base;
        $i = 2;

        while (CallQueue::query()->where('asterisk_name', $candidate)->exists()) {
            $suffix = '_'.$i++;
            $candidate = substr($base, 0, 80 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /**
     * Queues created without a « ringinuse » value use Asterisk's default
     * (yes): agents already on a call get a second one. Set them to no.
     */
    public function ensureNoRingInUse(Team $team): int
    {
        if (! in_array('ringinuse', $this->asteriskColumns('queues'), true)) {
            return 0;
        }

        return $this->connection()->table('queues')
            ->whereIn($this->identifierColumn(), $this->names($team))
            // ringinuse is an enum (yesno_values) in Asterisk's PostgreSQL schema:
            // an unset value is NULL, never '' (comparing to '' is an error).
            ->whereNull('ringinuse')
            ->update(['ringinuse' => 'no']);
    }

    /**
     * Queues with no answer threshold get the usual 20 s when a team turns
     * service level tracking on (Asterisk computes nothing at 0).
     */
    public function ensureServiceLevelThreshold(Team $team, int $seconds = 20): int
    {
        if (! in_array('servicelevel', $this->asteriskColumns('queues'), true)) {
            return 0;
        }

        return $this->connection()->table('queues')
            ->whereIn($this->identifierColumn(), $this->names($team))
            ->where(fn ($q) => $q->whereNull('servicelevel')->orWhere('servicelevel', 0))
            ->update(['servicelevel' => $seconds]);
    }

    private function identifierColumn(): string
    {
        $columns = $this->asteriskColumns('queues');

        if (in_array('name', $columns, true)) {
            return 'name';
        }

        if (in_array('queue_name', $columns, true)) {
            return 'queue_name';
        }

        throw new RuntimeException('Asterisk realtime queues table has no name/queue_name column.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function onlyEditable(array $data): array
    {
        $columns = array_flip(array_intersect(self::EDITABLE, $this->asteriskColumns('queues')));

        return array_intersect_key(
            array_filter($data, fn ($value) => $value !== null && $value !== ''),
            $columns,
        );
    }
}
