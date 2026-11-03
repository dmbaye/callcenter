<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->notOnboarded()->create(['name' => 'Kati Services']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
});

function onboardingUrl(Team $team, array $query = []): string
{
    return route('onboarding.show', ['current_team' => $team->slug, ...$query]);
}

test('a new team opens on its welcome wizard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('teams.store'), ['name' => 'Sahel Assistance'])
        ->assertRedirect(route('onboarding.show', ['current_team' => 'sahel-assistance']));

    expect(Team::query()->where('name', 'Sahel Assistance')->sole()->onboarded_at)->toBeNull();
});

test('the owner is sent to the wizard from team pages until it is done', function () {
    $this->actingAs($this->owner)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertRedirect(onboardingUrl($this->team));

    $this->actingAs($this->owner)
        ->get(onboardingUrl($this->team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Show')
            ->where('step', 'company')
            ->where('team.name', 'Kati Services')
            ->has('profileOptions.industries')
            ->has('checklist', 4));
});

test('agents of a new team are not held up by the wizard', function () {
    $agent = User::factory()->create();
    $this->team->members()->attach($agent, ['role' => TeamRole::Member->value]);
    $agent->switchTeam($this->team);

    $this->actingAs($agent)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();

    $this->actingAs($agent)->get(onboardingUrl($this->team))->assertForbidden();
});

test('the company step requires the activity and saves the name', function () {
    $this->actingAs($this->owner)
        ->put(route('onboarding.update', ['current_team' => $this->team->slug]), ['step' => 'company', 'name' => 'Kati Services'])
        ->assertSessionHasErrors(['industry', 'company_size']);

    $this->actingAs($this->owner)
        ->put(route('onboarding.update', ['current_team' => $this->team->slug]), [
            'step' => 'company',
            'name' => 'Kati Services Mali',
            'industry' => 'banking',
            'company_size' => '11-50',
            'planned_agents' => 12,
        ])
        ->assertRedirect(onboardingUrl($this->team->fresh(), ['step' => 'contact']));

    $team = $this->team->fresh();

    expect($team->name)->toBe('Kati Services Mali')
        ->and($team->slug)->toBe('kati-services-mali')
        ->and($team->industry)->toBe('banking')
        ->and($team->planned_agents)->toBe(12)
        ->and($team->profile_completed_at)->toBeNull();
});

test('the contact step completes the profile', function () {
    $this->team->update(['industry' => 'banking', 'company_size' => '11-50']);

    $this->actingAs($this->owner)
        ->put(route('onboarding.update', ['current_team' => $this->team->slug]), ['step' => 'contact'])
        ->assertSessionHasErrors(['country', 'city', 'phone']);

    $this->actingAs($this->owner)
        ->put(route('onboarding.update', ['current_team' => $this->team->slug]), [
            'step' => 'contact',
            'country' => 'ML',
            'city' => 'Bamako',
            'phone' => '+223 20 22 33 44',
            'website' => 'kati.ml',
        ])
        ->assertRedirect(onboardingUrl($this->team, ['step' => 'setup']));

    $team = $this->team->fresh();

    expect($team->hasCompleteProfile())->toBeTrue()
        ->and($team->profile_completed_at)->not->toBeNull()
        ->and($team->website)->toBe('https://kati.ml')
        ->and($team->onboarded_at)->toBeNull();
});

test('finishing the wizard opens the dashboard', function () {
    $this->actingAs($this->owner)
        ->post(route('onboarding.complete', ['current_team' => $this->team->slug]))
        ->assertRedirect(route('dashboard', ['current_team' => $this->team->slug]));

    expect($this->team->fresh()->onboarded_at)->not->toBeNull();

    $this->actingAs($this->owner)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertOk();
});

test('the wizard can be skipped or end on a setup page', function () {
    $this->actingAs($this->owner)
        ->post(route('onboarding.complete', ['current_team' => $this->team->slug]), ['next' => 'queues'])
        ->assertRedirect(route('asterisk.queues.index'));

    expect($this->team->fresh()->onboarded_at)->not->toBeNull();

    $this->actingAs($this->owner)
        ->post(route('onboarding.complete', ['current_team' => $this->team->slug]), ['next' => 'elsewhere'])
        ->assertSessionHasErrors('next');
});

test('existing teams never see the wizard', function () {
    $team = Team::factory()->create();
    $team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($this->owner)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk();
});

test('the guided tour is remembered once seen', function () {
    expect($this->owner->tour_completed_at)->toBeNull();

    $this->actingAs($this->owner)->postJson(route('tour.complete'))->assertOk()->assertJsonStructure(['completedAt']);

    $first = $this->owner->fresh()->tour_completed_at;
    expect($first)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->actingAs($this->owner)->postJson(route('tour.complete'))->assertOk();

    expect($this->owner->fresh()->tour_completed_at->equalTo($first))->toBeTrue();
});
