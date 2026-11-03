<?php

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\DialMode;
use App\Enums\TeamRole;
use App\Events\InboundCallOffered;
use App\Models\Campaign;
use App\Models\Team;
use App\Models\User;
use App\Services\InboundCallEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();
    $this->travelTo(now()->setTime(10, 0));

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->queue = teamQueue($this->team, 'Support');
    $this->agent = teamAgent($this->team, '7001', queues: [$this->queue]);
    $this->events = app(InboundCallEvents::class);
    $this->key = $this->team->asterisk_key;
});

function callbackEvent(string $queue, string $key, string $number): array
{
    return ['Event' => 'UserEvent', 'UserEvent' => 'CcCallback', 'Queue' => $queue, 'Number' => $number, 'Account' => $key];
}

it('turns "press 1" into a contact on the queue callback list', function () {
    $this->events->handle(callbackEvent($this->queue->asterisk_name, $this->key, '76123456'));
    $this->events->handle(callbackEvent($this->queue->asterisk_name, $this->key, '+22376123456'));

    $list = Campaign::query()->where('kind', Campaign::KIND_CALLBACK)->sole();
    $contact = $list->contacts()->sole();

    expect($list->call_queue_id)->toBe($this->queue->id)
        ->and($list->status)->toBe(CampaignStatus::Running)
        ->and($list->dial_mode)->toBe(DialMode::Preview)
        ->and($list->dispositions()->count())->toBeGreaterThan(0)
        ->and($contact->phone)->toBe('22376123456')
        ->and($contact->priority)->toBe(50)
        ->and($contact->data['file'])->toBe('Support');

    // A finished request is reopened when the caller asks again.
    $contact->update(['status' => ContactStatus::Completed, 'attempts' => 2]);
    $this->events->handle(callbackEvent($this->queue->asterisk_name, $this->key, '76123456'));

    expect($contact->fresh()->status)->toBe(ContactStatus::Pending)
        ->and($contact->fresh()->attempts)->toBe(0);
});

it('ignores callbacks from another tenant, anonymous callers and Do-Not-Call numbers', function () {
    $this->team->dncNumbers()->create(['phone' => '22366001122']);

    $this->events->handle(callbackEvent($this->queue->asterisk_name, 'otherkey00', '76123456'));
    $this->events->handle(callbackEvent($this->queue->asterisk_name, $this->key, 'anonymous'));
    $this->events->handle(callbackEvent($this->queue->asterisk_name, $this->key, '66001122'));

    expect(DB::table('campaign_contacts')->count())->toBe(0);
});

it('shows callback requests to the queue agents, on every plan', function () {
    $this->team->subscription->update(['plan' => 'essentiel']);
    $this->events->handle(callbackEvent($this->queue->asterisk_name, $this->key, '76123456'));
    $list = Campaign::query()->where('kind', Campaign::KIND_CALLBACK)->sole();

    $this->actingAs($this->agent)
        ->post(route('campaigns.next', ['current_team' => $this->team->slug, 'campaign' => $list->id]))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->agent)
        ->get(route('campaigns.workspace', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('current.contact.phone', '22376123456')
            ->where('campaigns.0.modeLabel', __('Rappels entrants')));
});

it('records post-call survey scores once per call', function () {
    $event = ['Event' => 'UserEvent', 'UserEvent' => 'CcSurvey', 'Queue' => $this->queue->asterisk_name, 'Account' => $this->key,
        'Member' => "PJSIP/{$this->key}_7001", 'Score' => '4', 'Number' => '76123456', 'Uniqueid' => '1700.5'];

    $this->events->handle($event);
    $this->events->handle($event);
    $this->events->handle([...$event, 'Uniqueid' => '1700.6', 'Score' => '9']);

    $survey = $this->team->surveys()->sole();

    expect($survey->score)->toBe(4)
        ->and($survey->user_id)->toBe($this->agent->id)
        ->and($survey->call_queue_id)->toBe($this->queue->id);

    $this->actingAs($this->owner->fresh())
        ->get(route('dashboard', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('csat.average', fn ($average) => (float) $average === 4.0)->where('csat.responses', 1));
});

it('pops the caller context on the ringing agent', function () {
    Event::fake([InboundCallOffered::class]);

    $outbound = teamCampaign($this->team, $this->owner);
    $outbound->contacts()->create(['phone' => '22376123456', 'first_name' => 'Awa', 'last_name' => 'Traoré', 'company' => 'Orange', 'status' => ContactStatus::Completed]);
    DB::table('cdr')->insert([
        ['uniqueid' => 'old1', 'src' => '76123456', 'dst' => 's', 'start' => now()->subDays(3), 'disposition' => 'ANSWERED', 'accountcode' => $this->key],
        ['uniqueid' => 'old2', 'src' => '76123456', 'dst' => 's', 'start' => now()->subDays(1), 'disposition' => 'NO ANSWER', 'accountcode' => $this->key],
    ]);

    $this->events->handle(['Event' => 'UserEvent', 'UserEvent' => 'CcInbound', 'Queue' => $this->queue->asterisk_name, 'Number' => '76123456', 'Choice' => 'Support', 'Uniqueid' => '1700.9']);
    $this->events->handle(['Event' => 'AgentCalled', 'Queue' => $this->queue->asterisk_name, 'Interface' => "PJSIP/{$this->key}_7001", 'CallerIDNum' => '76123456', 'Uniqueid' => '1700.9']);

    Event::assertDispatched(InboundCallOffered::class, fn (InboundCallOffered $event) => $event->userId === $this->agent->id
        && $event->context['queue'] === 'Support'
        && $event->context['choice'] === 'Support'
        && $event->context['contact']['name'] === 'Awa Traoré'
        && $event->context['history']['calls90d'] === 2
        && $event->context['history']['missed90d'] === 1);
});
