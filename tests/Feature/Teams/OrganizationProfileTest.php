<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();

    $this->team = Team::factory()->create(['name' => 'Ancien nom']);
    $this->owner = teamAgent($this->team, '7001', TeamRole::Owner);
});

test('the organization settings entry opens the current team page', function () {
    $this->actingAs($this->owner)
        ->get(route('organization.edit'))
        ->assertRedirect(route('teams.edit', ['team' => $this->team->slug]));
});

test('the team page shows the immutable asterisk identifiers', function () {
    $key = $this->team->asterisk_key;

    $this->actingAs($this->owner)
        ->get(route('teams.edit', ['team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Edit')
            ->where('team.name', 'Ancien nom')
            ->where('organization.asteriskKey', $key)
            ->where('organization.context', 't_'.$key)
            ->where('organization.endpointPrefix', $key.'_')
            ->where('organization.extensionsCount', 1)
        );
});

test('renaming the organization keeps its asterisk identifier', function () {
    $key = $this->team->asterisk_key;

    $this->actingAs($this->owner)
        ->patch(route('teams.update', ['team' => $this->team->slug]), ['name' => 'Nouveau nom', 'asterisk_key' => 'hijacked00'])
        ->assertRedirect();

    $team = $this->team->fresh();

    expect($team->name)->toBe('Nouveau nom')
        ->and($team->asterisk_key)->toBe($key)
        ->and($team->asteriskContext())->toBe('t_'.$key);
});

test('agents can view the organization but not rename it', function () {
    $agent = teamAgent($this->team, '7002');

    $this->actingAs($agent)
        ->get(route('teams.edit', ['team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('permissions.canUpdateTeam', false));

    $this->actingAs($agent)
        ->patch(route('teams.update', ['team' => $this->team->slug]), ['name' => 'Piraté'])
        ->assertForbidden();

    expect($this->team->fresh()->name)->toBe('Ancien nom');
});

test('admins can fill in the company profile, which is marked complete once', function () {
    $this->actingAs($this->owner)
        ->patch(route('teams.profile.update', ['team' => $this->team->slug]), [
            'legal_name' => 'Ancien Nom SARL',
            'industry' => 'banking',
            'company_size' => '11-50',
            'planned_agents' => 12,
            'use_case' => 'customer_service',
            'country' => 'ML',
            'city' => 'Bamako',
            'phone' => '+223 20 22 33 44',
            'contact_email' => 'contact@example.ml',
            'website' => 'example.ml',
            'rccm' => 'MA.BKO.2024.B.1234',
            'acquisition_source' => 'facebook',
            'marketing_opt_in' => '1',
            'asterisk_key' => 'hijacked00',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('teams.edit', ['team' => $this->team->slug]));

    $team = $this->team->fresh();

    expect($team->industry)->toBe('banking')
        ->and($team->planned_agents)->toBe(12)
        ->and($team->website)->toBe('https://example.ml')
        ->and($team->marketing_opt_in)->toBeTrue()
        ->and($team->profile_completed_at)->not->toBeNull()
        ->and($team->asterisk_key)->toBe($this->team->asterisk_key);

    $this->actingAs($this->owner)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.industry', 'banking')
            ->where('profile.isComplete', true)
            ->has('profileOptions.industries')
        );
});

test('the company profile only accepts the configured choices', function () {
    $this->actingAs($this->owner)
        ->patch(route('teams.profile.update', ['team' => $this->team->slug]), [
            'industry' => 'piracy',
            'company_size' => '3',
            'country' => 'XX',
            'phone' => 'call me',
        ])
        ->assertSessionHasErrors(['industry', 'company_size', 'country', 'phone']);

    expect($this->team->fresh()->industry)->toBeNull();
});

test('agents cannot change the company profile', function () {
    $agent = teamAgent($this->team, '7002');

    $this->actingAs($agent)
        ->patch(route('teams.profile.update', ['team' => $this->team->slug]), ['industry' => 'banking'])
        ->assertForbidden();
});

test('owners see a subscription summary, members do not', function () {
    $this->actingAs($this->owner)
        ->get(route('teams.edit', ['team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('subscription.planName')
            ->where('subscription.outboundRate', 100)
            ->has('subscription.agents'));

    $member = teamAgent($this->team, '7002', TeamRole::Member);

    $this->actingAs($member)
        ->get(route('teams.edit', ['team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('subscription', null));
});
