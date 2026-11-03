<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Shared notes changed: other open editors show the new text. */
class MeetingNotesUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Meeting $meeting) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('meeting.'.$this->meeting->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.notes';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'notes' => (string) $this->meeting->notes,
            'updatedAt' => $this->meeting->notes_updated_at?->toIso8601String(),
            'updatedBy' => $this->meeting->notesEditor?->name,
            'updatedById' => $this->meeting->notes_updated_by,
        ];
    }
}
