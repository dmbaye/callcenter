<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Services\AsteriskQueueMembers;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();

    $this->team = Team::factory()->create();
    $this->support = teamQueue($this->team, 'Support');
    $this->sales = teamQueue($this->team, 'Sales');
});

function interfaceOf(Team $team, string $extension): string
{
    return 'PJSIP/'.$team->asterisk_key.'_'.$extension;
}

test('joining adds the agent once per queue and is idempotent', function () {
    $agent = teamAgent($this->team, '7001', queues: [$this->support, $this->sales]);

    app(AsteriskQueueMembers::class)->join($agent, $this->team);
    app(AsteriskQueueMembers::class)->join($agent, $this->team);

    expect(DB::table('queue_members')->where('interface', interfaceOf($this->team, '7001'))->orderBy('queue_name')->pluck('queue_name')->all())
        ->toBe([$this->sales->asterisk_name, $this->support->asterisk_name]);
});

test('re-joining keeps an existing pause', function () {
    $agent = teamAgent($this->team, '7001', queues: [$this->support]);
    $members = app(AsteriskQueueMembers::class);

    $members->join($agent, $this->team);
    $members->setPaused($agent, true, $this->team);
    $members->join($agent, $this->team);

    expect($members->status($agent, $this->team))->toBe(AsteriskQueueMembers::PAUSED);
});

test('users without an extension are never queued', function () {
    $user = teamAgent($this->team, '7001', queues: [$this->support]);
    $user->phoneExtensions()->delete();

    app(AsteriskQueueMembers::class)->join($user->fresh(), $this->team);

    expect(DB::table('queue_members')->count())->toBe(0);
});

test('logging in does not queue the agent; the softphone presence call does', function () {
    $agent = teamAgent($this->team, '7001', queues: [$this->support]);

    $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password']);
    expect(DB::table('queue_members')->count())->toBe(0);

    $this->postJson(route('presence.join', $this->team))
        ->assertOk()
        ->assertJson(['status' => 'available']);

    expect(DB::table('queue_members')->pluck('interface')->all())->toBe([interfaceOf($this->team, '7001')]);
});

test('logout removes only the logging-out agent', function () {
    $a = teamAgent($this->team, '7001', queues: [$this->support]);
    $b = teamAgent($this->team, '7002', queues: [$this->support]);

    app(AsteriskQueueMembers::class)->join($a, $this->team);
    app(AsteriskQueueMembers::class)->join($b, $this->team);

    $this->actingAs($a)->post(route('logout'));

    expect(DB::table('queue_members')->pluck('interface')->all())->toBe([interfaceOf($this->team, '7002')]);
});

test('the presence leave call removes the agent from the team queues', function () {
    $agent = teamAgent($this->team, '7001', queues: [$this->support]);
    app(AsteriskQueueMembers::class)->join($agent, $this->team);

    $this->actingAs($agent)
        ->postJson(route('presence.leave', $this->team))
        ->assertOk()
        ->assertJson(['status' => 'offline']);

    expect(DB::table('queue_members')->count())->toBe(0);
});

test('switching teams leaves the previous team queues', function () {
    $agent = teamAgent($this->team, '7001', queues: [$this->support]);
    app(AsteriskQueueMembers::class)->join($agent, $this->team);

    $other = Team::factory()->create();
    $other->members()->attach($agent, ['role' => TeamRole::Member->value]);

    $agent->switchTeam($other);

    expect(DB::table('queue_members')->count())->toBe(0);
});

test('stale agents are pruned when their heartbeat stops', function () {
    $fresh = teamAgent($this->team, '7001', queues: [$this->support]);
    $stale = teamAgent($this->team, '7002', queues: [$this->support]);
    $members = app(AsteriskQueueMembers::class);

    $members->join($fresh, $this->team);
    $members->join($stale, $this->team);

    $this->travel(5)->minutes();
    $members->touch($fresh, $this->team);

    $this->artisan('agents:prune-presence')->assertSuccessful();

    expect(DB::table('queue_members')->pluck('interface')->all())->toBe([interfaceOf($this->team, '7001')]);
});

test('pausing one agent does not pause others', function () {
    $a = teamAgent($this->team, '7001', queues: [$this->support]);
    $b = teamAgent($this->team, '7002', queues: [$this->support]);

    app(AsteriskQueueMembers::class)->join($a, $this->team);
    app(AsteriskQueueMembers::class)->join($b, $this->team);

    $this->actingAs($a)
        ->postJson(route('pauses.store', $this->team))
        ->assertOk()
        ->assertJson(['status' => 'paused']);

    expect((int) DB::table('queue_members')->where('interface', interfaceOf($this->team, '7001'))->value('paused'))->toBe(1)
        ->and((int) DB::table('queue_members')->where('interface', interfaceOf($this->team, '7002'))->value('paused'))->toBe(0);

    $this->actingAs($a)
        ->deleteJson(route('pauses.destroy', $this->team))
        ->assertOk()
        ->assertJson(['status' => 'available']);
});

test('status endpoint reports offline when the agent is not queued', function () {
    $agent = teamAgent($this->team, '7001', queues: [$this->support]);

    $this->actingAs($agent)
        ->getJson(route('pauses.status', $this->team))
        ->assertOk()
        ->assertJson(['status' => 'offline']);
});

test('the sip secret is never exposed in the auth user prop', function () {
    $agent = teamAgent($this->team, '7001', queues: [$this->support]);

    expect($agent->toArray())->not->toHaveKey('secret')
        ->and($agent->phoneExtensionFor($this->team)->toArray()['secret'] ?? null)->not->toBeNull();
});
