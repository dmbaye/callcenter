<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignAttempt;
use App\Models\CampaignContact;
use App\Models\SurveyQuestion;
use App\Models\Team;
use App\Services\CampaignSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Campaign questionnaire: the builder and the results (supervisors and
 * up), and the answers recorded by the agent during the call.
 */
class CampaignSurveyController extends Controller
{
    use AuthorizesCampaigns;

    public function __construct(private CampaignSurvey $survey) {}

    public function edit(Request $request, Team $currentTeam, Campaign $campaign): Response
    {
        $this->authorizeSupervisor($request, $currentTeam, $campaign);

        return Inertia::render('campaigns/Survey', [
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name, 'status' => $campaign->status->value],
            'questions' => $campaign->surveyQuestions()->get()->map(fn (SurveyQuestion $q) => $q->toBuilder())->values()->all(),
            'responses' => $campaign->surveyResponses()->count(),
            'types' => collect(SurveyQuestion::TYPES)->map(fn (string $type) => ['value' => $type, 'label' => $this->typeLabel($type)])->all(),
        ]);
    }

    public function update(Request $request, Team $currentTeam, Campaign $campaign): RedirectResponse
    {
        $this->authorizeSupervisor($request, $currentTeam, $campaign);

        $data = $request->validate(['questions' => ['present', 'array']]);
        $questions = $this->survey->saveQuestions($campaign, $data['questions']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $questions->isEmpty()
            ? __('Questionnaire removed.')
            : trans_choice(':count question saved.|:count questions saved.', $questions->count(), ['count' => $questions->count()])]);

        return back();
    }

    public function results(Request $request, Team $currentTeam, Campaign $campaign): Response
    {
        $this->authorizeSupervisor($request, $currentTeam, $campaign);

        return Inertia::render('campaigns/SurveyResults', [
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name],
            'contacts' => $campaign->contacts()->count(),
            ...$this->survey->results($campaign),
        ]);
    }

    /** One line per response, one column per question (Excel-friendly). */
    public function export(Request $request, Team $currentTeam, Campaign $campaign): StreamedResponse
    {
        $this->authorizeSupervisor($request, $currentTeam, $campaign);

        $filename = 'questionnaire-'.$campaign->id.'-'.now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($this->survey->exportRows($campaign) as $row) {
                fputcsv($out, $row, ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The agent records the answers of the contact (draft or complete). */
    public function answer(Request $request, Team $currentTeam, Campaign $campaign, CampaignContact $contact): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $this->ensureTeamCampaign($currentTeam, $campaign);
        abort_unless($contact->campaign_id === $campaign->id, 404);
        abort_unless(
            $this->canOperate($user, $currentTeam) || $campaign->queue?->members()->where('users.id', $user->id)->exists(),
            403,
        );
        abort_if($campaign->status === CampaignStatus::Completed && ! $this->canOperate($user, $currentTeam), 403);

        $data = $request->validate([
            'answers' => ['present', 'array'],
            'complete' => ['boolean'],
            'attempt_id' => ['nullable', 'integer'],
        ]);

        $attempt = isset($data['attempt_id'])
            ? CampaignAttempt::query()->where('campaign_id', $campaign->id)->where('campaign_contact_id', $contact->id)->find($data['attempt_id'])
            : null;

        $response = $this->survey->answer($campaign, $contact, $attempt, $user, $data['answers'], (bool) ($data['complete'] ?? false));

        if ($request->wantsJson()) {
            return response()->json(['completed' => $response->completed_at !== null]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $response->completed_at
            ? __('Questionnaire completed.')
            : __('Answers saved.')]);

        return back();
    }

    private function authorizeSupervisor(Request $request, Team $team, Campaign $campaign): void
    {
        $this->ensureTeamCampaign($team, $campaign);
        abort_unless($this->canOperate($request->user(), $team), 403);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'single' => __('Choix unique'),
            'multiple' => __('Choix multiples'),
            'yes_no' => __('Oui / Non'),
            'rating' => __('Note (échelle)'),
            'nps' => __('Recommandation (NPS 0–10)'),
            'number' => __('Nombre'),
            'text' => __('Réponse courte'),
            'long_text' => __('Réponse longue'),
            'date' => __('Date'),
            default => $type,
        };
    }
}
