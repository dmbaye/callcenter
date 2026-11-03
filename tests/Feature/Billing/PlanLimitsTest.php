<?php

use App\Enums\SubscriptionState;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Billing\SubscriptionEndingSoon;
use App\Notifications\Billing\SubscriptionExpired;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
    $this->team->subscription->update(['plan' => 'essentiel', 'on_trial' => false, 'ends_at' => now()->addMonth()]);
});

function fillAgentSeats(Team $team, int $count): void
{
    foreach (range(1, $count) as $i) {
        $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    }
}

it('stops invitations at the agent limit, counting pending ones', function () {
    fillAgentSeats($this->team, 4);

    $this->actingAs($this->owner)->post(route('teams.invitations.store', $this->team), [
        'email' => 'fifth@example.com', 'role' => 'member',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->post(route('teams.invitations.store', $this->team), [
        'email' => 'sixth@example.com', 'role' => 'member',
    ])->assertSessionHasErrors('email');

    // Admins do not take an agent seat.
    $this->actingAs($this->owner)->post(route('teams.invitations.store', $this->team), [
        'email' => 'admin@example.com', 'role' => 'admin',
    ])->assertSessionHasNoErrors();
});

it('stops direct member creation at the agent limit', function () {
    fillAgentSeats($this->team, 5);

    $this->actingAs($this->owner)->post(route('teams.members.store', $this->team), [
        'name' => 'Agent Six',
        'email' => 'six@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
        'role' => 'member',
        'sip_extension' => '7106',
        'sip_secret' => 'a-long-sip-secret-123',
    ])->assertSessionHasErrors('role');

    expect(User::where('email', 'six@example.com')->exists())->toBeFalse();
});

it('limits supervisors separately', function () {
    $supervisor = User::factory()->create();
    $member = User::factory()->create();
    $this->team->members()->attach($supervisor, ['role' => TeamRole::Supervisor->value]);
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($this->owner)
        ->patch(route('teams.members.update', [$this->team, $member]), ['role' => 'supervisor'])
        ->assertSessionHasErrors('role');
});

it('limits queues', function () {
    teamQueue($this->team, 'Support');
    teamQueue($this->team, 'Ventes');

    $this->actingAs($this->owner)
        ->post('/settings/asterisk/queues', ['name' => 'Facturation'])
        ->assertSessionHasErrors('name');
});

it('makes an expired team read-only and silences its phones', function () {
    $agent = teamAgent($this->team, '7001');
    $this->team->subscription->update(['ends_at' => now()->subDays(10)]);

    expect($this->team->fresh()->subscription->state())->toBe(SubscriptionState::Expired);

    $this->actingAs($agent)
        ->postJson(route('presence.join', ['current_team' => $this->team->slug]))
        ->assertStatus(402);

    // Pages still open, without SIP credentials.
    $this->actingAs($agent)
        ->get(route('calls', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sipCredentials', null)->where('billing.state', 'expired'));
});

it('keeps a team usable during the grace period', function () {
    $this->team->subscription->update(['ends_at' => now()->subDays(2)]);

    expect($this->team->fresh()->subscription->state())->toBe(SubscriptionState::Grace)
        ->and($this->team->fresh()->hasUsableSubscription())->toBeTrue();
});

it('sends reminders and expires unpaid teams once', function () {
    Notification::fake();

    $agent = teamAgent($this->team, '7001', queues: [teamQueue($this->team)]);
    DB::table('queue_members')->insert([
        'queue_name' => $this->team->asterisk_key.'_support',
        'interface' => 'PJSIP/'.$this->team->asterisk_key.'_7001',
        'membername' => $agent->name,
    ]);

    $other = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $other->members()->attach($otherOwner, ['role' => TeamRole::Owner->value]);
    $other->subscription->update(['ends_at' => now()->addDays(3)]);

    $this->team->subscription->update(['ends_at' => now()->subDays(6)]);

    $this->artisan('billing:process')->assertSuccessful();
    $this->artisan('billing:process')->assertSuccessful();

    Notification::assertSentToTimes($otherOwner, SubscriptionEndingSoon::class, 1);
    Notification::assertSentToTimes($this->owner, SubscriptionExpired::class, 1);
    expect(DB::table('queue_members')->count())->toBe(0);
});

it('caps call history to the plan retention', function () {
    $agent = teamAgent($this->team, '7001', TeamRole::Member);
    $prefix = 'PJSIP/'.$this->team->asterisk_key.'_7001-';

    DB::table('cdr')->insert([
        ['uniqueid' => 'a', 'src' => '1', 'dst' => '7001', 'dstchannel' => $prefix.'1', 'start' => now()->subMonths(2), 'disposition' => 'ANSWERED', 'accountcode' => $this->team->asterisk_key],
        ['uniqueid' => 'b', 'src' => '2', 'dst' => '7001', 'dstchannel' => $prefix.'2', 'start' => now()->subMonths(5), 'disposition' => 'ANSWERED', 'accountcode' => $this->team->asterisk_key],
    ]);

    $this->actingAs($agent)
        ->get(route('calls', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('calls.data', 1)->where('historyMonths', 3));
});
