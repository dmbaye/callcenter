<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\AsteriskDialplan;
use App\Services\AsteriskProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

class ProvisionAsteriskTeam extends Command
{
    protected $signature = 'asterisk:provision-team
                            {team : Team slug or ID}
                            {--replace-dialplan : Rebuild the tenant dialplan context from scratch}';

    protected $description = 'Provision a team-scoped Asterisk dialplan, queue entries and PJSIP endpoints';

    public function handle(AsteriskProvisioner $provisioner, AsteriskDialplan $dialplan): int
    {
        $value = (string) $this->argument('team');
        $team = Team::query()->where('slug', $value)->orWhere('id', is_numeric($value) ? (int) $value : 0)->first();

        if (! $team) {
            $this->error('Team not found.');

            return self::FAILURE;
        }

        try {
            $result = $provisioner->provisionTeam($team, (bool) $this->option('replace-dialplan'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Dialplan context [{$team->asteriskContext()}] ready for key [{$team->asterisk_key}].");

        foreach ($result['created'] as $id) {
            $this->line("Created PJSIP [{$id}].");
        }

        foreach ($result['existing'] as $id) {
            $this->line("Updated PJSIP [{$id}].");
        }

        $this->table(['exten', 'priority', 'app', 'appdata'], array_map(fn (array $row) => [
            $row['exten'], $row['priority'], $row['app'], $row['appdata'],
        ], $result['dialplan']));

        $this->newLine();
        $this->line('Make sure Asterisk knows this context (php artisan asterisk:contexts-config):');
        $this->line($dialplan->configStanza($team));

        return self::SUCCESS;
    }
}
