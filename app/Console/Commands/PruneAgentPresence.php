<?php

namespace App\Console\Commands;

use App\Models\PhoneExtension;
use App\Services\AsteriskQueueMembers;
use App\Services\MonitoringNotifier;
use Illuminate\Console\Command;

class PruneAgentPresence extends Command
{
    protected $signature = 'agents:prune-presence
                            {--stale= : Seconds without heartbeat before an agent is removed (defaults to asterisk.presence.stale_after)}';

    protected $description = 'Remove agents from Asterisk queues when their softphone heartbeat stopped';

    public function handle(AsteriskQueueMembers $members, MonitoringNotifier $notifier): int
    {
        $staleAfter = (int) ($this->option('stale') ?: config('asterisk.presence.stale_after', 180));
        $cutoff = now()->getTimestamp() - $staleAfter;
        $interfaces = $members->connection()->table('queue_members')->distinct()->pluck('interface');

        if ($interfaces->isEmpty()) {
            return self::SUCCESS;
        }

        $removed = 0;

        PhoneExtension::query()
            ->with(['team', 'user'])
            ->whereNotNull('user_id')
            ->get()
            ->filter(fn (PhoneExtension $phone) => $phone->team && $interfaces->contains('PJSIP/'.$phone->asteriskName()))
            ->each(function (PhoneExtension $phone) use ($members, $notifier, $cutoff, &$removed) {
                $lastSeen = $members->lastSeen($phone->user, $phone->team);

                if ($lastSeen !== null && $lastSeen >= $cutoff) {
                    return;
                }

                $members->leaveInterface('PJSIP/'.$phone->asteriskName());
                $notifier->notifyTeam($phone->team);
                $removed++;
            });

        $this->info("Removed {$removed} stale agent(s) from their queues.");

        return self::SUCCESS;
    }
}
