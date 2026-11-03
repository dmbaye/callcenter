<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// Agents whose softphone stopped sending heartbeats (closed tab, expired
// session, crash) leave the Asterisk queues.
Schedule::command('agents:prune-presence')->everyMinute()->withoutOverlapping();

// Renewal reminders, expiry (read-only + agents leave the queues) and
// reconciliation of CinetPay payments whose notification never arrived.
Schedule::command('billing:process')->hourly()->withoutOverlapping();

// Rewrites queue entries daily so closure days (day/month checks) never repeat next year.
Schedule::command('asterisk:sync-queues')->dailyAt('00:05')->withoutOverlapping();

// Call recordings are kept for the plan's recording_days.
Schedule::command('recordings:prune')->dailyAt('03:15')->withoutOverlapping();

// In-app reminder a few minutes before planned video meetings.
Schedule::command('meetings:remind')->everyMinute()->withoutOverlapping();

// Video meetings close when they reach the time limit of the team's plan.
Schedule::command('meetings:enforce-limits')->everyMinute()->withoutOverlapping();

// Shift swaps whose shift started without a final answer.
Schedule::call(fn () => app(App\Services\Workforce\ShiftSwaps::class)->expireStarted())
    ->hourly()->description('Expire open shift swaps');
