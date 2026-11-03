<?php

use App\Models\Team;
use App\Models\User;
use App\Notifications\Meetings\MeetingCancelled;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the preferences with their defaults', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notification-preferences.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Notifications')
            ->where('preferences.meetings.in_app', true)
            ->where('preferences.meetings.email', true));
});

it('saves the choices and filters the channels', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('notification-preferences.update'), [
        'preferences' => ['meetings' => ['in_app' => true, 'email' => false], 'unknown' => ['email' => true]],
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->wantsNotification('meetings', 'email'))->toBeFalse()
        ->and($user->wantsNotification('meetings', 'in_app'))->toBeTrue()
        ->and($user->notification_preferences)->not->toHaveKey('unknown');

    $team = Team::factory()->create();
    $meeting = $team->meetings()->create([
        'title' => 'Point hebdo', 'room_name' => 'r1', 'max_participants' => 10,
        'created_by' => User::factory()->create()->id, 'scheduled_at' => now()->addDay(),
    ]);

    expect((new MeetingCancelled($meeting))->via($user))->toBe(['database', 'broadcast']);
});
