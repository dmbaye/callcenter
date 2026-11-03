<?php

namespace App\Events;

use App\Models\CampaignAttempt;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** The agent's campaign call ended (with its result): the workspace moves to wrap-up. */
class CampaignAttemptEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public CampaignAttempt $attempt) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->attempt->user_id}")];
    }

    public function broadcastAs(): string
    {
        return 'campaign.ended';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'attemptId' => $this->attempt->id,
            'result' => $this->attempt->result?->value,
            'resultLabel' => $this->attempt->result?->label(),
        ];
    }
}
