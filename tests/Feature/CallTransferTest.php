<?php

use App\Models\Team;
use App\Services\AsteriskAmi;

beforeEach(function () {
    createAsteriskTables();

    $this->team = Team::factory()->create();
    $this->support = teamQueue($this->team, 'Support');
    $this->sales = teamQueue($this->team, 'Sales');
    $this->user = teamAgent($this->team, '7001', queues: [$this->support]);
    $this->agentChannel = 'PJSIP/'.$this->team->asterisk_key.'_7001-00000001';
});

it('lists the team queues the agent may transfer to', function () {
    teamQueue(Team::factory()->create(), 'Other tenant');

    $this->actingAs($this->user)->getJson(route('queues.options', $this->team))
        ->assertOk()
        ->assertExactJson(['queues' => [
            ['name' => 'Sales', 'asterisk_name' => $this->sales->asterisk_name],
            ['name' => 'Support', 'asterisk_name' => $this->support->asterisk_name],
        ]]);
});

it('blind-transfers the active call into the queue entry of the team context', function () {
    $this->mock(AsteriskAmi::class, function ($mock) {
        $mock->shouldReceive('activeChannelForExtension')
            ->once()
            ->with('7001', $this->team->asterisk_key)
            ->andReturn(['channel' => $this->agentChannel, 'bridged_channel' => 'PJSIP/trunk-00000002']);

        $mock->shouldReceive('blindTransfer')
            ->once()
            ->with($this->agentChannel, $this->sales->asterisk_name, $this->team->asteriskContext());
    });

    $this->actingAs($this->user)->postJson(route('calls.transfer', $this->team), ['queue' => $this->sales->asterisk_name])
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('finds the active call from real CoreShowChannels data', function () {
    $ami = Mockery::mock(AsteriskAmi::class)->makePartial();
    $ami->shouldReceive('coreShowChannels')->once()->andReturn([
        ['Channel' => 'PJSIP/trunk-00000001', 'ChannelStateDesc' => 'Up', 'BridgeId' => 'bridge-1'],
        ['Channel' => $this->agentChannel, 'ChannelStateDesc' => 'Up', 'BridgeId' => 'bridge-1'],
    ]);
    $ami->shouldReceive('blindTransfer')->once()->with($this->agentChannel, $this->sales->asterisk_name, $this->team->asteriskContext());
    $this->app->instance(AsteriskAmi::class, $ami);

    $this->actingAs($this->user)->postJson(route('calls.transfer', $this->team), ['queue' => $this->sales->asterisk_name])
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('rejects queues of other teams', function () {
    $foreign = teamQueue(Team::factory()->create(), 'Sales');

    $this->actingAs($this->user)->postJson(route('calls.transfer', $this->team), ['queue' => $foreign->asterisk_name])
        ->assertUnprocessable();
});

it('fails cleanly when the agent has no active call', function () {
    $this->mock(AsteriskAmi::class, function ($mock) {
        $mock->shouldReceive('activeChannelForExtension')->once()->andReturn(null);
    });

    $this->actingAs($this->user)->postJson(route('calls.transfer', $this->team), ['queue' => $this->sales->asterisk_name])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You have no active call to transfer.');
});

it('fails cleanly when asterisk rejects the transfer', function () {
    $this->mock(AsteriskAmi::class, function ($mock) {
        $mock->shouldReceive('activeChannelForExtension')->once()
            ->andReturn(['channel' => $this->agentChannel, 'bridged_channel' => 'PJSIP/trunk-00000002']);
        $mock->shouldReceive('blindTransfer')->once()->andThrow(new RuntimeException('No such channel'));
    });

    $this->actingAs($this->user)->postJson(route('calls.transfer', $this->team), ['queue' => $this->sales->asterisk_name])
        ->assertStatus(502)
        ->assertJsonPath('message', 'No such channel');
});
