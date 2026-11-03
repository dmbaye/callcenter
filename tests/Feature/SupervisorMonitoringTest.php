<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\AsteriskAmi;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

beforeEach(function () {
    createAsteriskTables();

    $this->team = Team::factory()->create();
    $this->support = teamQueue($this->team, 'support');
});

function amiSnapshot(Team $team, array $channels, array $entries, array $queues, ?int $updatedAt = null): array
{
    return [
        'channels' => $channels,
        'queues' => ['entries' => $entries, 'queues' => $queues, 'members' => [], 'updated_at' => $updatedAt ?? now()->getTimestamp()],
    ];
}

it('allows team admins to monitor agents', function () {
    $admin = teamAgent($this->team, '7001', TeamRole::Admin);

    expect($admin->hasTeamPermission($this->team, TeamPermission::MonitorAgents))->toBeTrue();
});

it('does not allow team members to access supervisor monitoring', function () {
    $member = teamAgent($this->team, '7002');

    $this->actingAs($member)
        ->get(route('monitoring', $this->team))
        ->assertForbidden();
});

it('lets a supervisor monitor but not manage the team', function () {
    $supervisor = teamAgent($this->team, '7001', TeamRole::Supervisor);

    expect($supervisor->hasTeamPermission($this->team, TeamPermission::MonitorAgents))->toBeTrue()
        ->and($supervisor->hasTeamPermission($this->team, TeamPermission::UpdateTeam))->toBeFalse()
        ->and($supervisor->hasTeamPermission($this->team, TeamPermission::AddMember))->toBeFalse();
});

it('returns waiting calls, free agents and agents in communication', function () {
    $key = $this->team->asterisk_key;
    $queue = $this->support->asterisk_name;
    $supervisor = teamAgent($this->team, '7001', TeamRole::Supervisor, [$this->support]);
    $busy = teamAgent($this->team, '7002', queues: [$this->support]);
    $free = teamAgent($this->team, '7003', queues: [$this->support]);
    $paused = teamAgent($this->team, '7004', queues: [$this->support]);

    DB::table('queue_members')->insert([
        ['uniqueid' => 1, 'queue_name' => $queue, 'interface' => "PJSIP/{$key}_7001", 'paused' => 0],
        ['uniqueid' => 2, 'queue_name' => $queue, 'interface' => "PJSIP/{$key}_7002", 'paused' => 0],
        ['uniqueid' => 3, 'queue_name' => $queue, 'interface' => "PJSIP/{$key}_7003", 'paused' => 0],
        ['uniqueid' => 4, 'queue_name' => $queue, 'interface' => "PJSIP/{$key}_7004", 'paused' => 1],
    ]);

    $this->mock(AsteriskAmi::class, function (MockInterface $mock) use ($key, $queue) {
        $mock->shouldReceive('snapshot')->once()->andReturn(amiSnapshot($this->team,
            [['Channel' => "PJSIP/{$key}_7002-00000001", 'ChannelStateDesc' => 'Up', 'CallerIDNum' => '22501020304', 'Duration' => '00:02:05']],
            [
                ['Queue' => $queue, 'Position' => '2', 'CallerIDNum' => '22570000002', 'CallerIDName' => '', 'Wait' => '95'],
                ['Queue' => $queue, 'Position' => '1', 'CallerIDNum' => '22570000001', 'CallerIDName' => 'Awa', 'Wait' => '120'],
                ['Queue' => 'otherkey00_support', 'Position' => '1', 'CallerIDNum' => '1', 'CallerIDName' => '', 'Wait' => '5'],
            ],
            [$queue => ['Queue' => $queue, 'Completed' => '14', 'Abandoned' => '3']],
        ));
    });

    $this->actingAs($supervisor)
        ->getJson(route('monitoring.status', $this->team))
        ->assertOk()
        ->assertJsonPath('live', true)
        ->assertJsonPath('summary.waiting', 2)
        ->assertJsonPath('summary.longest_wait', 120)
        ->assertJsonPath('summary.in_call', 1)
        ->assertJsonPath('summary.free', 2) // supervisor (has an extension) + free agent
        ->assertJsonPath('summary.paused', 1)
        ->assertJsonPath('waiting.0.position', 1)
        ->assertJsonPath('waiting.0.caller', '22570000001')
        ->assertJsonPath('queues.0.name', 'support')
        ->assertJsonPath('queues.0.asterisk_name', $queue)
        ->assertJsonPath('queues.0.waiting', 2)
        ->assertJsonPath('queues.0.completed', 14)
        ->assertJsonPath('queues.0.abandoned', 3)
        ->assertJsonPath('agents.1.queues', ['support'])
        // Service level is opt-in.
        ->assertJsonPath('queues.0.service_level', null)
        ->assertJsonPath('serviceLevel', null);
});

it('shows the service level of each queue when the team tracks it', function () {
    $this->team->update(['service_level_enabled' => true, 'service_level_target' => 80]);
    $queue = $this->support->asterisk_name;
    $sales = teamQueue($this->team, 'ventes')->asterisk_name;
    $supervisor = teamAgent($this->team, '7001', TeamRole::Supervisor, [$this->support]);

    $this->mock(AsteriskAmi::class, function (MockInterface $mock) use ($queue, $sales) {
        $mock->shouldReceive('snapshot')->once()->andReturn(amiSnapshot($this->team, [], [], [
            $queue => ['Queue' => $queue, 'Completed' => '30', 'Abandoned' => '2', 'ServiceLevel' => '20', 'ServicelevelPerf' => '90.0'],
            $sales => ['Queue' => $sales, 'Completed' => '10', 'Abandoned' => '1', 'ServiceLevel' => '30', 'ServicelevelPerf' => '50.0'],
        ]));
    });

    $this->actingAs($supervisor)
        ->getJson(route('monitoring.status', $this->team))
        ->assertOk()
        ->assertJsonPath('queues.0.service_level.percent', 90.0)
        ->assertJsonPath('queues.0.service_level.threshold', 20)
        // Weighted by answered calls: (90 × 30 + 50 × 10) / 40 = 80.
        ->assertJsonPath('serviceLevel.percent', 80.0)
        ->assertJsonPath('serviceLevel.target', 80);
});

it('lets an administrator turn service level tracking on', function () {
    $admin = teamAgent($this->team, '7009', TeamRole::Admin);
    $admin->switchTeam($this->team);

    $this->actingAs($admin)
        ->put('/settings/asterisk/queues/service-level', ['enabled' => true, 'target' => 85])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh())
        ->service_level_enabled->toBeTrue()
        ->service_level_target->toBe(85);

    $member = teamAgent($this->team, '7010');
    $member->switchTeam($this->team);
    $this->actingAs($member)
        ->put('/settings/asterisk/queues/service-level', ['enabled' => false, 'target' => 80])
        ->assertForbidden();
});

it('does not present a stale AMI snapshot as live', function () {
    $key = $this->team->asterisk_key;
    $supervisor = teamAgent($this->team, '7001', TeamRole::Supervisor, [$this->support]);
    teamAgent($this->team, '7002', queues: [$this->support]);

    $this->mock(AsteriskAmi::class, function (MockInterface $mock) use ($key) {
        $mock->shouldReceive('snapshot')->once()->andReturn(amiSnapshot($this->team,
            [['Channel' => "PJSIP/{$key}_7002-00000001", 'ChannelStateDesc' => 'Up', 'Duration' => '00:00:05']],
            [], [], now()->subMinutes(5)->getTimestamp(),
        ));
    });

    $this->actingAs($supervisor)
        ->getJson(route('monitoring.status', $this->team))
        ->assertStatus(503)
        ->assertJsonPath('live', false)
        ->assertJsonPath('summary.in_call', 0);
});

it('falls back to queue presence when asterisk is unreachable', function () {
    $supervisor = teamAgent($this->team, '7001', TeamRole::Supervisor, [$this->support]);

    DB::table('queue_members')->insert([
        'uniqueid' => 1, 'queue_name' => $this->support->asterisk_name,
        'interface' => 'PJSIP/'.$this->team->asterisk_key.'_7001', 'paused' => 0,
    ]);

    $this->mock(AsteriskAmi::class, function (MockInterface $mock) {
        $mock->shouldReceive('snapshot')->andThrow(new RuntimeException('AMI down'));
    });

    $this->actingAs($supervisor)
        ->getJson(route('monitoring.status', $this->team))
        ->assertStatus(503)
        ->assertJsonPath('live', false)
        ->assertJsonPath('agents.0.status', 'available')
        ->assertJsonPath('waiting', []);
});

it('only lets supervisors spy on agents of their own team', function () {
    $supervisor = teamAgent($this->team, '7001', TeamRole::Supervisor);
    $agent = teamAgent($this->team, '7002');
    $stranger = teamAgent(Team::factory()->create(), '7003');

    $this->mock(AsteriskAmi::class, function (MockInterface $mock) {
        $mock->shouldReceive('startSpy')->once()->with('7001', '7002', false, $this->team->asterisk_key);
    });

    $this->actingAs($supervisor)
        ->postJson(route('monitoring.listen', $this->team), ['agent' => $agent->id])
        ->assertOk();

    $this->actingAs($supervisor)
        ->postJson(route('monitoring.listen', $this->team), ['agent' => $stranger->id])
        ->assertNotFound();
});
