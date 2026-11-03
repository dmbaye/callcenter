<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MigrateAsteriskTenancy extends Command
{
    protected $signature = 'asterisk:migrate-tenancy
                            {--dry-run : Show changes without modifying Asterisk}
                            {--keep-legacy : Keep old Asterisk endpoint/queue rows after copying}';

    protected $description = 'Migrate legacy extension/queue realtime rows into immutable team-scoped Asterisk names';

    public function handle(): int
    {
        $db = DB::connection(config('asterisk.connection'));
        $dryRun = (bool) $this->option('dry-run');
        $keepLegacy = (bool) $this->option('keep-legacy');

        $teams = Team::withTrashed()->with(['phoneExtensions', 'callQueues'])->get();
        $legacyQueueNames = [];

        foreach ($teams as $team) {
            $this->components->info("Team {$team->slug}: {$team->asterisk_key}");

            foreach ($team->phoneExtensions as $extension) {
                $old = $extension->extension;
                $new = $team->asterisk_key.'_'.$old;
                $this->migrateEndpoint($db, $old, $new, $team->asterisk_key, $dryRun, $keepLegacy);
            }

            foreach ($team->callQueues as $queue) {
                $legacyQueueNames[$queue->name] = true;
                $this->migrateQueue($db, $queue->name, $queue->asterisk_name, $team->asterisk_key, $team->phoneExtensions->pluck('extension')->all(), $dryRun);
            }
        }

        if (! $dryRun && ! $keepLegacy) {
            $column = $this->queueIdentifierColumn($db);
            foreach (array_keys($legacyQueueNames) as $legacyName) {
                $db->table('queues')->where($column, $legacyName)->delete();
            }
        }

        $this->info($dryRun ? 'Dry run complete; no Asterisk rows were changed.' : 'Asterisk tenancy migration complete.');

        return self::SUCCESS;
    }

    private function migrateEndpoint(ConnectionInterface $db, string $old, string $new, string $accountcode, bool $dryRun, bool $keepLegacy): void
    {
        $endpoint = $db->table('ps_endpoints')->where('id', $old)->first();
        $auth = $db->table('ps_auths')->where('id', $old)->first();
        $aor = $db->table('ps_aors')->where('id', $old)->first();

        if (! $endpoint && ! $auth && ! $aor) {
            $this->line("  endpoint {$old}: no legacy realtime row found");

            return;
        }

        $this->line("  endpoint {$old} -> {$new}");
        if ($dryRun) {
            return;
        }

        $columns = $db->getSchemaBuilder()->getColumnListing('ps_endpoints');
        $db->transaction(function () use ($db, $endpoint, $auth, $aor, $old, $new, $accountcode, $columns, $keepLegacy): void {
            if ($endpoint && ! $db->table('ps_endpoints')->where('id', $new)->exists()) {
                $row = (array) $endpoint;
                $row['id'] = $new;
                $row['aors'] = $new;
                $row['auth'] = $new;
                if (in_array('accountcode', $columns, true)) {
                    $row['accountcode'] = substr($accountcode, 0, 20);
                }
                $db->table('ps_endpoints')->insert($row);
            }

            if ($auth && ! $db->table('ps_auths')->where('id', $new)->exists()) {
                $row = (array) $auth;
                $row['id'] = $new;
                $row['username'] = $new;
                $db->table('ps_auths')->insert($row);
            }

            if ($aor && ! $db->table('ps_aors')->where('id', $new)->exists()) {
                $row = (array) $aor;
                $row['id'] = $new;
                $db->table('ps_aors')->insert($row);
            }

            $db->table('queue_members')
                ->where('interface', 'PJSIP/'.$old)
                ->update(['interface' => 'PJSIP/'.$new]);

            // Historical CDRs retain the tenant identity so accountcode-based
            // reporting remains complete after the migration.
            $db->table('cdr')
                ->where(function ($query) use ($old) {
                    $query->where('channel', 'like', 'PJSIP/'.$old.'-%')
                        ->orWhere('dstchannel', 'like', 'PJSIP/'.$old.'-%');
                })
                ->update(['accountcode' => $accountcode]);

            if (! $keepLegacy) {
                $db->table('ps_endpoints')->where('id', $old)->delete();
                $db->table('ps_auths')->where('id', $old)->delete();
                $db->table('ps_aors')->where('id', $old)->delete();
            }
        });
    }

    private function migrateQueue(ConnectionInterface $db, string $legacyName, string $newName, string $accountcode, array $extensions, bool $dryRun): void
    {
        $column = $this->queueIdentifierColumn($db);
        $source = $db->table('queues')->where($column, $legacyName)->first();

        if (! $source) {
            $this->line("  queue {$legacyName}: no legacy realtime row found");

            return;
        }

        $this->line("  queue {$legacyName} -> {$newName}");
        if ($dryRun) {
            return;
        }

        $db->transaction(function () use ($db, $column, $source, $legacyName, $newName, $extensions, $accountcode): void {
            if (! $db->table('queues')->where($column, $newName)->exists()) {
                $row = (array) $source;
                $row[$column] = $newName;
                if (array_key_exists('id', $row) && in_array('id', $db->getSchemaBuilder()->getColumnListing('queues'), true)) {
                    $idColumn = $db->getSchemaBuilder()->getColumnType('queues', 'id');
                    if ($idColumn === 'integer') {
                        unset($row['id']);
                    }
                }
                $db->table('queues')->insert($row);
            }

            if ($extensions !== []) {
                $db->table('queue_members')
                    ->where('queue_name', $legacyName)
                    ->whereIn('interface', array_map(fn (string $extension) => 'PJSIP/'.$accountcode.'_'.$extension, $extensions))
                    ->update(['queue_name' => $newName]);
            }
        });
    }

    private function queueIdentifierColumn(ConnectionInterface $db): string
    {
        $columns = $db->getSchemaBuilder()->getColumnListing('queues');
        if (in_array('name', $columns, true)) {
            return 'name';
        }
        if (in_array('queue_name', $columns, true)) {
            return 'queue_name';
        }

        throw new RuntimeException('Asterisk realtime queues table has no name/queue_name column.');
    }
}
