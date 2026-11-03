<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerDevProcesses();
    }

    /**
     * `composer dev` / `php artisan dev` also starts the call center daemons:
     * without them there is no live view, no push, no scheduler and no
     * progressive/power dialing. In production they run as systemd services
     * (deploy/*.service).
     */
    protected function registerDevProcesses(): void
    {
        if (! $this->app->environment('local') || ! class_exists(DevCommands::class)) {
            return;
        }

        DevCommands::artisan('schedule:work', 'schedule');
        DevCommands::artisan('asterisk:ami-listen', 'ami')->purple();
        DevCommands::artisan('reverb:start', 'reverb')->orange();
        DevCommands::artisan('campaigns:dial', 'dialer')->yellow();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Never allow migrate:fresh & co. when the app's own tables live in the Asterisk database.
        DB::prohibitDestructiveCommands(
            app()->isProduction() || $this->appSharesAsteriskDatabase(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Whether the default connection points at the same database as the Asterisk one.
     */
    protected function appSharesAsteriskDatabase(): bool
    {
        $app = config('database.connections.'.config('database.default'));
        $asterisk = config('database.connections.'.config('asterisk.connection'));

        if (! is_array($app) || ! is_array($asterisk)) {
            return false;
        }

        return ($app['driver'] ?? null) === ($asterisk['driver'] ?? null)
            && ($app['host'] ?? null) === ($asterisk['host'] ?? null)
            && ($app['database'] ?? null) === ($asterisk['database'] ?? null);
    }
}
