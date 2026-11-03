<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\AsteriskDialplan;
use Illuminate\Console\Command;

/**
 * One-off after upgrading: rewrites every team's routing so an agent who is
 * already on a call is never offered a second one (queues with ringinuse
 * left empty, and direct extension dialing without a busy check).
 */
class AsteriskBusyAgents extends Command
{
    protected $signature = 'asterisk:busy-agents';

    protected $description = 'Stop ringing agents who are already on a call (all teams)';

    public function handle(AsteriskDialplan $dialplan): int
    {
        $failed = 0;

        Team::query()->whereNotNull('asterisk_key')->orderBy('id')->each(function (Team $team) use ($dialplan, &$failed): void {
            try {
                $dialplan->syncTeamRouting($team);
                $this->line("✓ {$team->name}");
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("✗ {$team->name}: {$exception->getMessage()}");
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
