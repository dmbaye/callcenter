<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AsteriskQueueMembers;
use App\Services\MonitoringNotifier;
use Illuminate\Auth\Events\Logout;

/**
 * Logging out takes the agent out of the current team's queues. (Joining
 * is done by the softphone once it is registered, not at login.)
 */
class RemoveAgentFromAsteriskQueues
{
    public function __construct(
        private AsteriskQueueMembers $members,
        private MonitoringNotifier $notifier,
    ) {}

    public function handle(Logout $event): void
    {
        if ($event->user instanceof User && $event->user->currentTeam) {
            $this->members->leave($event->user, $event->user->currentTeam);
            $this->notifier->notifyTeam($event->user->currentTeam);
        }
    }
}
