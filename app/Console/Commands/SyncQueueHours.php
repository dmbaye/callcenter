<?php

namespace App\Console\Commands;

use App\Models\CallQueue;
use App\Models\Team;
use App\Services\AsteriskDialplan;
use App\Services\AsteriskProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

class SyncQueueHours extends Command
{
    protected $description = 'Write every queue entry extension (accountcode, opening hours, Queue()) into its team dialplan context';

    /** Former names: opening-hours sync and the global transfer context, both replaced by this command. */
    protected $aliases = ['queues:sync-hours', 'dialplan:install-transfer'];

    protected $signature = 'asterisk:sync-queues
                            {--team= : Only this team (slug or ID)}
                            {--queue= : Only this queue (display name or Asterisk name)}
                            {--dry-run : Show the rows that would be written without touching the database}
                            {--reload : Run "dialplan reload" through AMI afterwards}';

    public function handle(AsteriskDialplan $dialplan, AsteriskProvisioner $provisioner): int
    {
        $teamOption = $this->option('team');
        $queueOption = $this->option('queue');

        $queues = CallQueue::query()
            ->with(['team', 'hours'])
            ->whereHas('team')
            ->when($teamOption, fn ($query) => $query->whereIn('team_id', Team::query()
                ->where('slug', $teamOption)
                ->orWhere('id', is_numeric($teamOption) ? (int) $teamOption : 0)
                ->select('id')))
            ->when($queueOption, fn ($query) => $query->where(fn ($query) => $query
                ->where('name', $queueOption)
                ->orWhere('asterisk_name', $queueOption)))
            ->orderBy('team_id')
            ->orderBy('name')
            ->get();

        if ($queues->isEmpty()) {
            $this->warn('No matching queues.');

            return self::SUCCESS;
        }

        foreach ($queues as $queue) {
            try {
                $rows = $dialplan->syncQueue($queue, dryRun: (bool) $this->option('dry-run'));
            } catch (RuntimeException $exception) {
                $this->error("[{$queue->asterisk_name}] {$exception->getMessage()}");

                return self::FAILURE;
            }

            $this->components->info(($this->option('dry-run') ? 'Would write' : 'Synced')." [{$queue->team->asteriskContext()}] / [{$queue->asterisk_name}] ({$queue->name})");
            $this->table(['priority', 'app', 'appdata'], array_map(
                fn (array $row): array => [$row['priority'], $row['app'], $row['appdata']],
                $rows,
            ));
        }

        if (! $this->option('dry-run') && $this->option('reload')) {
            $provisioner->reloadDialplan();
            $this->components->info('Dialplan reload requested.');
        }

        return self::SUCCESS;
    }
}
