<?php

use App\Enums\AgentState;
use App\Enums\TeamRole;
use App\Models\AgentStateLog;
use App\Models\Team;
use App\Services\AsteriskAmi;
use App\Services\AsteriskQueueMembers;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

beforeEach(function () {
    createAsteriskTables();

    $this->team = Team::factory()->create();
    $this->support = teamQueue($this->team, 'Support');
    $this->agent = teamAgent($this->team, '7001', queues: [$this->support]);
    $this->interface = 'PJSIP/'.$this->team->asterisk_key.'_7001';
});

function memberRow(string $interface): ?object
{
    return DB::table('queue_members')->where('interface', $interface)->first();
}

test('new teams get the default pause reasons', function () {
    expect($this->team->pauseReasons()->pluck('name')->all())
        ->toBe(config('agents.default_pause_reasons'));
});

test('non prêt with a reason pauses the queues with that reason', function () {
    $lunch = $this->team->pauseReasons()->create(['name' => 'Déjeuner', 'position' => 99]);

    $this->actingAs($this->agent)->postJson(route('presence.join', $this->team))->assertOk();

    $this->actingAs($this->agent)
        ->putJson(route('agent-state.update', $this->team), ['state' => 'not_ready', 'reason_id' => $lunch->id])
        ->assertOk()
        ->assertJson([
            'status' => 'paused',
            'state' => 'not_ready',
            'stateLabel' => AgentState::NotReady->label().' – Déjeuner',
            'reasonId' => $lunch->id,
        ]);

    $row = memberRow($this->interface);

    expect((int) $row->paused)->toBe(1)
        ->and($row->reason_paused)->toBe('Déjeuner');
});

test('post-appel and ne pas déranger pause with their own reason, prêt resumes', function () {
    $this->actingAs($this->agent)->postJson(route('presence.join', $this->team));

    $this->putJson(route('agent-state.update', $this->team), ['state' => 'wrap_up'])->assertJson(['status' => 'paused']);
    expect(memberRow($this->interface)->reason_paused)->toBe('Post-appel');

    $this->putJson(route('agent-state.update', $this->team), ['state' => 'dnd'])->assertJson(['status' => 'paused']);
    expect(memberRow($this->interface)->reason_paused)->toBe('Ne pas déranger');

    $this->putJson(route('agent-state.update', $this->team), ['state' => 'ready'])->assertJson(['status' => 'available']);
    expect((int) memberRow($this->interface)->paused)->toBe(0);
});

test('déconnexion leaves the queues and the heartbeat does not bring the agent back', function () {
    $this->actingAs($this->agent)->postJson(route('presence.join', $this->team));

    $this->putJson(route('agent-state.update', $this->team), ['state' => 'logged_out'])
        ->assertJson(['status' => 'offline', 'state' => 'logged_out']);

    $this->postJson(route('presence.join', $this->team))
        ->assertOk()
        ->assertJson(['status' => 'offline', 'state' => 'logged_out']);

    expect(memberRow($this->interface))->toBeNull();

    // Connexion: back in the queues at once, the softphone is still registered.
    $this->putJson(route('agent-state.update', $this->team), ['state' => 'ready'])
        ->assertJson(['status' => 'available', 'state' => 'ready']);

    expect(memberRow($this->interface))->not->toBeNull();
});

test('the chosen pause survives a softphone reconnect', function () {
    $this->actingAs($this->agent)->postJson(route('presence.join', $this->team));
    $this->putJson(route('agent-state.update', $this->team), ['state' => 'wrap_up']);

    $this->postJson(route('presence.leave', $this->team));
    expect(memberRow($this->interface))->toBeNull();

    $this->postJson(route('presence.join', $this->team))->assertJson(['status' => 'paused', 'state' => 'wrap_up']);

    expect(memberRow($this->interface)->reason_paused)->toBe('Post-appel');
});

test('a state chosen while the softphone is offline applies when it registers', function () {
    $this->actingAs($this->agent)
        ->putJson(route('agent-state.update', $this->team), ['state' => 'not_ready'])
        ->assertJson(['status' => 'offline', 'state' => 'not_ready']);

    expect(memberRow($this->interface))->toBeNull();

    $this->postJson(route('presence.join', $this->team))->assertJson(['status' => 'paused']);
});

test('a reason of another team is refused', function () {
    $other = Team::factory()->create();
    $foreign = $other->pauseReasons()->create(['name' => 'Ailleurs', 'position' => 0]);

    $this->actingAs($this->agent)
        ->putJson(route('agent-state.update', $this->team), ['state' => 'not_ready', 'reason_id' => $foreign->id])
        ->assertUnprocessable();
});

test('each state change closes the previous period in the log', function () {
    $this->actingAs($this->agent)->putJson(route('agent-state.update', $this->team), ['state' => 'not_ready']);
    $this->travel(10)->minutes();
    $this->putJson(route('agent-state.update', $this->team), ['state' => 'ready']);
    $this->putJson(route('agent-state.update', $this->team), ['state' => 'ready']);

    $logs = AgentStateLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->state)->toBe(AgentState::NotReady)
        ->and((int) $logs[0]->started_at->diffInMinutes($logs[0]->ended_at))->toBe(10)
        ->and($logs[1]->state)->toBe(AgentState::Ready)
        ->and($logs[1]->ended_at)->toBeNull();
});

test('a new queue assignment keeps the pause of an online agent', function () {
    $sales = teamQueue($this->team, 'Sales');
    $this->actingAs($this->agent)->postJson(route('presence.join', $this->team));
    $this->putJson(route('agent-state.update', $this->team), ['state' => 'dnd']);

    app(AsteriskQueueMembers::class)->syncQueueAgents($sales, [$this->agent->id => 0]);

    $row = DB::table('queue_members')->where('queue_name', $sales->asterisk_name)->first();

    expect((int) $row->paused)->toBe(1)
        ->and($row->reason_paused)->toBe('Ne pas déranger');
});

test('admins manage the pause reasons, members cannot', function () {
    $admin = teamAgent($this->team, '7002', TeamRole::Admin);

    $this->actingAs($admin)->post('/settings/pause-reasons', ['name' => 'Rappel client'])->assertRedirect();
    $reason = $this->team->pauseReasons()->where('name', 'Rappel client')->sole();

    $this->actingAs($admin)->post('/settings/pause-reasons', ['name' => 'Rappel client'])->assertSessionHasErrors('name');

    $this->actingAs($admin)->put('/settings/pause-reasons/'.$reason->id, ['name' => 'Rappel'])->assertRedirect();
    expect($reason->fresh()->name)->toBe('Rappel');

    $ids = $this->team->pauseReasons()->pluck('id')->reverse()->values()->all();
    $this->actingAs($admin)->put('/settings/pause-reasons/order', ['ids' => $ids])->assertRedirect();
    expect($this->team->pauseReasons()->pluck('id')->all())->toBe($ids);

    $this->actingAs($this->agent)->post('/settings/pause-reasons', ['name' => 'Sieste'])->assertForbidden();

    $this->actingAs($admin)->delete('/settings/pause-reasons/'.$reason->id)->assertRedirect();
    expect($reason->fresh())->toBeNull();
});

test('supervision shows the reason the agent chose', function () {
    $lunch = $this->team->pauseReasons()->create(['name' => 'Déjeuner', 'position' => 99]);
    $supervisor = teamAgent($this->team, '7009', TeamRole::Supervisor);

    $this->actingAs($this->agent)->postJson(route('presence.join', $this->team));
    $this->putJson(route('agent-state.update', $this->team), ['state' => 'not_ready', 'reason_id' => $lunch->id]);

    $this->mock(AsteriskAmi::class, fn (MockInterface $mock) => $mock->shouldReceive('snapshot')->andReturn([
        'channels' => [],
        'queues' => ['entries' => [], 'queues' => [], 'members' => [], 'updated_at' => now()->getTimestamp()],
    ]));

    $agents = collect($this->actingAs($supervisor)->getJson(route('monitoring.status', $this->team))->assertOk()->json('agents'));
    $row = $agents->firstWhere('id', $this->agent->id);

    expect($row['status'])->toBe('paused')
        ->and($row['state'])->toBe('not_ready')
        ->and($row['stateLabel'])->toBe(AgentState::NotReady->label().' – Déjeuner')
        ->and($row['stateSince'])->not->toBeNull();
});
