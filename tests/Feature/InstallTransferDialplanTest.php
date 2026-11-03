<?php

use App\Models\Team;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => createAsteriskTables());

/*
 * Transfers no longer use a global "queue-transfer" context: each queue has
 * an entry extension in its team context. dialplan:install-transfer is kept
 * as an alias of asterisk:sync-queues, which (re)writes those entries.
 */
it('installs a transfer entry for every team queue in its own context', function () {
    $team = Team::factory()->create();
    $queue = teamQueue($team, 'Sales');

    $this->artisan('dialplan:install-transfer')->assertSuccessful();

    $this->assertDatabaseHas('extensions', [
        'context' => $team->asteriskContext(),
        'exten' => $queue->asterisk_name,
        'app' => 'Queue',
        'appdata' => $queue->asterisk_name.',Tr',
    ]);
    $this->assertDatabaseMissing('extensions', ['context' => 'queue-transfer']);
});

it('is idempotent', function () {
    $queue = teamQueue(Team::factory()->create(), 'Sales');

    $this->artisan('dialplan:install-transfer')->assertSuccessful();
    $this->artisan('dialplan:install-transfer')->assertSuccessful();

    expect(DB::table('extensions')->where('exten', $queue->asterisk_name)->count())->toBe(4);
});

it('provisions the internal dial pattern with the team team context', function () {
    $team = Team::factory()->create();

    $this->artisan('asterisk:provision-team', ['team' => $team->slug])
        ->expectsOutputToContain('switch => Realtime/'.$team->asteriskContext().'@extensions')
        ->assertSuccessful();

    $this->assertDatabaseHas('extensions', [
        'context' => $team->asteriskContext(),
        'exten' => '_X.',
        'app' => 'Dial',
        'appdata' => 'PJSIP/'.$team->asterisk_key.'_${EXTEN},30',
    ]);
});
