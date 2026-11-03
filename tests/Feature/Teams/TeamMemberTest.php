<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskProvisioner;
use App\Services\AsteriskQueueMembers;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();
});

test('team member roles can be updated by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertRedirect(route('teams.edit', $team));

    expect($team->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TeamRole::Admin->value);
});

test('team member roles cannot be updated by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('team members can be removed by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    $response->assertRedirect(route('teams.edit', $team));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('admins can remove members but not other admins', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $otherAdmin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($otherAdmin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin)
        ->delete(route('teams.members.destroy', [$team, $otherAdmin]))
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('teams.members.destroy', [$team, $member]))
        ->assertRedirect(route('teams.edit', $team));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('supervisors cannot remove members', function () {
    $supervisor = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($supervisor, ['role' => TeamRole::Supervisor->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($supervisor)
        ->delete(route('teams.members.destroy', [$team, $member]))
        ->assertForbidden();
});

test('the owner role cannot be changed by anyone', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $owner]), ['role' => TeamRole::Member->value])
        ->assertForbidden();

    expect($owner->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('removing a member deprovisions their extension, endpoint and queues', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $queue = teamQueue($team, 'Support');
    $agent = teamAgent($team, '7001', queues: [$queue]);
    app(AsteriskProvisioner::class)->saveExtension($team, '7001');
    app(AsteriskQueueMembers::class)->join($agent, $team);

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $agent]))
        ->assertRedirect();

    expect(DB::table('ps_endpoints')->count())->toBe(0)
        ->and(DB::table('queue_members')->count())->toBe(0)
        ->and($team->phoneExtensions()->count())->toBe(0)
        ->and($queue->members()->count())->toBe(0);
});

test('removing a member without a personal team clears their current team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)->post(route('teams.members.store', $team), [
        'name' => 'New Agent',
        'email' => 'agent@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role' => TeamRole::Member->value,
        'sip_extension' => '7005',
        'sip_secret' => 'sip-secret-123',
    ]);

    $agent = User::where('email', 'agent@example.com')->firstOrFail();

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $agent]))
        ->assertRedirect();

    expect($agent->fresh()->current_team_id)->toBeNull();
});

test('team owner cannot be removed', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $owner]));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('team member role cannot be set to owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($team->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TeamRole::Member->value);
});

test('removed member current team is set to personal team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalTeam = $member->personalTeam();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $team->id]);

    $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    expect($member->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('team owners can create team members with sip credentials', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.members.store', $team), [
            'name' => 'New Agent',
            'email' => 'agent@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'role' => TeamRole::Member->value,
            'sip_extension' => '7005',
            'sip_secret' => 'sip-secret-123',
        ]);

    $response->assertRedirect(route('teams.edit', $team));

    $member = User::where('email', 'agent@example.com')->firstOrFail();

    $endpoint = $team->asterisk_key.'_7005';

    expect($member->belongsToTeam($team))->toBeTrue()
        ->and($member->teamRole($team))->toBe(TeamRole::Member)
        ->and($member->current_team_id)->toBe($team->id)
        ->and($member->phoneExtensionFor($team)?->extension)->toBe('7005')
        ->and($member->phoneExtensionFor($team)?->secret)->toBe('sip-secret-123')
        ->and($member->email_verified_at)->not->toBeNull();

    // The softphone can register right away: the PJSIP endpoint exists.
    $this->assertDatabaseHas('ps_endpoints', ['id' => $endpoint, 'context' => $team->asteriskContext(), 'accountcode' => $team->asterisk_key]);
    $this->assertDatabaseHas('ps_auths', ['id' => $endpoint, 'username' => $endpoint, 'password' => 'sip-secret-123']);
});

test('admins can create members but not administrators', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $payload = fn (string $email, TeamRole $role, string $extension) => [
        'name' => 'New Agent',
        'email' => $email,
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role' => $role->value,
        'sip_extension' => $extension,
        'sip_secret' => 'sip-secret-123',
    ];

    $this->actingAs($admin)
        ->post(route('teams.members.store', $team), $payload('boss@example.com', TeamRole::Admin, '7006'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('teams.members.store', $team), $payload('agent@example.com', TeamRole::Member, '7005'))
        ->assertRedirect(route('teams.edit', $team));
});

test('team member creation requires sip credentials', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)->post(route('teams.members.store', $team), [
        'name' => 'New Agent',
        'email' => 'agent@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role' => TeamRole::Member->value,
    ])->assertSessionHasErrors(['sip_extension', 'sip_secret']);
});

test('an agent always needs sip credentials, even when asked for no phone', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)->post(route('teams.members.store', $team), [
        'name' => 'New Agent',
        'email' => 'agent@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role' => TeamRole::Member->value,
        'with_phone' => '0',
    ])->assertSessionHasErrors(['sip_extension', 'sip_secret']);
});

test('supervisors and planners can be created without a phone or a queue', function (TeamRole $role) {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)->post(route('teams.members.store', $team), [
        'name' => 'Sans Poste',
        'email' => 'nophone@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role' => $role->value,
        'with_phone' => '0',
        // Ignored without a phone.
        'sip_extension' => '',
        'queue' => 'does-not-exist',
    ])->assertSessionHasNoErrors()->assertRedirect(route('teams.edit', $team));

    $user = User::where('email', 'nophone@example.com')->firstOrFail();

    expect($user->teamRole($team))->toBe($role)
        ->and($team->phoneExtensions()->where('user_id', $user->id)->exists())->toBeFalse();
})->with([TeamRole::Supervisor, TeamRole::Planner]);

test('team member creation rejects duplicate sip extensions', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    teamAgent($team, '7005');

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.members.store', $team), [
            'name' => 'New Agent',
            'email' => 'agent@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'role' => TeamRole::Member->value,
            'sip_extension' => '7005',
            'sip_secret' => 'sip-secret-123',
        ]);

    $response->assertSessionHasErrors('sip_extension');
});
