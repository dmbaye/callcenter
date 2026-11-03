<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Something changed in this team's live call state."
 *
 * Deliberately carries no call data: supervisors refetch the authorized
 * /monitoring/status snapshot when they receive it, so nothing tenant-specific
 * ever travels over the WebSocket. Broadcast synchronously (the AMI daemon has
 * no queue worker in front of it); the HTTP client timeout is set in
 * config/broadcasting.php so an unreachable Reverb cannot stall the daemon.
 */
class MonitoringChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $teamId) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->teamId}.monitoring")];
    }

    public function broadcastAs(): string
    {
        return 'monitoring.changed';
    }

    /** @return array{at: int} */
    public function broadcastWith(): array
    {
        return ['at' => time()];
    }
}
