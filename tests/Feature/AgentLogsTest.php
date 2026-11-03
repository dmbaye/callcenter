<?php

use App\Enums\AgentState;
use App\Enums\TeamRole;
use App\Models\AgentStateLog;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();

    Carbon::setTestNow('2026-10-29 12:00:00');

    $this->team = Team::factory()->create();
    $this->agent = teamAgent($this->team, '7001');
    $this->other = teamAgent($this->team, '7002');
});

function stateLog(Team $team, int $userId, AgentState $state, string $start, ?string $end, ?string $reason = null): AgentStateLog
{
    return AgentStateLog::query()->create([
        'team_id' => $team->id,
        'user_id' => $userId,
        'state' => $state,
        'reason' => $reason,
        'started_at' => $start,
        'ended_at' => $end,
    ]);
}

test('supervisors and owners can open the agent log, agents cannot', function () {
    $supervisor = teamAgent($this->team, '7100', TeamRole::Supervisor);
    $owner = teamAgent($this->team, '7101', TeamRole::Owner);

    $this->actingAs($supervisor)->get(route('agent-logs.index', $this->team))->assertOk();
    $this->actingAs($owner)->get(route('agent-logs.index', $this->team))->assertOk();
    $this->actingAs($this->agent)->get(route('agent-logs.index', $this->team))->assertForbidden();
});

test('the log lists pauses with their reason and totals clipped to the range', function () {
    $supervisor = teamAgent($this->team, '7100', TeamRole::Supervisor);

    // Started yesterday 23:00, ended today 00:30: only 30 min count today.
    stateLog($this->team, $this->agent->id, AgentState::NotReady, '2026-10-28 23:00:00', '2026-10-29 00:30:00', 'Déjeuner');
    stateLog($this->team, $this->agent->id, AgentState::Ready, '2026-10-29 00:30:00', '2026-10-29 09:00:00');
    stateLog($this->team, $this->agent->id, AgentState::NotReady, '2026-10-29 09:00:00', '2026-10-29 09:10:00', 'Pause café');
    // Still running: 11:30 → now (12:00).
    stateLog($this->team, $this->other->id, AgentState::NotReady, '2026-10-29 11:30:00', null, 'Déjeuner');
    // Outside the range.
    stateLog($this->team, $this->agent->id, AgentState::NotReady, '2026-10-27 10:00:00', '2026-10-27 11:00:00', 'Formation');

    $this->actingAs($supervisor)
        ->get(route('agent-logs.index', ['current_team' => $this->team, 'from' => '2026-10-29', 'to' => '2026-10-29']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agent-logs/Index')
            ->has('logs.data', 4)
            ->where('logs.data.0.user_id', $this->other->id)
            ->where('logs.data.0.reason', 'Déjeuner')
            ->where('logs.data.0.ongoing', true)
            ->where('logs.data.0.duration', 1800)
            ->where('summary.pauses', 3)
            ->where('summary.pause_seconds', 1800 + 600 + 1800)
            ->where('summary.agents', 2)
            ->where('reasons.0.reason', 'Déjeuner')
            ->where('reasons.0.count', 2)
            ->where('reasons.0.seconds', 3600)
            ->where('reasons.1.reason', 'Pause café')
            ->has('perAgent', 2)
        );
});

test('the log can be narrowed to one agent and one state, and never shows another team', function () {
    $supervisor = teamAgent($this->team, '7100', TeamRole::Supervisor);
    $otherTeam = Team::factory()->create();

    stateLog($this->team, $this->agent->id, AgentState::NotReady, '2026-10-29 09:00:00', '2026-10-29 09:10:00', 'Pause café');
    stateLog($this->team, $this->agent->id, AgentState::WrapUp, '2026-10-29 10:00:00', '2026-10-29 10:01:00');
    stateLog($this->team, $this->other->id, AgentState::NotReady, '2026-10-29 09:00:00', '2026-10-29 09:05:00');
    stateLog($otherTeam, $this->agent->id, AgentState::NotReady, '2026-10-29 09:00:00', '2026-10-29 11:00:00', 'Autre équipe');

    $this->actingAs($supervisor)
        ->get(route('agent-logs.index', ['current_team' => $this->team, 'agent' => $this->agent->id, 'state' => 'not_ready']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.reason', 'Pause café')
            ->where('summary.pause_seconds', 600)
        );

    // A user outside the team is not a valid filter.
    $stranger = teamAgent($otherTeam, '7001');
    $this->actingAs($supervisor)
        ->get(route('agent-logs.index', ['current_team' => $this->team, 'agent' => $stranger->id]))
        ->assertSessionHasErrors('agent');
});
