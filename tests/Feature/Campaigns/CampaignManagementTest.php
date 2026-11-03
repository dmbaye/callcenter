<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\TeamRole;
use App\Models\Campaign;
use App\Models\Team;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
    $this->agent = teamAgent($this->team, '7001');
    $this->owner->refresh();
});

function campaignPayload(array $overrides = []): array
{
    return [
        'name' => 'Prospection Bamako',
        'dial_mode' => 'progressive',
        'dial_ratio' => 1.5,
        'max_abandon_rate' => 3,
        'dial_prefix' => '00',
        'caller_id' => '+223 20 00 00 00',
        'ring_timeout' => 25,
        'queue_timeout' => 15,
        'wrapup_seconds' => 30,
        'amd_enabled' => false,
        'max_attempts' => 3,
        'retry_minutes' => ['no_answer' => 60, 'busy' => 15, 'failed' => 30, 'machine' => 240, 'abandoned' => 10, 'cancelled' => 0],
        'timezone' => 'Africa/Bamako',
        'schedule' => ['days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00'],
        'script' => 'Bonjour {first_name}',
        'dispositions' => [
            ['label' => 'Vente', 'outcome' => 'success'],
            ['label' => 'Pas intéressé', 'outcome' => 'final'],
        ],
        ...$overrides,
    ];
}

it('is not available on the Essentiel plan', function () {
    $this->team->subscription->update(['plan' => 'essentiel']);

    $this->actingAs($this->owner)
        ->get(route('campaigns.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->component('campaigns/Index')->where('enabled', false));

    $this->actingAs($this->owner)
        ->post(route('campaigns.store', ['current_team' => $this->team->slug]), campaignPayload(['agent_ids' => [$this->agent->id]]))
        ->assertSessionHasErrors('name');

    expect(Campaign::count())->toBe(0);
});

it('creates a campaign with its own queue, agents and wrap-up codes', function () {
    $this->actingAs($this->owner)
        ->post(route('campaigns.store', ['current_team' => $this->team->slug]), campaignPayload(['agent_ids' => [$this->agent->id]]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $campaign = Campaign::sole();

    expect($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->caller_id)->toBe('+22320000000')
        ->and($campaign->dispositions()->count())->toBe(2)
        ->and($campaign->queue->members()->pluck('users.id')->all())->toBe([$this->agent->id])
        ->and(DB::table('queues')->where('name', $campaign->queue->asterisk_name)->exists())->toBeTrue()
        // The campaign queue does not use one of the plan's inbound queues.
        ->and(app(PlanLimits::class)->usage($this->team)['queues'])->toBe(0);
});

it('keeps campaign administration for admins', function () {
    $this->actingAs($this->agent)
        ->get(route('campaigns.create', ['current_team' => $this->team->slug]))
        ->assertForbidden();

    $this->actingAs($this->agent)
        ->get(route('campaigns.index', ['current_team' => $this->team->slug]))
        ->assertRedirect(route('campaigns.workspace', ['current_team' => $this->team->slug]));
});

it('imports a CSV, skipping duplicates, invalid and Do-Not-Call numbers', function () {
    $campaign = teamCampaign($this->team, $this->owner, [$this->agent]);
    $this->team->dncNumbers()->create(['phone' => '22366001122']);

    $csv = "\xEF\xBB\xBFPrénom;Nom;Téléphone;Entreprise;Ville\n"
        ."Awa;Traoré;76 12 34 56;Orange;Bamako\n"
        ."Awa;Traoré;+223 76 12 34 56;Orange;Bamako\n"
        ."Moussa;Keita;66 00 11 22;;Ségou\n"
        ."Bad;Row;12;;\n";

    $this->actingAs($this->owner)
        ->post(route('campaigns.contacts.import', ['current_team' => $this->team->slug, 'campaign' => $campaign->id]), [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
        ])
        ->assertSessionHasNoErrors();

    $contact = $campaign->contacts()->sole();

    expect($contact->phone)->toBe('22376123456')
        ->and($contact->first_name)->toBe('Awa')
        ->and($contact->company)->toBe('Orange')
        ->and($contact->data)->toBe(['ville' => 'Bamako']);
});

it('needs contacts and agents to start', function () {
    $campaign = teamCampaign($this->team, $this->owner, []);
    $url = route('campaigns.transition', ['current_team' => $this->team->slug, 'campaign' => $campaign->id, 'action' => 'start']);

    $this->actingAs($this->owner)->post($url)->assertSessionHasErrors('campaign');

    app(App\Services\CampaignManager::class)->syncAgents($campaign, [$this->agent->id]);
    campaignContacts($campaign, ['22376000001']);

    $this->actingAs($this->owner)->post($url)->assertSessionHasNoErrors();

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Running);
});

it('stops calling numbers added to the Do-Not-Call list', function () {
    $campaign = teamCampaign($this->team, $this->owner, [$this->agent]);
    campaignContacts($campaign, ['22376000001', '22376000002']);

    $this->actingAs($this->owner)
        ->post(route('campaigns.dnc.store', ['current_team' => $this->team->slug]), ['numbers' => "76 00 00 01\nnot a number"])
        ->assertSessionHasNoErrors();

    expect($this->team->dncNumbers()->pluck('phone')->all())->toBe(['22376000001'])
        ->and($campaign->contacts()->where('phone', '22376000001')->first()->status)->toBe(ContactStatus::Dnc)
        ->and($campaign->contacts()->where('phone', '22376000002')->first()->status)->toBe(ContactStatus::Pending);
});

it('exports contacts as CSV', function () {
    $campaign = teamCampaign($this->team, $this->owner, [$this->agent]);
    campaignContacts($campaign, ['22376000001']);

    $response = $this->actingAs($this->owner)
        ->get(route('campaigns.export', ['current_team' => $this->team->slug, 'campaign' => $campaign->id]))
        ->assertOk();

    expect($response->streamedContent())->toContain('22376000001');
});
