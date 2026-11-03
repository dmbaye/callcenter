<?php

use App\Enums\AgentState;
use App\Enums\TeamRole;
use App\Models\AgentStateLog;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentStates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    createAsteriskTables();

    config([
        'livekit.url' => 'wss://video.example.com',
        'livekit.api_url' => 'http://livekit.test',
        'livekit.api_key' => 'APItest',
        'livekit.api_secret' => 'livekit-secret',
    ]);

    Http::fake(['livekit.test/*' => Http::response(['name' => 'room'])]);

    $this->team = Team::factory()->create();
    $this->queue = teamQueue($this->team);
    $this->agent = teamAgent($this->team, '7001', TeamRole::Member, [$this->queue]);

    $uuid = (string) Str::uuid();
    $this->meeting = $this->team->meetings()->create([
        'uuid' => $uuid,
        'title' => 'Briefing',
        'room_name' => Meeting::roomNameFor($this->team, $uuid),
        'max_participants' => 10,
        'created_by' => $this->agent->id,
    ]);
});

function joinMeeting(User $user, Team $team, Meeting $meeting)
{
    return test()->actingAs($user)->postJson(route('meetings.joined', [
        'current_team' => $team->slug,
        'meeting' => $meeting->uuid,
    ]));
}

function signedLiveKitWebhook(array $payload)
{
    $body = json_encode($payload);
    $b64 = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $claims = $b64(json_encode(['iss' => 'APItest', 'exp' => time() + 60, 'sha256' => base64_encode(hash('sha256', $body, true))]));
    $token = $header.'.'.$claims.'.'.$b64(hash_hmac('sha256', $header.'.'.$claims, 'livekit-secret', true));

    return test()->call('POST', '/webhooks/livekit', [], [], [], ['HTTP_AUTHORIZATION' => $token, 'CONTENT_TYPE' => 'application/webhook+json'], $body);
}

it('sets a ready agent to Non prêt – Réunion vidéo when they join a meeting', function () {
    // Softphone online and taking calls.
    app(AgentStates::class)->heartbeat($this->agent, $this->team);

    joinMeeting($this->agent, $this->team, $this->meeting)
        ->assertOk()
        ->assertJsonPath('agentState.state', 'not_ready')
        ->assertJsonPath('agentState.reason', AgentStates::MEETING_REASON)
        ->assertJsonPath('agentState.reasonId', null);

    $status = app(AgentStates::class)->current($this->agent, $this->team);

    expect($status->state)->toBe(AgentState::NotReady)
        ->and($status->reason)->toBe(AgentStates::MEETING_REASON)
        ->and(AgentStateLog::query()->where('user_id', $this->agent->id)->whereNull('ended_at')->value('reason'))
        ->toBe(AgentStates::MEETING_REASON);

    // Paused in the Asterisk queue, with the reason supervisors see.
    $member = DB::table('queue_members')->where('queue_name', $this->queue->asterisk_name)->first();
    expect((int) $member->paused)->toBe(1)
        ->and($member->reason_paused)->toBe(AgentStates::MEETING_REASON);
});

it('does not pause the agent before the browser is connected', function () {
    app(AgentStates::class)->heartbeat($this->agent, $this->team);

    // The token is asked for first; the connection may still fail (unreachable server).
    $this->actingAs($this->agent)
        ->postJson(route('meetings.token', ['current_team' => $this->team->slug, 'meeting' => $this->meeting->uuid]))
        ->assertOk()
        ->assertJsonMissingPath('agentState');

    expect(app(AgentStates::class)->current($this->agent, $this->team)->state)->toBe(AgentState::Ready);
});

it('also pauses an agent in post-call work', function () {
    app(AgentStates::class)->set($this->agent, $this->team, AgentState::WrapUp);

    joinMeeting($this->agent, $this->team, $this->meeting)->assertOk()->assertJsonPath('agentState.state', 'not_ready');
});

it('keeps a pause the agent chose themselves', function (AgentState $state) {
    app(AgentStates::class)->set($this->agent, $this->team, $state);

    joinMeeting($this->agent, $this->team, $this->meeting)
        ->assertOk()
        ->assertJsonPath('agentState.state', $state->value);
})->with([AgentState::DoNotDisturb, AgentState::LoggedOut]);

it('keeps a Non prêt reason the agent chose', function () {
    $lunch = $this->team->pauseReasons()->firstOrCreate(['name' => 'Déjeuner'], ['position' => 99]);
    app(AgentStates::class)->set($this->agent, $this->team, AgentState::NotReady, $lunch);

    joinMeeting($this->agent, $this->team, $this->meeting)
        ->assertOk()
        ->assertJsonPath('agentState.reason', 'Déjeuner');
});

it('leaves users without a phone extension alone', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $admin->switchTeam($this->team);

    joinMeeting($admin, $this->team, $this->meeting)
        ->assertOk()
        ->assertJsonPath('agentState', null);

    expect(AgentStateLog::query()->where('user_id', $admin->id)->exists())->toBeFalse();
});

it('pauses the agent again when LiveKit reports them joining', function () {
    app(AgentStates::class)->set($this->agent, $this->team, AgentState::Ready);

    signedLiveKitWebhook([
        'event' => 'participant_joined',
        'room' => ['name' => $this->meeting->room_name],
        'participant' => ['identity' => 'u'.$this->agent->id],
    ])->assertOk();

    expect(app(AgentStates::class)->current($this->agent, $this->team)->reason)->toBe(AgentStates::MEETING_REASON);
});

it('ignores webhook participants from another team', function () {
    $otherTeam = Team::factory()->create();
    $stranger = teamAgent($otherTeam, '7009');

    signedLiveKitWebhook([
        'event' => 'participant_joined',
        'room' => ['name' => $this->meeting->room_name],
        'participant' => ['identity' => 'u'.$stranger->id],
    ])->assertOk();

    expect(AgentStateLog::query()->where('user_id', $stranger->id)->exists())->toBeFalse();
});
