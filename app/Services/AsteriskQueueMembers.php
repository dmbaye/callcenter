<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\Team;
use App\Models\User;
use App\Services\Concerns\UsesAsteriskConnection;
use Illuminate\Support\Facades\Cache;

/**
 * An agent's presence in the Asterisk realtime queue_members table.
 *
 * Presence follows the softphone: the browser joins after its SIP
 * registration succeeds, sends a heartbeat while it stays registered and
 * leaves when it unregisters. agents:prune-presence removes members whose
 * heartbeat stopped (closed tab, expired session, crashed browser).
 */
class AsteriskQueueMembers
{
    use UsesAsteriskConnection;

    public const OFFLINE = 'offline';

    public const AVAILABLE = 'available';

    public const PAUSED = 'paused';

    public function interfaceFor(User $user, ?Team $team = null): ?string
    {
        $extension = $user->phoneExtensionFor($team);

        return $extension ? 'PJSIP/'.$extension->asteriskName() : null;
    }

    /**
     * Add the agent to every queue of the team.
     *
     * With $pause (the agent's chosen state: paused + Asterisk reason) every
     * row is set to it. Without, an existing pause is kept and new queues
     * inherit it: re-joining (heartbeat, second tab, new queue assignment)
     * must never un-pause.
     *
     * @param  array{paused: bool, reason: string}|null  $pause
     */
    public function join(User $user, ?Team $team = null, ?array $pause = null): void
    {
        $team ??= $user->currentTeam;
        $interface = $team ? $this->interfaceFor($user, $team) : null;

        if (! $team || $interface === null) {
            return;
        }

        $rows = $this->connection()->table('queue_members')
            ->where('interface', $interface)
            ->get(['queue_name', 'paused', 'reason_paused']);
        $existing = $rows->pluck('queue_name')->all();

        $current = $rows->first(fn ($row) => (int) $row->paused === 1);
        $forNewRows = $pause ?? ['paused' => $current !== null, 'reason' => (string) ($current->reason_paused ?? '')];

        foreach ($user->teamQueues($team) as $queue) {
            $values = [
                'membername' => $user->name,
                'penalty' => (int) ($queue->pivot->penalty ?? 0),
            ];

            if ($pause !== null) {
                $values['paused'] = $pause['paused'] ? 1 : 0;
                $values['reason_paused'] = $pause['paused'] ? $pause['reason'] : '';
            }

            if (in_array($queue->asterisk_name, $existing, true)) {
                $this->connection()->table('queue_members')
                    ->where('queue_name', $queue->asterisk_name)
                    ->where('interface', $interface)
                    ->update($values);

                continue;
            }

            $this->connection()->table('queue_members')->insert([
                ...$values,
                'queue_name' => $queue->asterisk_name,
                'interface' => $interface,
                // Asterisk's realtime PostgreSQL schema requires uniqueid.
                'uniqueid' => $this->memberUniqueId($queue->asterisk_name, $interface),
                'paused' => $forNewRows['paused'] ? 1 : 0,
                'reason_paused' => $forNewRows['paused'] ? $forNewRows['reason'] : '',
            ]);
        }

        // Queues the agent is no longer assigned to.
        $this->connection()->table('queue_members')
            ->where('interface', $interface)
            ->whereIn('queue_name', $team->callQueues()->pluck('asterisk_name')->all())
            ->whereNotIn('queue_name', $user->asteriskQueueNames($team))
            ->delete();

        $this->touch($user, $team);
    }

    /**
     * Set who answers a queue (user id => penalty: 0 = primary, higher =
     * only when the lower levels are busy). Agents already online are
     * re-joined at once so the change applies without a new login.
     * Used by queue settings and by campaigns (whose agents are queue members).
     *
     * @param  array<int, int>  $penalties
     */
    public function syncQueueAgents(CallQueue $queue, array $penalties): void
    {
        $before = $queue->members()->pluck('queue_memberships.penalty', 'users.id')->map(fn ($p) => (int) $p)->all();

        $queue->members()->sync(collect($penalties)->map(fn ($penalty) => ['penalty' => max(0, (int) $penalty)])->all());

        $changed = collect(array_keys($before + $penalties))
            ->filter(fn (int $id) => ($before[$id] ?? null) !== (isset($penalties[$id]) ? max(0, (int) $penalties[$id]) : null))
            ->values()
            ->all();

        $team = $queue->team;

        foreach (User::query()->whereIn('id', $changed)->get() as $user) {
            try {
                if ($this->status($user, $team) !== self::OFFLINE) {
                    $this->join($user, $team);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    /** Remove the agent from every queue of the team. */
    public function leave(User $user, ?Team $team = null): void
    {
        $team ??= $user->currentTeam;
        $interface = $team ? $this->interfaceFor($user, $team) : null;

        if (! $team || $interface === null) {
            return;
        }

        $this->leaveInterface($interface);
        Cache::forget($this->heartbeatKey($user, $team));
    }

    /** Remove an endpoint from every queue (endpoints are tenant-scoped, so this never crosses teams). */
    public function leaveInterface(string $interface): void
    {
        $this->connection()->table('queue_members')->where('interface', $interface)->delete();
    }

    public function setPaused(User $user, bool $paused, ?Team $team = null, string $reason = 'Pause'): void
    {
        $team ??= $user->currentTeam;
        $interface = $team ? $this->interfaceFor($user, $team) : null;

        if (! $team || $interface === null) {
            return;
        }

        $this->connection()->table('queue_members')
            ->where('interface', $interface)
            ->whereIn('queue_name', $user->asteriskQueueNames($team))
            ->update([
                'paused' => $paused ? 1 : 0,
                'reason_paused' => $paused ? $reason : '',
            ]);
    }

    public function status(User $user, ?Team $team = null): string
    {
        $team ??= $user->currentTeam;

        return $team ? ($this->statusesFor([$user], $team)[$user->id] ?? self::OFFLINE) : self::OFFLINE;
    }

    /**
     * Queue presence of many users with a single query. Users should have
     * phoneExtensions and callQueues eager loaded (see TeamAgents).
     *
     * @param  iterable<User>  $users
     * @return array<int, string> user id => offline|available|paused
     */
    public function statusesFor(iterable $users, Team $team): array
    {
        $targets = collect($users)->mapWithKeys(fn (User $user) => [$user->id => [
            'interface' => $this->interfaceFor($user, $team),
            'queues' => $user->asteriskQueueNames($team),
        ]]);

        $interfaces = $targets->pluck('interface')->filter()->unique()->values()->all();
        $queueNames = $targets->flatMap(fn (array $target) => $target['queues'])->unique()->values()->all();

        $byInterface = $interfaces === [] || $queueNames === []
            ? collect()
            : $this->connection()->table('queue_members')
                ->whereIn('interface', $interfaces)
                ->whereIn('queue_name', $queueNames)
                ->get(['queue_name', 'interface', 'paused'])
                ->groupBy('interface');

        return $targets->map(function (array $target) use ($byInterface): string {
            $rows = $target['interface'] === null ? collect() : $byInterface->get($target['interface'], collect())
                ->filter(fn ($row) => in_array($row->queue_name, $target['queues'], true));

            return match (true) {
                $rows->isEmpty() => self::OFFLINE,
                $rows->contains(fn ($row) => (int) $row->paused === 0) => self::AVAILABLE,
                default => self::PAUSED,
            };
        })->all();
    }

    /** Record that the agent's softphone is still registered. */
    public function touch(User $user, Team $team): void
    {
        Cache::put($this->heartbeatKey($user, $team), now()->getTimestamp(), now()->addDay());
    }

    public function lastSeen(User $user, Team $team): ?int
    {
        $value = Cache::get($this->heartbeatKey($user, $team));

        return $value === null ? null : (int) $value;
    }

    private function heartbeatKey(User $user, Team $team): string
    {
        return "presence:{$team->id}:{$user->id}";
    }

    private function memberUniqueId(string $queueName, string $interface): int
    {
        // The stock realtime PostgreSQL schema uses INTEGER for uniqueid:
        // keep it positive and deterministic, and walk past CRC32 collisions.
        $candidate = max(1, crc32($queueName.'|'.$interface) & 0x7FFFFFFF);

        while ($this->connection()->table('queue_members')->where('uniqueid', $candidate)->exists()) {
            $candidate = $candidate >= 2147483647 ? 1 : $candidate + 1;
        }

        return $candidate;
    }
}
