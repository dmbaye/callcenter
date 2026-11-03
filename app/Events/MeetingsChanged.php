<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A meeting of the team started, ended or changed: the list reloads. Carries no data. */
class MeetingsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $teamId) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->teamId}.meetings")];
    }

    public function broadcastAs(): string
    {
        return 'meetings.changed';
    }

    /** @return array{at: int} */
    public function broadcastWith(): array
    {
        return ['at' => time()];
    }
}
