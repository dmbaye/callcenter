<?php

use App\Models\Team;
use App\Services\CallStats;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
    $this->team = Team::factory()->create();
});

function directionLeg(Team $team, string $channel, string $dstchannel, string $dcontext): void
{
    DB::table('cdr')->insert([
        'uniqueid' => uniqid(),
        'linkedid' => uniqid(),
        'clid' => '"Awa" <22376000001>',
        'src' => '22376000001',
        'dst' => 's',
        'channel' => $channel,
        'dstchannel' => $dstchannel,
        'dcontext' => $dcontext,
        'start' => now()->toDateTimeString(),
        'duration' => 30,
        'billsec' => 20,
        'disposition' => 'ANSWERED',
        'accountcode' => $team->asterisk_key,
    ]);
}

test('queue calls are inbound, campaign calls are outbound even though the agent is rung', function () {
    $agent = 'PJSIP/'.$this->team->asterisk_key.'_7001-0001';

    directionLeg($this->team, 'PJSIP/trunk-0001', $agent, 't_'.$this->team->asterisk_key);
    directionLeg($this->team, 'PJSIP/trunk-0002', $agent, config('asterisk.campaigns.context', 'cc-campaign'));
    directionLeg($this->team, $agent, 'PJSIP/trunk-0003', 't_'.$this->team->asterisk_key);

    $directions = app(CallStats::class)
        ->legs($this->team, now()->subHour(), now()->addHour())
        ->pluck('direction')
        ->sort()
        ->values()
        ->all();

    expect($directions)->toBe(['inbound', 'outbound', 'outbound']);
});
