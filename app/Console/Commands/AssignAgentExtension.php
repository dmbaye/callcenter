<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class AssignAgentExtension extends Command
{
    protected $signature = 'agents:assign
                            {email : Email of the user}
                            {extension : Digits only, e.g. 7002}
                            {--team= : Team slug or ID (defaults to the user\'s current team)}
                            {--secret= : SIP secret (generated when omitted)}';

    protected $description = 'Assign a team-scoped SIP extension to a user and provision its PJSIP endpoint';

    public function handle(AsteriskProvisioner $provisioner): int
    {
        $extension = (string) $this->argument('extension');

        if (! preg_match('/^\d{2,20}$/', $extension)) {
            $this->error('The extension must be 2 to 20 digits.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $option = $this->option('team');
        $team = $option
            ? Team::query()->where('slug', $option)->orWhere('id', is_numeric($option) ? (int) $option : 0)->first()
            : $user->currentTeam;

        if (! $team || ! $user->belongsToTeam($team)) {
            $this->error('Specify a team the user belongs to with --team=.');

            return self::FAILURE;
        }

        $taken = $team->phoneExtensions()->where('extension', $extension)->first();

        if ($taken && $taken->user_id !== null && $taken->user_id !== $user->id) {
            $this->error("Extension {$extension} is already assigned in team [{$team->slug}].");

            return self::FAILURE;
        }

        $secret = $this->option('secret') ?: Str::password(24, symbols: false);

        try {
            $phone = $provisioner->saveExtension($team, $extension, [
                'user_id' => $user->id,
                'label' => $user->name,
                'secret' => $secret,
            ]);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Extension {$extension} assigned to {$user->email} in [{$team->slug}].");
        $this->line("Asterisk endpoint: {$phone->asteriskName()}");

        if (! $this->option('secret')) {
            $this->warn("Generated SIP secret: {$secret}");
        }

        return self::SUCCESS;
    }
}
