<?php

use App\Enums\TeamRole;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Meetings\InstantMeetingInvitation;
use App\Notifications\Meetings\MeetingCancelled;
use App\Notifications\Meetings\MeetingScheduled;
use App\Notifications\Meetings\MeetingStarted;
use App\Notifications\Meetings\MeetingStartingSoon;
use App\Notifications\Meetings\MeetingUpdated;
use App\Services\MeetingCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'livekit.url' => 'wss://video.example.com',
        'livekit.api_url' => 'http://livekit.test',
        'livekit.api_key' => 'APItest',
        'livekit.api_secret' => 'livekit-secret',
        'mail.from.address' => 'reunions@example.ml',
    ]);

    Http::fake(['livekit.test/*' => Http::response(['name' => 'room'])]);
    app()->setLocale('fr');
    Carbon::setTestNow('2026-11-02 08:00:00');

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Awa Diarra']);
    $this->alice = User::factory()->create(['name' => 'Aminata Coulibaly']);
    $this->bob = User::factory()->create(['name' => 'Moussa Keita']);

    foreach ([[$this->owner, TeamRole::Owner], [$this->alice, TeamRole::Member], [$this->bob, TeamRole::Member]] as [$user, $role]) {
        $this->team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($this->team);
    }
});

function planMeeting(array $overrides = [])
{
    return test()->actingAs(test()->owner)->post(route('meetings.store', ['current_team' => test()->team->slug]), [
        'mode' => 'scheduled',
        'title' => 'Revue mensuelle',
        'description' => 'Bilan des appels d\'octobre',
        'scheduled_at' => '2026-11-03T09:30:00+00:00',
        'duration_minutes' => 45,
        'invitees' => [test()->alice->id],
        ...$overrides,
    ]);
}

it('plans a meeting without opening a room and sends invitations with a calendar file', function () {
    Notification::fake();

    planMeeting()->assertRedirect(route('meetings.index', ['current_team' => $this->team->slug]));

    $meeting = $this->team->meetings()->sole();

    expect($meeting->isUpcoming())->toBeTrue()
        ->and($meeting->scheduled_at->toIso8601String())->toBe('2026-11-03T09:30:00+00:00')
        ->and($meeting->duration_minutes)->toBe(45);

    // LiveKit is only contacted when someone joins.
    Http::assertNothingSent();

    Notification::assertSentTo($this->alice, MeetingScheduled::class, function (MeetingScheduled $notification, array $channels) {
        $mail = $notification->toMail($this->alice);
        $attachment = $mail->rawAttachments[0];

        return $channels === ['database', 'broadcast', 'mail']
            && str_contains($attachment['options']['mime'], 'method=REQUEST')
            && str_contains($attachment['data'], 'METHOD:REQUEST')
            && str_contains($attachment['data'], 'DTSTART:20261103T093000Z')
            && str_contains($attachment['data'], 'DTEND:20261103T101500Z')
            && str_contains($attachment['data'], 'mailto:'.$this->alice->email);
    });

    // The organizer gets the calendar copy by e-mail only.
    Notification::assertSentTo($this->owner, MeetingScheduled::class, fn ($notification, array $channels) => $channels === ['mail']);
    Notification::assertNotSentTo($this->bob, MeetingScheduled::class);
});

it('refuses a planned meeting in the past or with an unknown duration', function () {
    planMeeting(['scheduled_at' => '2026-11-01T09:00:00+00:00', 'duration_minutes' => 37])
        ->assertSessionHasErrors(['scheduled_at', 'duration_minutes']);
});

it('notifies invitees of an instant meeting right away', function () {
    planMeeting(['mode' => 'instant', 'title' => '', 'scheduled_at' => null, 'duration_minutes' => null, 'invitees' => [$this->alice->id, $this->bob->id]])
        ->assertRedirect();

    $meeting = $this->team->meetings()->sole();

    expect($meeting->title)->toBe('Réunion de Awa Diarra')
        ->and($meeting->isScheduled())->toBeFalse()
        ->and($this->alice->unreadNotifications()->sole()->data)
        ->toMatchArray(['kind' => 'meeting.invitation', 'url' => $meeting->joinUrl(), 'action' => 'Rejoindre']);

    // The bell lists it, and marks it read.
    $this->actingAs($this->alice)->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('items.0.kind', 'meeting.invitation')
        ->assertJsonPath('items.0.title', 'Awa Diarra vous invite à une réunion');

    $this->actingAs($this->alice)->postJson(route('notifications.read-all'))->assertJsonPath('unread', 0);
    expect($this->alice->unreadNotifications()->count())->toBe(0);
});

it('updates calendars when a planned meeting changes', function () {
    planMeeting();
    $meeting = $this->team->meetings()->sole();
    Notification::fake();

    $this->actingAs($this->owner)->put(route('meetings.update', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]), [
        'title' => 'Revue mensuelle',
        'scheduled_at' => '2026-11-04T14:00:00+00:00',
        'duration_minutes' => 60,
        'invitees' => [$this->bob->id],
    ])->assertRedirect();

    expect($meeting->fresh()->calendar_sequence)->toBe(1)
        ->and($meeting->fresh()->scheduled_at->toIso8601String())->toBe('2026-11-04T14:00:00+00:00');

    // Alice was removed: her calendar drops the event.
    Notification::assertSentTo($this->alice, MeetingCancelled::class, function (MeetingCancelled $notification) {
        $ics = $notification->toMail($this->alice)->rawAttachments[0]['data'];

        return str_contains($ics, 'METHOD:CANCEL') && str_contains($ics, 'SEQUENCE:1');
    });
    Notification::assertSentTo($this->bob, MeetingScheduled::class);
    Notification::assertSentTo($this->owner, MeetingUpdated::class);
});

it('cancels a planned meeting for everyone', function () {
    planMeeting();
    $meeting = $this->team->meetings()->sole();
    Notification::fake();

    $this->actingAs($this->alice)
        ->post(route('meetings.cancel', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->post(route('meetings.cancel', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertRedirect();

    expect($meeting->fresh()->isCancelled())->toBeTrue();
    Notification::assertSentTo([$this->alice, $this->owner], MeetingCancelled::class);

    $this->actingAs($this->alice)
        ->get(route('meetings.show', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertRedirect(route('meetings.index', ['current_team' => $this->team->slug]));
});

it('downloads the meeting as an .ics file', function () {
    planMeeting();
    $meeting = $this->team->meetings()->sole();

    $response = $this->actingAs($this->alice)
        ->get(route('meetings.calendar', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

    expect($response->getContent())
        ->toContain('METHOD:PUBLISH')
        ->toContain('SUMMARY:Revue mensuelle')
        ->toContain('UID:'.$meeting->uuid.'@');
});

it('tells waiting invitees when a planned meeting starts', function () {
    planMeeting(['invitees' => [$this->alice->id, $this->bob->id]]);
    $meeting = $this->team->meetings()->sole();
    Notification::fake();

    $this->actingAs($this->alice)
        ->postJson(route('meetings.token', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertOk();

    Notification::assertSentTo([$this->bob, $this->owner], MeetingStarted::class);
    Notification::assertNotSentTo($this->alice, MeetingStarted::class);

    // A second person joining does not notify again.
    $this->actingAs($this->bob)
        ->postJson(route('meetings.token', ['current_team' => $this->team->slug, 'meeting' => $meeting->uuid]))
        ->assertOk();

    Notification::assertSentToTimes($this->owner, MeetingStarted::class, 1);
});

it('reminds participants once, shortly before the start', function () {
    planMeeting(['scheduled_at' => '2026-11-02T08:25:00+00:00']);
    $meeting = $this->team->meetings()->sole();
    Notification::fake();

    $this->artisan('meetings:remind')->assertSuccessful();
    Notification::assertNothingSent();

    Carbon::setTestNow('2026-11-02 08:16:00');
    $this->artisan('meetings:remind')->assertSuccessful();
    $this->artisan('meetings:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->alice, MeetingStartingSoon::class, 1);
    Notification::assertSentToTimes($this->owner, MeetingStartingSoon::class, 1);
    expect($meeting->fresh()->reminded_at)->not->toBeNull();
});

it('lists my live, upcoming and past meetings', function () {
    planMeeting();
    planMeeting(['title' => 'Sans moi', 'invitees' => [$this->bob->id]]);

    $this->actingAs($this->alice)
        ->get(route('meetings.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('meetings/Index')
            ->has('upcoming', 1)
            ->where('upcoming.0.title', 'Revue mensuelle')
            ->where('upcoming.0.status', 'upcoming')
            ->where('upcoming.0.participants.0.name', 'Aminata Coulibaly')
            ->where('upcoming.0.calendar.google', fn (string $url) => str_starts_with($url, 'https://calendar.google.com/calendar/render?action=TEMPLATE'))
            ->where('canSeeAll', false));

    // The organizer (owner) can see the whole team's meetings.
    $this->actingAs($this->owner)
        ->get(route('meetings.index', ['current_team' => $this->team->slug, 'scope' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('upcoming', 2)->where('scope', 'all'));
});

it('folds long calendar lines and escapes text', function () {
    $meeting = Meeting::query()->make([
        'uuid' => 'abc',
        'title' => str_repeat('Réunion, très; longue ', 6),
        'scheduled_at' => now()->addDay(),
        'duration_minutes' => 30,
        'calendar_sequence' => 0,
    ]);
    $meeting->setRelation('team', $this->team);
    $meeting->setRelation('creator', $this->owner);

    $ics = app(MeetingCalendar::class)->ics($meeting, MeetingCalendar::METHOD_PUBLISH, []);

    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    expect($ics)->toContain('Réunion\, très\; longue');
});
