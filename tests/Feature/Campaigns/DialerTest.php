<?php

use App\Enums\AttemptResult;
use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\DialMode;
use App\Enums\TeamRole;
use App\Models\CampaignAttempt;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskAmi;
use App\Services\CampaignCallEvents;
use App\Services\CampaignDialer;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->agentA = teamAgent($this->team, '7001');
    $this->agentB = teamAgent($this->team, '7002');
    $this->campaign = teamCampaign($this->team, $this->owner, [$this->agentA, $this->agentB]);
    $this->campaign->update(['status' => CampaignStatus::Running]);
    campaignContacts($this->campaign, ['22376000001', '22376000002', '22376000003', '22376000004', '22376000005']);
});

/** AMI QueueMember entries for the campaign queue. */
function queueMembers(string $queue, string $key, array $extensions, string $status = '1', string $paused = '0')
{
    return collect($extensions)->map(fn ($ext) => [
        'Queue' => $queue,
        'StateInterface' => "PJSIP/{$key}_{$ext}",
        'Status' => $status,
        'Paused' => $paused,
        'InCall' => '0',
    ]);
}

it('places one call per free agent in progressive mode', function () {
    $calls = [];
    $this->mock(AsteriskAmi::class)->shouldReceive('originateCampaignCall')->twice()->andReturnUsing(function ($call) use (&$calls) {
        $calls[] = $call;
    });

    $placed = app(CampaignDialer::class)->pace(
        $this->campaign->fresh(['team', 'queue']),
        queueMembers($this->campaign->queue->asterisk_name, $this->team->asterisk_key, ['7001', '7002']),
    );

    expect($placed)->toBe(2)
        ->and($this->campaign->contacts()->where('status', ContactStatus::Dialing)->count())->toBe(2)
        ->and($this->campaign->attempts()->count())->toBe(2)
        ->and($calls[0]['channel'])->toBe('PJSIP/0022376000001@orange')
        ->and($calls[0]['exten'])->toBe('s')
        ->and($calls[0]['account'])->toBe($this->team->asterisk_key)
        ->and($calls[0]['variables']['CC_QUEUE'])->toBe($this->campaign->queue->asterisk_name);

    // Same agents still free, two calls already ringing: nothing more.
    expect(app(CampaignDialer::class)->pace(
        $this->campaign->fresh(['team', 'queue']),
        queueMembers($this->campaign->queue->asterisk_name, $this->team->asterisk_key, ['7001', '7002']),
    ))->toBe(0);
});

it('ignores paused, busy and qualifying agents', function () {
    $this->mock(AsteriskAmi::class)->shouldNotReceive('originateCampaignCall');
    $queue = $this->campaign->queue->asterisk_name;
    $key = $this->team->asterisk_key;

    // Agent A still has to qualify a connected call.
    $this->campaign->attempts()->create([
        'campaign_contact_id' => $this->campaign->contacts()->first()->id, 'team_id' => $this->team->id,
        'user_id' => $this->agentA->id, 'mode' => DialMode::Progressive, 'phone' => '1', 'status' => CampaignAttempt::ENDED,
        'result' => AttemptResult::Connected, 'connected_at' => now(), 'ended_at' => now(),
    ]);

    $members = queueMembers($queue, $key, ['7001'])
        ->merge(queueMembers($queue, $key, ['7002'], paused: '1'))
        ->merge(queueMembers($queue, $key, ['7003'], status: '2'));

    expect(app(CampaignDialer::class)->freeAgents($this->campaign->fresh(['queue']), $members))->toBe(0);
});

it('dials several lines per agent in power mode and falls back when abandons grow', function () {
    $this->campaign->update(['dial_mode' => DialMode::Power, 'dial_ratio' => 2.5]);
    $dialer = app(CampaignDialer::class);

    expect($dialer->effectiveRatio($this->campaign->fresh()))->toBe(2.5);

    $contact = $this->campaign->contacts()->first();
    foreach ([AttemptResult::Abandoned, AttemptResult::Connected] as $result) {
        $this->campaign->attempts()->create([
            'campaign_contact_id' => $contact->id, 'team_id' => $this->team->id, 'mode' => DialMode::Power,
            'phone' => $contact->phone, 'status' => CampaignAttempt::ENDED, 'result' => $result, 'ended_at' => now(),
        ]);
    }

    expect($dialer->abandonRate($this->campaign))->toBe(50.0)
        ->and($dialer->effectiveRatio($this->campaign->fresh()))->toBe(1.0);
});

it('never auto-dials Do-Not-Call numbers, personal callbacks or contacts not yet due', function () {
    $this->team->dncNumbers()->create(['phone' => '22376000001']);
    $this->campaign->contacts()->where('phone', '22376000002')->update(['status' => ContactStatus::Callback, 'callback_user_id' => $this->agentA->id, 'next_attempt_at' => now()->subMinute()]);
    $this->campaign->contacts()->where('phone', '22376000003')->update(['next_attempt_at' => now()->addHour()]);
    $this->campaign->contacts()->where('phone', '22376000004')->update(['attempts' => 3]);

    expect(app(CampaignDialer::class)->callable($this->campaign)->pluck('phone')->all())->toBe(['22376000005']);
});

it('puts the contact back when Asterisk cannot place the call', function () {
    $this->mock(AsteriskAmi::class)->shouldReceive('originateCampaignCall')->once()->andThrow(new RuntimeException('AMI down'));

    $placed = app(CampaignDialer::class)->pace(
        $this->campaign->fresh(['team', 'queue']),
        queueMembers($this->campaign->queue->asterisk_name, $this->team->asterisk_key, ['7001']),
    );

    expect($placed)->toBe(0)
        ->and($this->campaign->attempts()->count())->toBe(0)
        ->and($this->campaign->contacts()->where('status', ContactStatus::Pending)->where('attempts', 0)->count())->toBe(5);
});

it('follows a progressive call from answer to agent to wrap-up', function () {
    $this->mock(AsteriskAmi::class)->shouldReceive('originateCampaignCall')->once();
    app(CampaignDialer::class)->pace($this->campaign->fresh(['team', 'queue']), queueMembers($this->campaign->queue->asterisk_name, $this->team->asterisk_key, ['7001']));

    $attempt = $this->campaign->attempts()->sole();
    $events = app(CampaignCallEvents::class);

    $events->handle(['Event' => 'UserEvent', 'UserEvent' => 'CcCampaign', 'Attempt' => (string) $attempt->id, 'State' => 'answered', 'Uniqueid' => '1700.1']);
    $events->handle(['Event' => 'AgentConnect', 'Uniqueid' => '1700.1', 'Interface' => 'PJSIP/'.$this->team->asterisk_key.'_7002', 'Queue' => $this->campaign->queue->asterisk_name]);

    expect($attempt->fresh()->status)->toBe(CampaignAttempt::CONNECTED)
        ->and($attempt->fresh()->user_id)->toBe($this->agentB->id);

    $events->handle(['Event' => 'UserEvent', 'UserEvent' => 'CcCampaignEnd', 'Attempt' => (string) $attempt->id, 'Answered' => '1', 'Member' => 'PJSIP/x', 'Amd' => '', 'Dial' => '']);

    expect($attempt->fresh()->result)->toBe(AttemptResult::Connected)
        ->and($attempt->contact->fresh()->status)->toBe(ContactStatus::Wrapup);
});

it('schedules retries from call results and stops at the attempt limit', function () {
    $contact = $this->campaign->contacts()->first();
    $events = app(CampaignCallEvents::class);

    $newAttempt = function () use ($contact) {
        $contact->increment('attempts');

        return $this->campaign->attempts()->create([
            'campaign_contact_id' => $contact->id, 'team_id' => $this->team->id, 'mode' => DialMode::Progressive,
            'phone' => $contact->phone, 'status' => CampaignAttempt::DIALING,
        ]);
    };

    $busy = $newAttempt();
    $events->handle(['Event' => 'OriginateResponse', 'ActionID' => "cc-camp-{$busy->id}-ab12", 'Response' => 'Failure', 'Reason' => '5']);

    expect($busy->fresh()->result)->toBe(AttemptResult::Busy)
        ->and($contact->fresh()->status)->toBe(ContactStatus::Pending)
        ->and((int) round(now()->diffInMinutes($contact->fresh()->next_attempt_at)))->toBe(15);

    $abandoned = $newAttempt();
    $events->handle(['Event' => 'UserEvent', 'UserEvent' => 'CcCampaignEnd', 'Attempt' => (string) $abandoned->id, 'Answered' => '1', 'Member' => '', 'Amd' => '']);
    expect($abandoned->fresh()->result)->toBe(AttemptResult::Abandoned);

    $machine = $newAttempt();
    $events->handle(['Event' => 'UserEvent', 'UserEvent' => 'CcCampaignEnd', 'Attempt' => (string) $machine->id, 'Answered' => '1', 'Member' => '', 'Amd' => 'MACHINE']);

    expect($machine->fresh()->result)->toBe(AttemptResult::Machine)
        ->and($contact->fresh()->status)->toBe(ContactStatus::Exhausted);
});
