<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();
});

function helpReader(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);

    return $user;
}

test('every listed article has its page', function () {
    foreach (array_keys(config('help.articles')) as $slug) {
        expect(resource_path("help/{$slug}.md"))->toBeFile();
    }
});

test('guests are sent to the login page', function () {
    $this->get(route('help.index'))->assertRedirect(route('login'));
});

test('the help center recommends articles for the reader role', function (TeamRole $role, array $audience) {
    $this->actingAs(helpReader($this->team, $role))
        ->get(route('help.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/Index')
            ->where('audience', $audience)
            ->has('sections', count(config('help.sections')))
            ->has('searchIndex', count(config('help.articles'))));
})->with([
    'agent' => [TeamRole::Member, ['all', 'agent']],
    'supervisor' => [TeamRole::Supervisor, ['all', 'agent', 'supervisor']],
    'admin' => [TeamRole::Admin, ['all', 'agent', 'supervisor', 'admin']],
]);

test('an article renders with its table of contents and team links', function () {
    $this->actingAs(helpReader($this->team, TeamRole::Member))
        ->get(route('help.show', 'statuts-agent'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/Show')
            ->where('article.title', 'Les statuts agent')
            ->where('article.toc.0.id', 'les-statuts')
            ->where('article.html', fn (string $html) => str_contains($html, '<h2 id="les-statuts">')
                && str_contains($html, '<div class="table-wrap"><table>')
                && ! str_contains($html, '<h1'))
            ->where('previous.slug', 'softphone')
            ->where('next.slug', 'qualifier-un-appel'));

    $this->actingAs(helpReader($this->team, TeamRole::Admin))
        ->get(route('help.show', 'mise-en-place'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('article.html', fn (string $html) => str_contains($html, '/'.$this->team->slug.'/monitoring')
                && ! str_contains($html, '{team}')));
});

test('an unknown article is not found', function () {
    $this->actingAs(helpReader($this->team, TeamRole::Member))
        ->get(route('help.show', 'nope'))
        ->assertNotFound();
});
