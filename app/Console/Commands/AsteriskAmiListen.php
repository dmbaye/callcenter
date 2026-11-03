<?php

namespace App\Console\Commands;

use App\Services\AsteriskAmiListener;
use App\Services\CampaignCallEvents;
use App\Services\InboundCallEvents;
use App\Services\MonitoringNotifier;
use Illuminate\Console\Command;

class AsteriskAmiListen extends Command
{
    protected $signature = 'asterisk:ami-listen
                            {--interval=2 : Seconds between live AMI snapshot refreshes}
                            {--socket= : Override the Unix socket path}';

    protected $description = 'Run the long-lived Asterisk AMI listener daemon';

    public function handle(AsteriskAmiListener $listener, MonitoringNotifier $notifier, CampaignCallEvents $campaignEvents, InboundCallEvents $inboundEvents): int
    {
        $listener->run(
            max(1, (int) $this->option('interval')),
            $this->option('socket') ?: null,
            fn (string $message) => $this->line($message),
            fn (array $keys) => $notifier->notifyKeys($keys),
            function (array $event) use ($campaignEvents, $inboundEvents): void {
                $campaignEvents->handle($event);
                $inboundEvents->handle($event);
            },
        );

        return self::SUCCESS;
    }
}
