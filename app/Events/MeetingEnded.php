<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Tells the people in the room why it closed (time limit, organizer). */
class MeetingEnded implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public Meeting $meeting) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('meeting.'.$this->meeting->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.ended';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['reason' => $this->meeting->ended_reason];
    }
}
