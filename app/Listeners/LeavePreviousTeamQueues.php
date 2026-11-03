<?php

namespace App\Listeners;

use App\Events\CurrentTeamSwitched;
use App\Services\AsteriskQueueMembers;
use App\Services\MonitoringNotifier;

/**
 * An agent works in one team at a time: switching teams takes them out of
 * the previous team's queues. The softphone re-registers with the new
 * team's extension and joins its queues.
 */
class LeavePreviousTeamQueues
{
    public function __construct(
        private AsteriskQueueMembers $members,
        private MonitoringNotifier $notifier,
    ) {}

    public function handle(CurrentTeamSwitched $event): void
    {
        if ($event->previous === null || $event->previous->is($event->current)) {
            return;
        }

        $this->members->leave($event->user, $event->previous);
        $this->notifier->notifyTeam($event->previous);
    }
}
