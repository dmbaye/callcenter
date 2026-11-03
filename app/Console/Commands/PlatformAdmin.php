<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Platform admins run the platform itself (trunk, carrier settings), above
 * the owners of each team. Granted from the server only.
 */
class PlatformAdmin extends Command
{
    protected $signature = 'platform:admin
                            {email : Email of the user}
                            {--revoke : Remove the platform admin right}';

    protected $description = 'Grant or revoke the platform admin right (Administration → Plateforme)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with this email.');

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();

        $this->info($this->option('revoke')
            ? "{$user->email} is no longer a platform admin."
            : "{$user->email} is now a platform admin.");

        return self::SUCCESS;
    }
}
