<?php

use App\Enums\TeamRole;
use App\Models\InboundNumber;
use App\Models\PoolNumber;
use App\Models\Team;
use App\Models\User;
use App\Services\NumberPool;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->admin = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->admin->switchTeam($this->team);

    app(NumberPool::class)->add(['22320200001', '22320200002']);
});

it('gives the team a pool number routed to its first queue', function () {
    $queue = teamQueue($this->team, 'Support');

    $this->actingAs($this->admin)->post('/settings/asterisk/ivr/numbers/claim')->assertSessionHasNoErrors();

    $number = $this->team->inboundNumbers()->sole();

    expect($number)
        ->number->toBe('22320200001')
        ->source->toBe(InboundNumber::SOURCE_POOL)
        ->destination_type->toBe('queue')
        ->destination_target->toBe((string) $queue->id);

    expect(PoolNumber::query()->where('number', '22320200001')->value('team_id'))->toBe($this->team->id);

    $this->assertDatabaseHas('extensions', [
        'context' => config('asterisk.inbound.context'),
        'exten' => '22320200001',
    ]);

    // Only one platform number per team.
    $this->actingAs($this->admin)->post('/settings/asterisk/ivr/numbers/claim')->assertSessionHasErrors('pool');
});

it('routes a number taken before any queue once the first queue exists', function () {
    $pool = app(NumberPool::class);
    $number = $pool->ensureFor($this->team);

    expect($number->destination_type)->toBe('hangup');

    $queue = teamQueue($this->team, 'Support');
    $pool->routeUnrouted($this->team, $queue);

    expect($number->fresh())
        ->destination_type->toBe('queue')
        ->destination_target->toBe((string) $queue->id);
});

it('refuses typing in a number of the pool', function () {
    $queue = teamQueue($this->team, 'Support');

    $this->actingAs($this->admin)->post('/settings/asterisk/ivr/numbers', [
        'number' => '22320200002',
        'destination_type' => 'queue',
        'destination_target' => $queue->id,
    ])->assertSessionHasErrors('number');
});

it('replaces the pool number with the team own number and keeps the routing', function () {
    $queue = teamQueue($this->team, 'Support');
    $number = app(NumberPool::class)->ensureFor($this->team);

    $this->actingAs($this->admin)->post("/settings/asterisk/ivr/numbers/{$number->id}/replace", [
        'own_number' => '22344445555',
    ])->assertSessionHasNoErrors();

    $own = $this->team->inboundNumbers()->sole();

    expect($own)
        ->number->toBe('22344445555')
        ->source->toBe(InboundNumber::SOURCE_OWN)
        ->destination_target->toBe((string) $queue->id);

    expect(PoolNumber::query()->where('number', '22320200001')->value('team_id'))->toBeNull();
    $this->assertDatabaseMissing('extensions', ['exten' => '22320200001']);
});

it('gives a removed pool number back to the pool', function () {
    teamQueue($this->team, 'Support');
    $number = app(NumberPool::class)->ensureFor($this->team);

    $this->actingAs($this->admin)->delete("/settings/asterisk/ivr/numbers/{$number->id}")->assertRedirect();

    expect(PoolNumber::query()->free()->count())->toBe(2);
});

it('loads ranges into the pool from the command line', function () {
    $this->artisan('numbers:pool', ['action' => 'add', 'values' => ['22320201000-22320201009']])->assertSuccessful();

    expect(PoolNumber::query()->count())->toBe(12);
});
