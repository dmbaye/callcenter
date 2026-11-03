<?php

namespace App\Events;

use App\Models\CampaignAttempt;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Screen pop: a campaign customer was just connected to this agent.
 * Only goes to the agent's own private channel.
 */
class CampaignCallConnected implements ShouldBroadcastNow
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
        return 'campaign.connected';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $campaign = $this->attempt->campaign;

        return [
            'attemptId' => $this->attempt->id,
            'campaignId' => $campaign->id,
            'campaign' => $campaign->name,
            'contact' => $this->attempt->contact->fullName() ?: $this->attempt->phone,
            'url' => route('campaigns.workspace', ['current_team' => $campaign->team->slug, 'attempt' => $this->attempt->id]),
        ];
    }
}
