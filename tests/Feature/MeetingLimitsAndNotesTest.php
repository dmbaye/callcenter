<?php

use App\Enums\TeamRole;
use App\Events\MeetingEnded;
use App\Events\MeetingNotesUpdated;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
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
    Carbon::setTestNow('2026-11-02 08:00:00');

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Awa Diarra']);
    $this->agent = User::factory()->create(['name' => 'Moussa Keita']);
    $this->outsider = User::factory()->create();

    foreach ([[$this->owner, TeamRole::Owner], [$this->agent, TeamRole::Member], [$this->outsider, TeamRole::Member]] as [$user, $role]) {
        $this->team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($this->team);
    }
});

function teamRoom(Team $team, User $creator, array $invitees = []): Meeting
{
    $uuid = (string) Str::uuid();
    $meeting = $team->meetings()->create([
        'uuid' => $uuid,
        'title' => 'Briefing',
        'room_name' => Meeting::roomNameFor($team, $uuid),
        'max_participants' => 5,
        'created_by' => $creator->id,
    ]);
    $meeting->invitees()->sync($invitees);

    return $meeting;
}

function joinRoom(User $user, Meeting $meeting)
{
    return test()->actingAs($user)->postJson(route('meetings.token', ['current_team' => $meeting->team->slug, 'meeting' => $meeting->uuid]));
}

it('starts the plan time limit with the first person in', function () {
    $this->team->subscription->update(['plan' => 'essentiel']);
    $meeting = teamRoom($this->team, $this->owner);

    joinRoom($this->owner, $meeting)->assertOk();

    expect($meeting->fresh()->time_limit_minutes)->toBe(40)
        ->and($meeting->fresh()->limitEndsAt()->toIso8601String())->toBe('2026-11-02T08:40:00+00:00');
});

it('closes meetings that reach the limit and refuses late joiners', function () {
    Event::fake([MeetingEnded::class]);
    $this->team->subscription->update(['plan' => 'essentiel']);
    $meeting = teamRoom($this->team, $this->owner);
    joinRoom($this->owner, $meeting)->assertOk();

    Carbon::setTestNow('2026-11-02 08:39:00');
    $this->artisan('meetings:enforce-limits')->assertSuccessful();
    expect($meeting->fresh()->isEnded())->toBeFalse();

    Carbon::setTestNow('2026-11-02 08:40:30');
    $this->artisan('meetings:enforce-limits')->assertSuccessful();

    expect($meeting->fresh()->ended_reason)->toBe('limit');
    Event::assertDispatched(MeetingEnded::class);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/twirp/livekit.RoomService/DeleteRoom'));

    joinRoom($this->agent, $meeting)->assertStatus(410);
});

it('refuses to join after the limit even before the scheduler ran', function () {
    $this->team->subscription->update(['plan' => 'essentiel']);
    $meeting = teamRoom($this->team, $this->owner);
    joinRoom($this->owner, $meeting)->assertOk();

    Carbon::setTestNow('2026-11-02 09:00:00');

    joinRoom($this->agent, $meeting)
        ->assertStatus(410)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, '40'));

    expect($meeting->fresh()->ended_reason)->toBe('limit');
});

it('only offers planned durations within the plan limit', function () {
    $this->team->subscription->update(['plan' => 'essentiel']);

    $this->actingAs($this->owner)
        ->get(route('meetings.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('meetingMinutes', 40)
            ->where('durations', [15, 30]));

    $this->actingAs($this->owner)->post(route('meetings.store', ['current_team' => $this->team->slug]), [
        'mode' => 'scheduled',
        'title' => 'Trop long',
        'scheduled_at' => '2026-11-03T09:00:00+00:00',
        'duration_minutes' => 60,
    ])->assertSessionHasErrors('duration_minutes');
});

it('lets participants share notes and tells others live', function () {
    Event::fake([MeetingNotesUpdated::class]);
    $meeting = teamRoom($this->team, $this->owner, [$this->agent->id]);
    $url = route('meetings.notes', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]);

    $this->actingAs($this->agent)->putJson($url, ['notes' => "Décisions :\n- relance vendredi"])
        ->assertOk()
        ->assertJsonPath('updatedBy', 'Moussa Keita');

    expect($meeting->fresh()->notes)->toBe("Décisions :\n- relance vendredi");
    Event::assertDispatched(MeetingNotesUpdated::class);

    // Not invited: no access.
    $this->actingAs($this->outsider)->putJson($url, ['notes' => 'x'])->assertForbidden();
});

it('does not silently overwrite a colleague newer notes', function () {
    $meeting = teamRoom($this->team, $this->owner, [$this->agent->id]);
    $url = route('meetings.notes', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]);

    $this->actingAs($this->agent)->putJson($url, ['notes' => 'Version de Moussa'])->assertOk();
    $openedAt = now()->subMinute()->toIso8601String();

    Carbon::setTestNow('2026-11-02 08:05:00');

    // The owner's editor was opened before Moussa's save.
    $this->actingAs($this->owner)->putJson($url, ['notes' => 'Version d\'Awa', 'base' => $openedAt])
        ->assertStatus(409)
        ->assertJsonPath('notes', 'Version de Moussa');

    $this->actingAs($this->owner)->putJson($url, ['notes' => 'Version d\'Awa', 'base' => $openedAt, 'force' => true])
        ->assertOk();

    expect($meeting->fresh()->notes)->toBe('Version d\'Awa');
});

it('treats an editor opened before any notes as stale', function () {
    $meeting = teamRoom($this->team, $this->owner, [$this->agent->id]);
    $url = route('meetings.notes', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]);

    $this->actingAs($this->agent)->putJson($url, ['notes' => 'Premier jet'])->assertOk();

    $this->actingAs($this->owner)->putJson($url, ['notes' => 'Autre texte', 'base' => null])
        ->assertStatus(409);
});

it('keeps notes readable after the meeting', function () {
    $meeting = teamRoom($this->team, $this->owner, [$this->agent->id]);
    $meeting->update(['started_at' => now()->subHour(), 'ended_at' => now(), 'notes' => 'Compte rendu']);

    $this->actingAs($this->agent)
        ->get(route('meetings.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('past.0.notes', 'Compte rendu')
            ->where('past.0.canEditNotes', true));
});
