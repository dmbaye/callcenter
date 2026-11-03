<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\DialMode;
use App\Enums\TeamRole;
use App\Models\CampaignAttempt;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskAmi;
use App\Services\CampaignCallEvents;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->agent = teamAgent($this->team, '7001');
    $this->campaign = teamCampaign($this->team, $this->owner, [$this->agent], ['dial_mode' => DialMode::Preview, 'script' => 'Bonjour {first_name} de {ville}']);
    $this->campaign->update(['status' => CampaignStatus::Running]);
    $this->campaign->contacts()->create(['phone' => '22376000001', 'first_name' => 'Awa', 'data' => ['ville' => 'Kayes'], 'status' => ContactStatus::Pending]);
    $this->slug = $this->team->slug;
});

function workspaceAttempt($test): CampaignAttempt
{
    $ami = Mockery::mock(AsteriskAmi::class);
    app()->instance(AsteriskAmi::class, $ami);
    $ami->shouldReceive('originateCampaignCall')->once()->andReturnUsing(function ($call) use ($test) {
        expect($call['channel'])->toBe('PJSIP/'.$test->team->asterisk_key.'_7001')
            ->and($call['exten'])->toBe('preview')
            ->and($call['variables']['CC_DIAL'])->toBe('PJSIP/0022376000001@orange');
    });

    $test->actingAs($test->agent)->post(route('campaigns.next', ['current_team' => $test->slug, 'campaign' => $test->campaign->id]))->assertSessionHasNoErrors();
    $contact = $test->campaign->contacts()->sole();

    $test->actingAs($test->agent)
        ->post(route('campaigns.contacts.call', ['current_team' => $test->slug, 'campaign' => $test->campaign->id, 'contact' => $contact->id]))
        ->assertSessionHasNoErrors();

    $attempt = $test->campaign->attempts()->sole();
    app(CampaignCallEvents::class)->handle(['Event' => 'UserEvent', 'UserEvent' => 'CcCampaign', 'Attempt' => (string) $attempt->id, 'State' => 'connected']);

    return $attempt->fresh();
}

it('reserves the next contact and shows it with the script filled in', function () {
    $this->actingAs($this->agent)->post(route('campaigns.next', ['current_team' => $this->slug, 'campaign' => $this->campaign->id]));

    expect($this->campaign->contacts()->sole()->status)->toBe(ContactStatus::Reserved);

    $this->actingAs($this->agent)
        ->get(route('campaigns.workspace', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('campaigns/Workspace')
            ->where('current.contact.phone', '22376000001')
            ->where('current.campaign.script', 'Bonjour Awa de Kayes'));
});

it('refuses agents who are not on the campaign', function () {
    $other = teamAgent($this->team, '7009');

    $this->actingAs($other)
        ->post(route('campaigns.next', ['current_team' => $this->slug, 'campaign' => $this->campaign->id]))
        ->assertForbidden();
});

it('completes the contact on a success wrap-up', function () {
    $attempt = workspaceAttempt($this);
    $success = $this->campaign->dispositions()->where('outcome', 'success')->first();

    $this->actingAs($this->agent)
        ->post(route('campaigns.attempts.dispose', ['current_team' => $this->slug, 'attempt' => $attempt->id]), [
            'disposition_id' => $success->id, 'notes' => 'Commande de 3 cartons',
        ])->assertSessionHasNoErrors();

    // The hangup arrives after the wrap-up: the decision stands.
    app(CampaignCallEvents::class)->handle(['Event' => 'UserEvent', 'UserEvent' => 'CcCampaignEnd', 'Attempt' => (string) $attempt->id, 'Dial' => 'ANSWER']);

    $contact = $this->campaign->contacts()->sole();

    expect($contact->status)->toBe(ContactStatus::Completed)
        ->and($contact->disposition_id)->toBe($success->id)
        ->and($contact->notes)->toContain('Commande de 3 cartons')
        ->and($attempt->fresh()->result?->value)->toBe('connected');
});

it('schedules a personal callback for the agent', function () {
    $attempt = workspaceAttempt($this);
    $callback = $this->campaign->dispositions()->where('outcome', 'callback')->first();

    $this->actingAs($this->agent)
        ->post(route('campaigns.attempts.dispose', ['current_team' => $this->slug, 'attempt' => $attempt->id]), [
            'disposition_id' => $callback->id, 'callback_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

    $contact = $this->campaign->contacts()->sole();

    expect($contact->status)->toBe(ContactStatus::Callback)
        ->and($contact->callback_user_id)->toBe($this->agent->id);

    $this->actingAs($this->agent)
        ->get(route('campaigns.workspace', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page->has('callbacks', 1));
});

it('adds the number to the Do-Not-Call list when asked', function () {
    $attempt = workspaceAttempt($this);
    $dnc = $this->campaign->dispositions()->where('outcome', 'dnc')->first();

    $this->actingAs($this->agent)
        ->post(route('campaigns.attempts.dispose', ['current_team' => $this->slug, 'attempt' => $attempt->id]), ['disposition_id' => $dnc->id])
        ->assertSessionHasNoErrors();

    expect($this->team->dncNumbers()->pluck('phone')->all())->toBe(['22376000001'])
        ->and($this->campaign->contacts()->sole()->status)->toBe(ContactStatus::Dnc);
});

it('does not count an attempt the agent never picked up', function () {
    $this->mock(AsteriskAmi::class)->shouldReceive('originateCampaignCall')->once();
    $this->actingAs($this->agent)->post(route('campaigns.next', ['current_team' => $this->slug, 'campaign' => $this->campaign->id]));
    $contact = $this->campaign->contacts()->sole();
    $this->actingAs($this->agent)->post(route('campaigns.contacts.call', ['current_team' => $this->slug, 'campaign' => $this->campaign->id, 'contact' => $contact->id]));
    $attempt = $this->campaign->attempts()->sole();

    app(CampaignCallEvents::class)->handle(['Event' => 'OriginateResponse', 'ActionID' => "cc-camp-{$attempt->id}-ff00", 'Response' => 'Failure', 'Reason' => '3']);

    expect($attempt->fresh()->result?->value)->toBe('cancelled')
        ->and($contact->fresh()->attempts)->toBe(0)
        ->and($contact->fresh()->status)->toBe(ContactStatus::Pending);
});

it('lets the dialer recognise the campaign call that just ended and qualify it', function () {
    $attempt = workspaceAttempt($this);
    $success = $this->campaign->dispositions()->where('outcome', 'success')->first();

    // The softphone shows the customer number as caller ID (any prefix).
    $this->actingAs($this->agent)
        ->getJson(route('campaigns.attempts.active', ['current_team' => $this->slug, 'number' => '+223 76 00 00 01', 'since' => now()->subMinute()->toIso8601String()]))
        ->assertOk()
        ->assertJsonPath('attempt.id', $attempt->id)
        ->assertJsonPath('attempt.campaign', $this->campaign->name)
        ->assertJsonPath('attempt.dispositions.0.id', $this->campaign->dispositions()->first()->id);

    // Another number is an ordinary call.
    $this->getJson(route('campaigns.attempts.active', ['current_team' => $this->slug, 'number' => '22370111111']))
        ->assertOk()
        ->assertJsonPath('attempt', null);

    // Qualifying from the dialer answers JSON and stays on the page.
    $this->postJson(route('campaigns.attempts.dispose', ['current_team' => $this->slug, 'attempt' => $attempt->id]), [
        'disposition_id' => $success->id,
    ])->assertOk()->assertJson(['ok' => true]);

    expect($attempt->fresh()->disposition_id)->toBe($success->id);

    // Qualified: no longer offered.
    $this->getJson(route('campaigns.attempts.active', ['current_team' => $this->slug, 'number' => '22376000001']))
        ->assertJsonPath('attempt', null);
});

it('does not hand another agent\'s campaign call to the dialer', function () {
    workspaceAttempt($this);
    $other = teamAgent($this->team, '7009');

    $this->actingAs($other)
        ->getJson(route('campaigns.attempts.active', ['current_team' => $this->slug, 'number' => '22376000001']))
        ->assertJsonPath('attempt', null);
});
