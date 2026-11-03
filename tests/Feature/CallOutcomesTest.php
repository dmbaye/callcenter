<?php

use App\Enums\TeamRole;
use App\Models\CallOutcome;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($this->team);
});

function outcomePayload(array $overrides = []): array
{
    return array_merge([
        'caller' => '771234567',
        'direction' => 'inbound',
        'started_at' => '2026-10-05T10:00:00.000Z',
        'ended_at' => '2026-10-05T10:04:30.000Z',
        'duration' => 270,
        'outcome' => 'resolved',
        'notes' => 'Customer issue fixed.',
    ], $overrides);
}

it('stores a call outcome for the authenticated agent', function () {
    $this->actingAs($this->user)->post('/'.$this->team->slug.'/call-outcomes', outcomePayload())
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('call_outcomes', [
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'caller' => '771234567',
        'direction' => 'inbound',
        'duration' => 270,
        'outcome' => 'resolved',
    ]);
});

it('updates instead of duplicating when the same call is saved twice', function () {
    $payload = outcomePayload();

    $this->actingAs($this->user)->post('/'.$this->team->slug.'/call-outcomes', $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post('/'.$this->team->slug.'/call-outcomes', outcomePayload(['outcome' => 'callback', 'notes' => 'Call back tomorrow']))
        ->assertSessionHasNoErrors();

    expect(CallOutcome::count())->toBe(1);

    $this->assertDatabaseHas('call_outcomes', ['outcome' => 'callback', 'notes' => 'Call back tomorrow']);
});

it('validates outcome values and required fields', function () {
    $this->actingAs($this->user)->post('/'.$this->team->slug.'/call-outcomes', outcomePayload([
        'outcome' => 'bogus',
        'caller' => '',
    ]))->assertSessionHasErrors(['outcome', 'caller']);
});

it('lets agents reclassify and delete their own outcomes', function () {
    $outcome = CallOutcome::factory()->create(['team_id' => $this->team->id, 'user_id' => $this->user->id, 'outcome' => 'other']);

    // The history page only sends the classification.
    $this->actingAs($this->user)->put('/'.$this->team->slug.'/call-outcomes/'.$outcome->id, [
        'outcome' => 'sale',
        'notes' => 'Upsold premium plan',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('call_outcomes', ['id' => $outcome->id, 'outcome' => 'sale']);

    $this->actingAs($this->user)->delete('/'.$this->team->slug.'/call-outcomes/'.$outcome->id)
        ->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('call_outcomes', ['id' => $outcome->id]);
});

it('forbids agents from touching another agent\'s outcomes', function () {
    $other = User::factory()->create();
    $outcome = CallOutcome::factory()->create(['team_id' => $this->team->id, 'user_id' => $other->id]);

    $this->actingAs($this->user)->put('/'.$this->team->slug.'/call-outcomes/'.$outcome->id, outcomePayload())
        ->assertForbidden();

    $this->actingAs($this->user)->delete('/'.$this->team->slug.'/call-outcomes/'.$outcome->id)
        ->assertForbidden();
});

it('shows the agent outcomes of the current team only on the calls page', function () {
    CallOutcome::factory()->count(2)->create(['team_id' => $this->team->id, 'user_id' => $this->user->id]);
    CallOutcome::factory()->create(['user_id' => $this->user->id]); // another team

    $this->actingAs($this->user)->get('/'.$this->team->slug.'/calls')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('outcomes.data', 2));
});

it('does not let an outcome be edited through another team', function () {
    $other = Team::factory()->create();
    $other->members()->attach($this->user, ['role' => TeamRole::Member->value]);
    $outcome = CallOutcome::factory()->create(['team_id' => $other->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user)->delete('/'.$this->team->slug.'/call-outcomes/'.$outcome->id)
        ->assertForbidden();
});
