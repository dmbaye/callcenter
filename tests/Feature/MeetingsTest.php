<?php

use App\Enums\TeamRole;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'livekit.url' => 'wss://video.example.com',
        'livekit.api_url' => 'http://livekit.test',
        'livekit.api_key' => 'APItest',
        'livekit.api_secret' => 'livekit-secret',
    ]);

    Http::fake(['livekit.test/*' => Http::response(['name' => 'room'])]);

    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    $this->owner->switchTeam($this->team);
    $this->member->switchTeam($this->team);
});

function teamMeeting(Team $team, User $creator): Meeting
{
    $uuid = (string) Str::uuid();

    return $team->meetings()->create([
        'uuid' => $uuid,
        'title' => 'Point du matin',
        'room_name' => Meeting::roomNameFor($team, $uuid),
        'max_participants' => 10,
        'created_by' => $creator->id,
    ]);
}

it('is not available on a plan without video', function () {
    config(['billing.plans.essentiel.features.video' => false]);
    $this->team->subscription->update(['plan' => 'essentiel']);

    $this->actingAs($this->owner)
        ->get(route('meetings.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->component('meetings/Index')->where('videoEnabled', false));

    $this->actingAs($this->owner)
        ->post(route('meetings.store', ['current_team' => $this->team->slug]), ['title' => 'Hello'])
        ->assertSessionHasErrors('title');

    Http::assertNothingSent();
});

it('creates a room capped at the plan size and invites members', function () {
    $this->actingAs($this->owner)
        ->post(route('meetings.store', ['current_team' => $this->team->slug]), [
            'title' => 'Point du matin',
            'invitees' => [$this->member->id],
        ])
        ->assertRedirect();

    $meeting = $this->team->meetings()->sole();

    expect($meeting->max_participants)->toBe(10)
        ->and($meeting->room_name)->toStartWith('t_'.$this->team->asterisk_key.'_')
        ->and($meeting->invitees()->pluck('users.id')->all())->toBe([$this->member->id]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/twirp/livekit.RoomService/CreateRoom')
        && $request['max_participants'] === 10
        && $request['name'] === $meeting->room_name
        && str_starts_with($request->header('Authorization')[0], 'Bearer '));
});

it('refuses invitees from another team', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('meetings.store', ['current_team' => $this->team->slug]), [
            'title' => 'Point', 'invitees' => [$stranger->id],
        ])
        ->assertSessionHasErrors('invitees.0');
});

it('gives team members a join token for their room only', function () {
    $meeting = teamMeeting($this->team, $this->owner);

    $response = $this->actingAs($this->member)
        ->postJson(route('meetings.token', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertOk()
        ->assertJsonPath('url', 'wss://video.example.com');

    [, $payload] = explode('.', $response->json('token'));
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

    expect($claims['sub'])->toBe('u'.$this->member->id)
        ->and($claims['video']['room'])->toBe($meeting->room_name)
        ->and($claims['video']['roomAdmin'])->toBeFalse()
        ->and($meeting->fresh()->started_at)->not->toBeNull();

    // Same meeting through another team's URL.
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->actingAs($this->member)
        ->postJson(route('meetings.token', ['current_team' => $otherTeam->slug, 'meeting' => $meeting->uuid]))
        ->assertNotFound();
});

it('lets only the creator or supervisors end a meeting', function () {
    $meeting = teamMeeting($this->team, $this->owner);

    $this->actingAs($this->member)
        ->post(route('meetings.end', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->post(route('meetings.end', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertRedirect(route('meetings.index', ['current_team' => $this->team->slug]));

    expect($meeting->fresh()->ended_at)->not->toBeNull();
});

it('closes meetings from signed LiveKit webhooks', function () {
    $meeting = teamMeeting($this->team, $this->owner);
    $body = json_encode(['event' => 'room_finished', 'room' => ['name' => $meeting->room_name]]);

    $b64 = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $claims = $b64(json_encode(['iss' => 'APItest', 'exp' => time() + 60, 'sha256' => base64_encode(hash('sha256', $body, true))]));
    $token = $header.'.'.$claims.'.'.$b64(hash_hmac('sha256', $header.'.'.$claims, 'livekit-secret', true));

    $this->call('POST', '/webhooks/livekit', [], [], [], ['HTTP_AUTHORIZATION' => 'invalid', 'CONTENT_TYPE' => 'application/webhook+json'], $body)
        ->assertUnauthorized();

    $this->call('POST', '/webhooks/livekit', [], [], [], ['HTTP_AUTHORIZATION' => $token, 'CONTENT_TYPE' => 'application/webhook+json'], $body)
        ->assertOk();

    expect($meeting->fresh()->ended_at)->not->toBeNull();
});

it('sends the video quality of the plan', function (string $plan, string $quality) {
    $this->team->subscription()->update(['plan' => $plan, 'on_trial' => false, 'ends_at' => now()->addMonth()]);
    $meeting = teamMeeting($this->team, $this->owner);

    $this->actingAs($this->member)
        ->postJson(route('meetings.token', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertOk()
        ->assertJsonPath('quality', $quality);
})->with([
    'starter' => ['starter', 'sd'],
    'essentiel' => ['essentiel', 'sd'],
    'croissance' => ['croissance', 'hd'],
    'pro' => ['pro', 'hd'],
    'corporate' => ['entreprise', 'full_hd'],
]);
