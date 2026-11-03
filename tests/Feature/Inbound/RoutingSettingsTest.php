<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    $this->admin->switchTeam($this->team);
    $this->member->switchTeam($this->team);
    $this->support = teamQueue($this->team, 'Support');
    $this->sales = teamQueue($this->team, 'Ventes');
});

it('keeps routing settings for admins', function (string $url) {
    $this->actingAs($this->member)->get($url)->assertForbidden();
    $this->actingAs($this->admin)->get($url)->assertOk();
})->with([
    'queues' => ['/settings/asterisk/queues'],
    'ivr' => ['/settings/asterisk/ivr'],
]);

it('saves queue routing and rewrites the queue entry', function () {
    $this->actingAs($this->admin)->put('/settings/asterisk/queues', [
        'id' => $this->support->id,
        'name' => 'Support',
        'max_wait' => 120,
        'overflow_type' => 'queue',
        'overflow_target' => $this->sales->id,
        'callback_offer' => true,
        'survey_enabled' => false,
        'emergency_closed' => false,
    ])->assertSessionHasNoErrors();

    expect($this->support->fresh())
        ->max_wait->toBe(120)
        ->overflow_target->toBe((string) $this->sales->id)
        ->callback_offer->toBeTrue();

    $this->assertDatabaseHas('extensions', [
        'context' => $this->team->asteriskContext(),
        'exten' => $this->support->asterisk_name,
        'app' => 'Queue',
        'appdata' => $this->support->asterisk_name.',Tr,,,120',
    ]);
});

it('refuses an overflow to the queue itself or to another team', function () {
    $foreign = teamQueue(Team::factory()->create(), 'Autre');

    $this->actingAs($this->admin)->put('/settings/asterisk/queues', [
        'id' => $this->support->id, 'name' => 'Support', 'overflow_type' => 'queue', 'overflow_target' => $this->support->id,
    ])->assertSessionHasErrors('overflow_target');

    $this->actingAs($this->admin)->put('/settings/asterisk/queues', [
        'id' => $this->support->id, 'name' => 'Support', 'overflow_type' => 'queue', 'overflow_target' => $foreign->id,
    ])->assertSessionHasErrors('overflow_target');
});

it('adds a closure day to every queue at once', function () {
    $this->actingAs($this->admin)->post("/settings/asterisk/queues/{$this->support->id}/closures", [
        'date' => now()->addDays(10)->toDateString(),
        'label' => 'Tabaski',
        'all_queues' => true,
    ])->assertSessionHasNoErrors();

    expect(DB::table('queue_closures')->count())->toBe(2);
});

it('creates IVR menus and numbers, routed in the dialplan', function () {
    $this->actingAs($this->admin)->post('/settings/asterisk/ivr/menus', [
        'name' => 'Accueil',
        'greeting_sound' => 'custom/accueil',
        'timeout' => 5,
        'max_retries' => 3,
        'options' => [
            ['digit' => '1', 'label' => 'Ventes', 'type' => 'queue', 'target' => $this->sales->id],
            ['digit' => '2', 'label' => 'Support', 'type' => 'queue', 'target' => $this->support->id],
        ],
        'fallback_type' => 'queue',
        'fallback_target' => $this->support->id,
    ])->assertSessionHasNoErrors();

    $menu = $this->team->ivrMenus()->sole();

    $this->actingAs($this->admin)->post('/settings/asterisk/ivr/numbers', [
        'number' => '22320202020',
        'label' => 'Standard',
        'destination_type' => 'ivr',
        'destination_target' => $menu->id,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('extensions', ['context' => $this->team->asteriskContext(), 'exten' => 'ivr-'.$menu->id, 'app' => 'Read']);
    $this->assertDatabaseHas('extensions', ['context' => 'cc-inbound', 'exten' => '22320202020', 'appdata' => $this->team->asteriskContext().',ivr-'.$menu->id.',1']);

    // A number belongs to one team only.
    $this->actingAs($this->admin)->post('/settings/asterisk/ivr/numbers', [
        'number' => '22320202020', 'destination_type' => 'queue', 'destination_target' => $this->sales->id,
    ])->assertSessionHasErrors('number');

    $this->actingAs($this->admin)
        ->get('/settings/asterisk/ivr')
        ->assertInertia(fn (Assert $page) => $page->component('settings/asterisk/Ivr')->has('menus', 1)->has('numbers', 1));
});

it('makes routes that pointed at a deleted queue hang up', function () {
    $this->support->update(['overflow_type' => 'queue', 'overflow_target' => (string) $this->sales->id]);

    $this->actingAs($this->admin)->delete('/settings/asterisk/queues', ['id' => $this->sales->id])->assertSessionHasNoErrors();

    expect(DB::table('extensions')->where('exten', $this->support->asterisk_name)->where('appdata', 'like', '%'.$this->sales->asterisk_name.'%')->exists())->toBeFalse();
});

it('saves only the fields a tab sends', function () {
    $this->support->update(['max_wait' => 60, 'survey_enabled' => true]);

    $this->actingAs($this->admin)->put('/settings/asterisk/queues', [
        'id' => $this->support->id, 'name' => 'Support', 'strategy' => 'leastrecent',
    ])->assertSessionHasNoErrors();

    expect($this->support->fresh())
        ->max_wait->toBe(60)
        ->survey_enabled->toBeTrue();
    expect(DB::table('queues')->where('name', $this->support->asterisk_name)->value('strategy'))->toBe('leastrecent');
});

it('assigns agents to a queue with a level and refuses outsiders', function () {
    $agent = teamAgent($this->team, '7001');
    $backup = teamAgent($this->team, '7002');
    $stranger = User::factory()->create();

    $this->actingAs($this->admin)->put("/settings/asterisk/queues/{$this->support->id}/agents", [
        'agents' => [$agent->id => 0, $backup->id => 1],
    ])->assertSessionHasNoErrors();

    expect($this->support->members()->pluck('queue_memberships.penalty', 'users.id')->map(fn ($p) => (int) $p)->all())
        ->toEqual([$agent->id => 0, $backup->id => 1]);

    $this->actingAs($this->admin)->put("/settings/asterisk/queues/{$this->support->id}/agents", [
        'agents' => [$stranger->id => 0],
    ])->assertSessionHasErrors('agents');

    $this->actingAs($this->admin)->put("/settings/asterisk/queues/{$this->support->id}/agents", ['agents' => []])
        ->assertSessionHasNoErrors();

    expect($this->support->members()->count())->toBe(0);
});

it('shows the call plan in plain words and locks the lines the app writes', function () {
    $this->team->inboundNumbers()->create(['number' => '22320202020', 'label' => 'Standard', 'destination_type' => 'queue', 'destination_target' => (string) $this->support->id]);
    app(App\Services\AsteriskDialplan::class)->syncTeamRouting($this->team);

    $this->actingAs($this->admin)
        ->get('/settings/asterisk/dialplan')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/asterisk/Dialplan')
            ->where('flow.numbers.0.label', 'Numéro 22320202020 — Standard')
            ->where('flow.numbers.0.children.0.label', "File d'attente « Support »")
            ->where('flow.unreached.0.label', "File d'attente « Ventes »"));

    $this->actingAs($this->admin)->put('/settings/asterisk/dialplan', [
        'original_context' => $this->team->asteriskContext(),
        'original_exten' => $this->support->asterisk_name,
        'original_priority' => 1,
        'context' => $this->team->asteriskContext(),
        'exten' => $this->support->asterisk_name,
        'priority' => 1,
        'app' => 'Hangup',
    ])->assertSessionHasErrors('exten');

    $this->actingAs($this->admin)->post('/settings/asterisk/dialplan', [
        'context' => $this->team->asteriskContext(), 'exten' => 'ivr-99', 'priority' => 1, 'app' => 'Hangup',
    ])->assertSessionHasErrors('exten');
});
