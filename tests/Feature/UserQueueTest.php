<?php

use App\Models\Team;
use App\Services\AsteriskQueueMembers;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
});

it('redirects a newly registered user to their personal team dashboard', function () {
    $this->post('/register', [
        'name' => 'Queue Agent',
        'email' => 'agent@example.com',
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
    ])->assertRedirect('/queue-agents-team/dashboard');
});

it('sends team-less dashboard links to the current team dashboard', function () {
    $team = Team::factory()->create();
    $agent = teamAgent($team, '7001');

    $this->actingAs($agent)->get('/dashboard')->assertRedirect("/{$team->slug}/dashboard");
});

it('joins every queue the agent is assigned to in the team, with its penalty', function () {
    $team = Team::factory()->create();
    $support = teamQueue($team, 'Support');
    $sales = teamQueue($team, 'Sales');
    $agent = teamAgent($team, '7001', queues: [$support]);
    $sales->members()->attach($agent->id, ['penalty' => 3]);

    app(AsteriskQueueMembers::class)->join($agent->fresh(), $team);

    expect(DB::table('queue_members')->where('queue_name', $sales->asterisk_name)->value('penalty'))->toBe(3)
        ->and(DB::table('queue_members')->count())->toBe(2);
});

it('drops queues the agent was unassigned from on the next join', function () {
    $team = Team::factory()->create();
    $support = teamQueue($team, 'Support');
    $sales = teamQueue($team, 'Sales');
    $agent = teamAgent($team, '7001', queues: [$support, $sales]);
    $members = app(AsteriskQueueMembers::class);

    $members->join($agent, $team);
    $sales->members()->detach($agent->id);
    $members->join($agent->fresh(), $team);

    expect(DB::table('queue_members')->pluck('queue_name')->all())->toBe([$support->asterisk_name]);
});
