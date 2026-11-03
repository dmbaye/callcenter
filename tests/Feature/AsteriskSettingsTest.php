<?php

use App\Enums\TeamRole;
use App\Models\QueueHour;
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
    $this->context = $this->team->asteriskContext();
    $this->key = $this->team->asterisk_key;
});

it('forbids team members from the asterisk settings', function (string $url) {
    $this->actingAs($this->member)->get($url)->assertForbidden();
})->with([
    'dialplan' => ['/settings/asterisk/dialplan'],
    'queues' => ['/settings/asterisk/queues'],
    'pjsip' => ['/settings/asterisk/pjsip'],
]);

it('shows only the team dialplan rows to team admins', function () {
    DB::table('extensions')->insert([
        ['context' => $this->context, 'exten' => '7000', 'priority' => 1, 'app' => 'Queue', 'appdata' => 'support,Tr'],
        ['context' => 't_otherteam1', 'exten' => '7000', 'priority' => 1, 'app' => 'Dial', 'appdata' => 'x'],
    ]);

    $this->actingAs($this->admin)
        ->get('/settings/asterisk/dialplan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/asterisk/Dialplan')
            ->has('rows', 1)
            ->where('rows.0.app', 'Queue')
        );
});

it('creates, updates and deletes dialplan rows in the team context', function () {
    $this->actingAs($this->admin)->post('/settings/asterisk/dialplan', [
        'context' => $this->context,
        'exten' => 's',
        'priority' => 1,
        'app' => 'Answer',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('extensions', [
        'context' => $this->context, 'exten' => 's', 'priority' => 1, 'app' => 'Answer',
    ]);

    $this->actingAs($this->admin)->put('/settings/asterisk/dialplan', [
        'original_context' => $this->context,
        'original_exten' => 's',
        'original_priority' => 1,
        'context' => $this->context,
        'exten' => 's',
        'priority' => 2,
        'app' => 'Hangup',
        'appdata' => '',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('extensions', ['priority' => 1, 'app' => 'Answer']);
    $this->assertDatabaseHas('extensions', ['priority' => 2, 'app' => 'Hangup']);

    $this->actingAs($this->admin)->delete('/settings/asterisk/dialplan', [
        'context' => $this->context,
        'exten' => 's',
        'priority' => 2,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('extensions', ['app' => 'Hangup']);
});

it('refuses dialplan rows outside the team context', function () {
    $this->actingAs($this->admin)->post('/settings/asterisk/dialplan', [
        'context' => 'from-pstn',
        'exten' => 's',
        'priority' => 1,
        'app' => 'Answer',
    ])->assertForbidden();
});

it('accepts asterisk pattern extensions', function () {
    $this->actingAs($this->admin)->post('/settings/asterisk/dialplan', [
        'context' => $this->context,
        'exten' => '_00X.',
        'priority' => 1,
        'app' => 'Dial',
        'appdata' => 'PJSIP/${EXTEN}@trunk',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('extensions', ['context' => $this->context, 'exten' => '_00X.', 'app' => 'Dial']);
});

it('rejects invalid characters in extensions', function () {
    $this->actingAs($this->admin)->post('/settings/asterisk/dialplan', [
        'context' => $this->context,
        'exten' => 'bad;drop table',
        'priority' => 1,
        'app' => 'Queue',
        'appdata' => 'x',
    ])->assertSessionHasErrors('exten');
});

it('rejects duplicate dialplan keys and invalid applications', function () {
    DB::table('extensions')->insert([
        'context' => $this->context, 'exten' => '7000', 'priority' => 1,
        'app' => 'Queue', 'appdata' => 'support,Tr',
    ]);

    $this->actingAs($this->admin)->post('/settings/asterisk/dialplan', [
        'context' => $this->context, 'exten' => '7000', 'priority' => 1, 'app' => 'Playback', 'appdata' => 'x',
    ])->assertSessionHasErrors('priority');

    $this->actingAs($this->admin)->post('/settings/asterisk/dialplan', [
        'context' => $this->context, 'exten' => '7000', 'priority' => 9, 'app' => 'not an app!', 'appdata' => '',
    ])->assertSessionHasErrors('app');
});

it('creates, updates and deletes team queues with their dialplan entry', function () {
    $this->actingAs($this->admin)->post('/settings/asterisk/queues', [
        'name' => 'Sales',
        'strategy' => 'roundrobin',
        'timeout' => 30,
        'context' => 'from-internal', // not editable: always the team context
        'not_a_real_column' => 'ignored',
    ])->assertSessionHasNoErrors();

    $queue = $this->team->callQueues()->firstOrFail();

    expect($queue->asterisk_name)->toBe($this->key.'_sales');
    $this->assertDatabaseHas('queues', [
        'name' => $queue->asterisk_name, 'strategy' => 'roundrobin', 'timeout' => 30, 'context' => $this->context,
    ]);
    $this->assertDatabaseHas('extensions', [
        'context' => $this->context, 'exten' => $queue->asterisk_name, 'app' => 'Queue', 'appdata' => $queue->asterisk_name.',Tr',
    ]);

    $this->actingAs($this->admin)->put('/settings/asterisk/queues', [
        'id' => $queue->id,
        'name' => 'Ventes',
        'timeout' => 45,
        'musiconhold' => 'default',
    ])->assertSessionHasNoErrors();

    expect($queue->fresh()->name)->toBe('Ventes')
        ->and($queue->fresh()->asterisk_name)->toBe($this->key.'_sales');
    $this->assertDatabaseHas('queues', ['name' => $queue->asterisk_name, 'timeout' => 45, 'musiconhold' => 'default']);

    $this->actingAs($this->admin)->delete('/settings/asterisk/queues', ['id' => $queue->id])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('queues', ['name' => $queue->asterisk_name]);
    $this->assertDatabaseMissing('extensions', ['exten' => $queue->asterisk_name]);
    $this->assertDatabaseMissing('call_queues', ['id' => $queue->id]);
});

it('rejects invalid queue strategies', function () {
    $this->actingAs($this->admin)->post('/settings/asterisk/queues', [
        'name' => 'Sales',
        'strategy' => 'bogus',
    ])->assertSessionHasErrors('strategy');
});

it('creates, updates and deletes team extensions with their pjsip endpoint', function () {
    $id = $this->key.'_7001';

    $this->actingAs($this->admin)->post('/settings/asterisk/pjsip', [
        'extension' => '7001',
        'user_id' => $this->member->id,
        'transport' => 'transport-wss',
        'context' => 'from-internal', // ignored: always the team context
        'allow' => 'ulaw,alaw',
        'password' => 'secret123',
        'max_contacts' => 1,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('ps_endpoints', ['id' => $id, 'aors' => $id, 'auth' => $id, 'context' => $this->context, 'accountcode' => $this->key]);
    $this->assertDatabaseHas('ps_auths', ['id' => $id, 'username' => $id, 'password' => 'secret123']);
    $this->assertDatabaseHas('ps_aors', ['id' => $id, 'max_contacts' => 1]);
    expect($this->member->fresh()->phoneExtensionFor($this->team)?->secret)->toBe('secret123');

    $this->actingAs($this->admin)->put('/settings/asterisk/pjsip', [
        'extension' => '7001',
        'user_id' => $this->member->id,
        'password' => 'newsecret456',
        'max_contacts' => 2,
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('ps_auths', ['id' => $id, 'password' => 'newsecret456']);
    $this->assertDatabaseHas('ps_aors', ['id' => $id, 'max_contacts' => 2]);
    expect($this->member->fresh()->phoneExtensionFor($this->team)?->secret)->toBe('newsecret456');

    $this->actingAs($this->admin)->delete('/settings/asterisk/pjsip', ['extension' => '7001'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('ps_endpoints', ['id' => $id]);
    $this->assertDatabaseMissing('ps_auths', ['id' => $id]);
    $this->assertDatabaseMissing('ps_aors', ['id' => $id]);
    $this->assertDatabaseMissing('phone_extensions', ['extension' => '7001']);
});

it('rejects duplicate team extensions and members of other teams', function () {
    $this->team->phoneExtensions()->create(['extension' => '7001', 'secret' => 'x', 'user_id' => null]);
    $stranger = User::factory()->create();

    $this->actingAs($this->admin)->post('/settings/asterisk/pjsip', [
        'extension' => '7001',
        'transport' => 'transport-wss',
        'allow' => 'ulaw',
        'password' => 'secret123',
    ])->assertSessionHasErrors('extension');

    $this->actingAs($this->admin)->post('/settings/asterisk/pjsip', [
        'extension' => '7002',
        'user_id' => $stranger->id,
        'transport' => 'transport-wss',
        'allow' => 'ulaw',
        'password' => 'secret123',
    ])->assertSessionHasErrors('user_id');
});

it('refuses writes after another tab switched the current team', function () {
    $this->actingAs($this->admin)
        ->withHeaders(['X-Team' => 'some-other-team'])
        ->postJson('/settings/asterisk/queues', ['name' => 'Sales'])
        ->assertStatus(409);

    expect($this->team->callQueues()->count())->toBe(0);
});

it('saves the weekly hours of a queue in one go and applies them to the dialplan', function () {
    $queue = teamQueue($this->team, 'Support');
    $url = "/settings/asterisk/queues/{$queue->id}/hours";

    $this->actingAs($this->admin)->put($url, ['days' => [
        ['weekday' => 0, 'opens_at' => '08:00', 'closes_at' => '16:00'],
        ['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '16:00'],
    ]])->assertSessionHasNoErrors();

    expect(QueueHour::where('call_queue_id', $queue->id)->count())->toBe(2);
    $this->assertDatabaseHas('extensions', [
        'context' => $this->context, 'exten' => $queue->asterisk_name, 'app' => 'GotoIfTime', 'appdata' => '08:00-16:00,mon&tue,*,*?4:6',
    ]);

    // Saving with no open day = always open.
    $this->actingAs($this->admin)->put($url, ['days' => []])->assertSessionHasNoErrors();

    expect(QueueHour::where('call_queue_id', $queue->id)->count())->toBe(0);
    $this->assertDatabaseMissing('extensions', ['app' => 'GotoIfTime']);
});

it('rejects closing time before opening time, duplicate weekdays and other teams queues', function () {
    $queue = teamQueue($this->team, 'Support');
    $foreign = teamQueue(Team::factory()->create(), 'Support');

    $this->actingAs($this->admin)->put("/settings/asterisk/queues/{$queue->id}/hours", ['days' => [
        ['weekday' => 1, 'opens_at' => '16:00', 'closes_at' => '08:00'],
    ]])->assertSessionHasErrors('days.0.closes_at');

    $this->actingAs($this->admin)->put("/settings/asterisk/queues/{$queue->id}/hours", ['days' => [
        ['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '16:00'],
        ['weekday' => 1, 'opens_at' => '09:00', 'closes_at' => '12:00'],
    ]])->assertSessionHasErrors('days.0.weekday');

    $this->actingAs($this->admin)->put("/settings/asterisk/queues/{$foreign->id}/hours", ['days' => []])
        ->assertNotFound();
});

it('redirects the former hours and routing pages to the queues page', function (string $url) {
    $this->actingAs($this->admin)->get($url)->assertRedirect('/settings/asterisk/queues');
})->with(['/settings/asterisk/hours', '/settings/asterisk/routing']);
