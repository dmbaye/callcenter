<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\DialMode;
use App\Models\Campaign;
use App\Services\AsteriskAmi;
use App\Services\CampaignDialer;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * The outbound dialer daemon: paces progressive/power campaigns every few
 * seconds and cleans up stale calls and reservations. Calls themselves are
 * placed by the AMI daemon (asterisk:ami-listen), which must be running.
 *
 * Nothing is dialled? `php artisan campaigns:dial --explain` lists, for each
 * running automatic campaign, what stops it.
 */
class CampaignsDial extends Command
{
    protected $signature = 'campaigns:dial
                            {--once : Run a single round and exit}
                            {--explain : Show why each running progressive/power campaign is (not) dialling, then exit}';

    protected $description = 'Run the outbound campaign dialer';

    public function handle(CampaignDialer $dialer, AsteriskAmi $ami): int
    {
        if ($this->option('explain')) {
            return $this->explain($dialer, $ami);
        }

        $interval = max(1, (int) config('asterisk.campaigns.tick_seconds', 2));
        $this->info('Campaign dialer running (every '.$interval.' s). Ctrl+C to stop.');

        do {
            $started = microtime(true);

            try {
                $placed = array_sum($dialer->tick());

                if ($placed > 0) {
                    $this->line(now()->format('H:i:s')." placed {$placed} call(s)");
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error($exception->getMessage());
            }

            if ($this->option('once')) {
                break;
            }

            usleep((int) max(0, ($interval - (microtime(true) - $started)) * 1_000_000));
        } while (true);

        return self::SUCCESS;
    }

    private function explain(CampaignDialer $dialer, AsteriskAmi $ami): int
    {
        $this->line(CampaignDialer::isRunning()
            ? '<info>✓</info> campaigns:dial is running.'
            : '<error>✗</error> campaigns:dial is NOT running: nothing is dialled until it runs (systemd callcenter-campaign-dialer, or composer dev).');

        try {
            $members = collect($ami->snapshot()['queues']['members'] ?? []);
            $this->line('<info>✓</info> AMI listener reachable ('.$members->count().' queue member(s) seen).');
        } catch (RuntimeException $exception) {
            $members = null;
            $this->line('<error>✗</error> '.$exception->getMessage());
        }

        $campaigns = Campaign::query()
            ->with(['team.subscription', 'queue'])
            ->whereIn('dial_mode', [DialMode::Progressive, DialMode::Power])
            ->whereIn('status', [CampaignStatus::Running, CampaignStatus::Draft, CampaignStatus::Paused])
            ->orderBy('id')
            ->get();

        if ($campaigns->isEmpty()) {
            $this->warn('No progressive or power campaign.');

            return self::SUCCESS;
        }

        foreach ($campaigns as $campaign) {
            $this->newLine();
            $this->line("<comment>#{$campaign->id} {$campaign->name}</comment> ({$campaign->dial_mode->label()}, {$campaign->status->label()}, team {$campaign->team?->slug})");

            $reasons = $dialer->diagnose($campaign, $members);

            if ($reasons === []) {
                $this->line('  <info>✓</info> Ready: '.$dialer->freeAgents($campaign, $members ?? collect()).' free agent(s), '.$dialer->callable($campaign)->count().' contact(s) to call.');

                continue;
            }

            foreach ($reasons as $reason) {
                $this->line('  <error>✗</error> '.$reason);
            }
        }

        return self::SUCCESS;
    }
}
