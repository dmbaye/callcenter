<?php

use App\Events\MonitoringChanged;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function signedWebhook($test, array $payload, ?string $secret = 'test-secret', ?string $signature = null)
{
    $body = json_encode($payload);
    $signature ??= hash_hmac('sha256', $body, (string) $secret);

    return $test->call('POST', '/webhooks/asterisk/monitoring', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature,
    ], $body);
}

it('is disabled when no secret is configured', function () {
    config(['asterisk.webhook.secret' => null]);
    Event::fake([MonitoringChanged::class]);

    signedWebhook($this, ['team_key' => 'abcdefghij'], 'anything')->assertStatus(503);

    Event::assertNotDispatched(MonitoringChanged::class);
});

it('rejects a bad signature', function () {
    config(['asterisk.webhook.secret' => 'test-secret']);
    Event::fake([MonitoringChanged::class]);

    signedWebhook($this, ['team_key' => 'abcdefghij'], signature: 'nope')->assertUnauthorized();

    Event::assertNotDispatched(MonitoringChanged::class);
});

it('rejects an invalid payload', function () {
    config(['asterisk.webhook.secret' => 'test-secret']);

    signedWebhook($this, ['team_key' => 'x'])->assertStatus(422);
});

it('broadcasts a refresh ping for the matching team', function () {
    config(['asterisk.webhook.secret' => 'test-secret']);
    Event::fake([MonitoringChanged::class]);

    $team = Team::factory()->create();

    signedWebhook($this, ['team_key' => $team->asterisk_key])->assertStatus(202);

    Event::assertDispatched(MonitoringChanged::class, fn (MonitoringChanged $event) => $event->teamId === $team->id);
});

it('answers the same way for an unknown tenant key', function () {
    config(['asterisk.webhook.secret' => 'test-secret']);
    Event::fake([MonitoringChanged::class]);

    signedWebhook($this, ['team_key' => 'zzzzzzzzzz'])->assertStatus(202);

    Event::assertNotDispatched(MonitoringChanged::class);
});
