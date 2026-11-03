<?php

use App\Models\QueueHour;
use App\Models\Team;
use App\Services\AsteriskDialplan;
use App\Services\QueueOpeningHours;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();

    config(['asterisk.dialplan.closed_sound' => 'callcenter-closed']);

    $this->team = Team::factory()->create();
    $this->queue = teamQueue($this->team, 'Support');
    $this->key = $this->team->asterisk_key;
});

function seedWeekdayHours($queue): void
{
    for ($weekday = 0; $weekday < 5; $weekday++) {
        QueueHour::factory()->create([
            'call_queue_id' => $queue->id,
            'weekday' => $weekday,
            'opens_at' => '08:00:00',
            'closes_at' => '16:00:00',
        ]);
    }
}

it('merges weekdays that share identical hours into one time group', function () {
    seedWeekdayHours($this->queue);

    $groups = app(QueueOpeningHours::class)->timeGroups($this->queue);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['times'])->toBe('08:00-16:00')
        ->and($groups[0]['days'])->toBe('mon&tue&wed&thu&fri');
});

it('keeps days with different hours in separate time groups', function () {
    seedWeekdayHours($this->queue);
    QueueHour::factory()->create(['call_queue_id' => $this->queue->id, 'weekday' => 5, 'opens_at' => '09:00:00', 'closes_at' => '12:00:00']);

    $groups = app(QueueOpeningHours::class)->timeGroups($this->queue);

    expect($groups)->toHaveCount(2)
        ->and($groups[0])->toMatchArray(['times' => '08:00-16:00', 'days' => 'mon&tue&wed&thu&fri'])
        ->and($groups[1])->toMatchArray(['times' => '09:00-12:00', 'days' => 'sat']);
});

it('writes the queue entry in the team context with accountcode, hours check and closed branch', function () {
    seedWeekdayHours($this->queue);

    $rows = app(AsteriskDialplan::class)->syncQueue($this->queue);
    $name = $this->queue->asterisk_name;

    expect($rows)->toBe([
        ['context' => "t_{$this->key}", 'exten' => $name, 'priority' => 1, 'app' => 'Set', 'appdata' => "CHANNEL(accountcode)={$this->key}"],
        ['context' => "t_{$this->key}", 'exten' => $name, 'priority' => 2, 'app' => 'UserEvent', 'appdata' => "CcInbound,Queue: {$name},Number: \${CALLERID(num)},Choice: \${CC_IVR_CHOICE}"],
        ['context' => "t_{$this->key}", 'exten' => $name, 'priority' => 3, 'app' => 'GotoIfTime', 'appdata' => '08:00-16:00,mon&tue&wed&thu&fri,*,*?4:6'],
        ['context' => "t_{$this->key}", 'exten' => $name, 'priority' => 4, 'app' => 'Queue', 'appdata' => "{$name},Tr"],
        ['context' => "t_{$this->key}", 'exten' => $name, 'priority' => 5, 'app' => 'Hangup', 'appdata' => ''],
        ['context' => "t_{$this->key}", 'exten' => $name, 'priority' => 6, 'app' => 'Playback', 'appdata' => 'callcenter-closed'],
        ['context' => "t_{$this->key}", 'exten' => $name, 'priority' => 7, 'app' => 'Hangup', 'appdata' => ''],
    ]);

    expect(DB::table('extensions')->where('exten', $name)->count())->toBe(7);
});

it('chains one GotoIfTime check per distinct hours group', function () {
    seedWeekdayHours($this->queue);
    QueueHour::factory()->create(['call_queue_id' => $this->queue->id, 'weekday' => 5, 'opens_at' => '09:00:00', 'closes_at' => '12:00:00']);

    $rows = collect(app(AsteriskDialplan::class)->syncQueue($this->queue))->map(fn ($row) => [$row['priority'], $row['app'], $row['appdata']])->all();

    expect($rows)->toBe([
        [1, 'Set', "CHANNEL(accountcode)={$this->key}"],
        [2, 'UserEvent', 'CcInbound,Queue: '.$this->queue->asterisk_name.',Number: ${CALLERID(num)},Choice: ${CC_IVR_CHOICE}'],
        [3, 'GotoIfTime', '08:00-16:00,mon&tue&wed&thu&fri,*,*?5:4'],
        [4, 'GotoIfTime', '09:00-12:00,sat,*,*?5:7'],
        [5, 'Queue', $this->queue->asterisk_name.',Tr'],
        [6, 'Hangup', ''],
        [7, 'Playback', 'callcenter-closed'],
        [8, 'Hangup', ''],
    ]);
});

it('keeps a queue without opening hours always open', function () {
    $rows = collect(app(AsteriskDialplan::class)->syncQueue($this->queue))->pluck('app')->all();

    expect($rows)->toBe(['Set', 'UserEvent', 'Queue', 'Hangup']);
});

it('does not touch the extensions table in dry run mode', function () {
    seedWeekdayHours($this->queue);

    $rows = app(AsteriskDialplan::class)->syncQueue($this->queue, dryRun: true);

    expect($rows)->toHaveCount(7)
        ->and(DB::table('extensions')->count())->toBe(0);
});

it('rejects a closing time before the opening time', function () {
    QueueHour::factory()->create(['call_queue_id' => $this->queue->id, 'weekday' => 0, 'opens_at' => '16:00:00', 'closes_at' => '08:00:00']);

    app(QueueOpeningHours::class)->timeGroups($this->queue);
})->throws(RuntimeException::class, 'Invalid opening hours');

it('syncs every queue through the artisan command', function () {
    seedWeekdayHours($this->queue);
    $other = teamQueue(Team::factory()->create(), 'Sales');

    $this->artisan('asterisk:sync-queues')
        ->expectsOutputToContain('08:00-16:00,mon&tue&wed&thu&fri,*,*?4:6')
        ->assertSuccessful();

    $this->assertDatabaseHas('extensions', ['exten' => $this->queue->asterisk_name, 'app' => 'Playback']);
    $this->assertDatabaseHas('extensions', ['exten' => $other->asterisk_name, 'app' => 'Queue']);
});

it('limits the command to one team and supports a dry run', function () {
    $other = teamQueue(Team::factory()->create(), 'Sales');

    $this->artisan('asterisk:sync-queues', ['--team' => $this->team->slug, '--dry-run' => true])->assertSuccessful();
    expect(DB::table('extensions')->count())->toBe(0);

    $this->artisan('asterisk:sync-queues', ['--team' => $this->team->slug])->assertSuccessful();
    expect(DB::table('extensions')->where('exten', $other->asterisk_name)->count())->toBe(0)
        ->and(DB::table('extensions')->where('exten', $this->queue->asterisk_name)->count())->toBe(4);
});

it('keeps the former command names working', function () {
    $this->artisan('queues:sync-hours')->assertSuccessful();

    expect(DB::table('extensions')->where('exten', $this->queue->asterisk_name)->count())->toBe(4);
});
