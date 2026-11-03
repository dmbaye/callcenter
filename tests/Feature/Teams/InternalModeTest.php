<?php

use App\Enums\CampaignStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->internalOnly()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
});

it('closes call-center screens to internal-only organizations', function (string $route) {
    $this->actingAs($this->owner)
        ->get(route($route, ['current_team' => $this->team->slug]))
        ->assertRedirect(route('dashboard', ['current_team' => $this->team->slug]));
})->with(['monitoring', 'agent-logs.index', 'campaigns.index', 'campaigns.workspace']);

it('closes call-center settings to internal-only organizations', function (string $url) {
    $this->actingAs($this->owner)->get($url)->assertRedirect();
})->with(['/settings/asterisk/queues', '/settings/asterisk/ivr', '/settings/pause-reasons']);

it('keeps meetings, history and extensions open', function () {
    $this->actingAs($this->owner)->get(route('meetings.index', ['current_team' => $this->team->slug]))->assertOk();
    $this->actingAs($this->owner)->get(route('calls', ['current_team' => $this->team->slug]))->assertOk();
    $this->actingAs($this->owner)->get('/settings/asterisk/pjsip')->assertOk();
});

it('shows the directory as dashboard', function () {
    $colleague = teamAgent($this->team, '7002', attributes: ['name' => 'Awa Traoré', 'job_title' => 'Comptable']);

    $this->actingAs($this->owner)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Internal')
            ->has('colleagues', 1)
            ->where('colleagues.0.name', $colleague->name)
            ->where('colleagues.0.jobTitle', 'Comptable')
            ->where('colleagues.0.extension', '7002'));
});

it('shares the mode with the front end', function () {
    $this->actingAs($this->owner)
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('currentTeam.usageMode', 'internal'));
});

it('switches modes and pauses running campaigns', function () {
    $this->team->update(['usage_mode' => Team::USAGE_CALL_CENTER]);
    $campaign = teamCampaign($this->team, $this->owner);
    $campaign->update(['status' => CampaignStatus::Running]);

    $this->actingAs($this->owner)
        ->patch(route('teams.usage.update', ['team' => $this->team->slug]), ['usage_mode' => 'internal'])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->isInternalOnly())->toBeTrue()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Paused);

    $this->actingAs($this->owner)
        ->patch(route('teams.usage.update', ['team' => $this->team->slug]), ['usage_mode' => 'call_center'])
        ->assertSessionHasNoErrors();

    expect($this->team->fresh()->isCallCenter())->toBeTrue();
});

it('does not let members change the mode', function () {
    $member = teamAgent($this->team, '7003');

    $this->actingAs($member)
        ->patch(route('teams.usage.update', ['team' => $this->team->slug]), ['usage_mode' => 'call_center'])
        ->assertForbidden();
});

it('does not pause internal-only members when they join a meeting', function () {
    $member = teamAgent($this->team, '7004');
    $meeting = $this->team->meetings()->create([
        'title' => 'Point', 'room_name' => 'r1', 'max_participants' => 10, 'created_by' => $this->owner->id,
    ]);
    $meeting->update(['started_at' => now()]);

    $this->actingAs($member)
        ->postJson(route('meetings.joined', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertOk()
        ->assertJson(['agentState' => null]);
});

it('only lists the help articles that apply to internal-only organizations', function () {
    $this->actingAs($this->owner)
        ->get(route('help.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sections.0.articles.0.slug', 'communication-interne'));

    $this->actingAs($this->owner)->get(route('help.show', 'campagnes'))->assertNotFound();
    $this->actingAs($this->owner)->get(route('help.show', 'softphone'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('article.html', fn (string $html) => ! str_contains($html, 'statuts-agent')));
});
