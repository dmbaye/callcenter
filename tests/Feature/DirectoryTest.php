<?php

use App\Enums\AgentState;
use App\Enums\TeamRole;
use App\Models\AgentStateLog;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskQueueMembers;

beforeEach(function () {
    createAsteriskTables();
    $this->team = Team::factory()->create();
});

it('lists colleagues with an extension and their status, not the caller', function () {
    $me = teamAgent($this->team, '7001');
    $awa = teamAgent($this->team, '7002', attributes: ['name' => 'Awa Diarra', 'job_title' => 'Superviseure']);
    teamAgent($this->team, '7003', TeamRole::Supervisor, attributes: ['name' => 'Moussa Keita']);

    // A member without an extension cannot be called.
    $noPhone = User::factory()->create(['name' => 'Sans Poste']);
    $this->team->members()->attach($noPhone, ['role' => TeamRole::Planner->value]);

    // Awa's softphone is open; Moussa's tab is closed.
    app(AsteriskQueueMembers::class)->touch($awa, $this->team);

    AgentStateLog::query()->create([
        'team_id' => $this->team->id, 'user_id' => $awa->id,
        'state' => AgentState::NotReady, 'reason' => 'Déjeuner', 'started_at' => now(),
    ]);

    $this->actingAs($me)
        ->getJson(route('directory', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertJsonCount(2, 'colleagues')
        ->assertJsonPath('colleagues.0.name', 'Awa Diarra')
        ->assertJsonPath('colleagues.0.extension', '7002')
        ->assertJsonPath('colleagues.0.jobTitle', 'Superviseure')
        ->assertJsonPath('colleagues.0.online', true)
        ->assertJsonPath('colleagues.0.state', 'not_ready')
        ->assertJsonPath('colleagues.1.name', 'Moussa Keita')
        ->assertJsonPath('colleagues.1.online', false)
        ->assertJsonPath('colleagues.1.state', null);
});

it('is only open to members of the team', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson(route('directory', ['current_team' => $this->team->slug]))
        ->assertForbidden();
});
