<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\AsteriskDialplan;
use Illuminate\Console\Command;

class ProvisionCampaignDialplan extends Command
{
    protected $signature = 'asterisk:campaign-dialplan';

    protected $description = 'Write the shared campaign, call recording and outside-call contexts to the Asterisk realtime dialplan';

    public function handle(AsteriskDialplan $dialplan): int
    {
        $rows = $dialplan->provisionCampaignContext();

        $this->info(count($rows).' rows written to context '.config('asterisk.campaigns.context').'.');
        $this->line('Declare it in extensions.conf (or use asterisk:contexts-config):');
        $this->line($dialplan->campaignConfigStanza());

        $rows = $dialplan->provisionRecordingContext();

        $this->info(count($rows).' rows written to context '.config('asterisk.recordings.context').'.');
        $this->line($dialplan->recordingConfigStanza());

        // Outside calls: the shared trunk route, then each team's _00X. entry.
        $rows = $dialplan->provisionOutboundContext();

        $this->info(count($rows).' rows written to context '.config('asterisk.outbound.context').'.');
        $this->line($dialplan->outboundConfigStanza());

        $teams = 0;
        Team::query()->whereNotNull('asterisk_key')->each(function (Team $team) use ($dialplan, &$teams): void {
            $dialplan->ensureOutboundDialing($team);
            $teams++;
        });

        $this->info("Outside-call route written for {$teams} team(s).");

        return self::SUCCESS;
    }
}
