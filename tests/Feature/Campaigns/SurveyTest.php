<?php

use App\Enums\TeamRole;
use App\Models\SurveyQuestion;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);
    $this->agent = teamAgent($this->team, '7001');
    $this->campaign = teamCampaign($this->team, $this->owner, [$this->agent]);
    campaignContacts($this->campaign, ['22376000001', '22376000002']);
    $this->contact = $this->campaign->contacts()->orderBy('id')->first();
    $this->base = "/{$this->team->slug}/campaigns/{$this->campaign->id}";
});

/** Satisfied? (yes/no) → if no: why? (text) ; rating 1-5 ; NPS */
function saveSurvey($test): array
{
    $test->actingAs($test->owner)->put("{$test->base}/survey", ['questions' => [
        ['key' => 'k1', 'type' => 'yes_no', 'label' => 'Satisfait ?', 'required' => true],
        ['key' => 'k2', 'type' => 'long_text', 'label' => 'Pourquoi ?', 'required' => true, 'condition' => ['key' => 'k1', 'values' => ['no']]],
        ['key' => 'k3', 'type' => 'rating', 'label' => 'Note', 'settings' => ['min' => 1, 'max' => 5]],
        ['key' => 'k4', 'type' => 'single', 'label' => 'Canal', 'options' => [['label' => 'Agence'], ['label' => 'Téléphone'], ['label' => '']]],
        ['key' => 'k5', 'type' => 'nps', 'label' => 'Recommandation'],
    ]])->assertSessionHasNoErrors();

    return SurveyQuestion::query()->where('campaign_id', $test->campaign->id)->orderBy('position')->get()->all();
}

it('saves the questions with conditions and choice identifiers', function () {
    [$satisfied, $why, , $channel] = saveSurvey($this);

    expect($why->condition)->toBe(['question_id' => $satisfied->id, 'values' => ['no']])
        ->and($channel->options)->toHaveCount(2)
        ->and($channel->options[0]['value'])->toMatch('/^[a-z0-9]{6}$/');

    // Saving again with the ids keeps the questions and their choice values.
    $this->actingAs($this->owner)->put("{$this->base}/survey", ['questions' => [
        ['id' => $channel->id, 'key' => 'q'.$channel->id, 'type' => 'single', 'label' => 'Canal préféré', 'options' => $channel->options],
    ]])->assertSessionHasNoErrors();

    expect(SurveyQuestion::query()->where('campaign_id', $this->campaign->id)->pluck('id')->all())->toBe([$channel->id])
        ->and($channel->fresh()->options)->toBe($channel->options);
});

it('rejects a condition on a later or free-text question', function () {
    $this->actingAs($this->owner)->put("{$this->base}/survey", ['questions' => [
        ['key' => 'a', 'type' => 'text', 'label' => 'Nom'],
        ['key' => 'b', 'type' => 'text', 'label' => 'Ville', 'condition' => ['key' => 'a', 'values' => ['x']]],
        ['key' => 'c', 'type' => 'single', 'label' => 'Un seul choix', 'options' => [['label' => 'A']]],
    ]])->assertSessionHasErrors(['questions.1.condition', 'questions.2.options']);
});

it('checks required questions only when completing, and skips hidden ones', function () {
    [$satisfied, $why, $rating] = saveSurvey($this);
    $url = "{$this->base}/contacts/{$this->contact->id}/survey";

    // Draft: nothing required.
    $this->actingAs($this->agent)->post($url, ['answers' => [$rating->id => 4], 'complete' => false])->assertSessionHasNoErrors();

    // Completing: "Satisfait ?" is required; "Pourquoi ?" too once the answer is no.
    $this->actingAs($this->agent)->post($url, ['answers' => [$satisfied->id => 'no'], 'complete' => true])
        ->assertSessionHasErrors("answers.{$why->id}");

    // Answered yes: "Pourquoi ?" is hidden, its stray answer dropped.
    $this->actingAs($this->agent)->post($url, ['answers' => [$satisfied->id => 'yes', $why->id => 'ignored', $rating->id => 6], 'complete' => true])
        ->assertSessionHasErrors("answers.{$rating->id}");

    $this->actingAs($this->agent)->post($url, ['answers' => [$satisfied->id => 'yes', $why->id => 'ignored', $rating->id => 5], 'complete' => true])
        ->assertSessionHasNoErrors();

    $response = $this->campaign->surveyResponses()->sole();

    expect($response->answers)->toBe([(string) $satisfied->id => 'yes', (string) $rating->id => 5])
        ->and($response->completed_at)->not->toBeNull()
        ->and($response->user_id)->toBe($this->agent->id);
});

it('aggregates the results per question', function () {
    [$satisfied, , $rating, $channel, $nps] = saveSurvey($this);
    [$first, $second] = $this->campaign->contacts()->orderBy('id')->get()->all();
    $agency = $channel->options[0]['value'];

    foreach ([[$first, 'yes', 5, 10], [$second, 'yes', 3, 4]] as [$contact, $yes, $note, $score]) {
        $this->actingAs($this->agent)->post("{$this->base}/contacts/{$contact->id}/survey", [
            'answers' => [$satisfied->id => $yes, $rating->id => $note, $channel->id => $agency, $nps->id => $score],
            'complete' => true,
        ])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->owner)->get("{$this->base}/survey/results")->assertInertia(fn (Assert $page) => $page
        ->component('campaigns/SurveyResults')
        ->where('responses', 2)
        ->where('completed', 2)
        ->where('questions.2.average', fn ($average) => (float) $average === 4.0)
        ->where('questions.3.breakdown.0.count', 2)
        ->where('questions.3.breakdown.0.percent', fn ($percent) => (float) $percent === 100.0)
        // One promoter (10), one detractor (4): NPS 0.
        ->where('questions.4.nps', 0));
});

it('exports one line per response', function () {
    [$satisfied] = saveSurvey($this);

    $this->actingAs($this->agent)->post("{$this->base}/contacts/{$this->contact->id}/survey", [
        'answers' => [$satisfied->id => 'yes'], 'complete' => true,
    ]);

    $csv = $this->actingAs($this->owner)->get("{$this->base}/survey/export")->assertOk()->streamedContent();

    expect($csv)->toContain('Satisfait ?')->toContain('22376000001')->toContain(__('Oui'));
});

it('keeps the builder to supervisors and the answers to campaign agents', function () {
    $outsider = teamAgent($this->team, '7009');

    $this->actingAs($this->agent)->get("{$this->base}/survey")->assertForbidden();
    $this->actingAs($outsider)->post("{$this->base}/contacts/{$this->contact->id}/survey", ['answers' => []])->assertForbidden();
});

it('shows the questionnaire with the contact on the agent desktop', function () {
    [$satisfied] = saveSurvey($this);
    $this->campaign->update(['dial_mode' => App\Enums\DialMode::Preview, 'status' => App\Enums\CampaignStatus::Running]);

    $this->actingAs($this->agent)->post(route('campaigns.next', ['current_team' => $this->team->slug, 'campaign' => $this->campaign->id]));

    $this->actingAs($this->agent)
        ->get(route('campaigns.workspace', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('current.survey.questions', 5)
            ->where('current.survey.questions.0.id', $satisfied->id)
            ->where('current.survey.completed', false));
});
