<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\DialMode;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignAttempt;
use App\Models\CampaignContact;
use App\Models\CampaignDisposition;
use App\Models\SurveyQuestion;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskAmi;
use App\Services\CampaignDialer;
use App\Services\CampaignOutcomes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The agent desktop for campaigns: next contact (preview), screen pop
 * (progressive/power), script, wrap-up and personal callbacks.
 */
class CampaignWorkspaceController extends Controller
{
    use AuthorizesCampaigns;

    public function __construct(private CampaignDialer $dialer, private CampaignOutcomes $outcomes) {}

    public function show(Request $request, Team $currentTeam): Response
    {
        $user = $request->user();
        $campaigns = $this->campaignsFor($user, $currentTeam);

        $attempt = $this->currentAttempt($user, $currentTeam, $request->integer('attempt') ?: null);
        $reserved = $attempt ? null : CampaignContact::query()
            ->whereIn('campaign_id', $campaigns->pluck('id'))
            ->where('status', ContactStatus::Reserved)
            ->where('reserved_by', $user->id)
            ->with('campaign')
            ->first();

        $contact = $attempt?->contact ?? $reserved;
        $campaign = $attempt?->campaign ?? $reserved?->campaign;
        $members = $this->queueMembers($campaigns);

        return Inertia::render('campaigns/Workspace', [
            'enabled' => $currentTeam->plan()->allows('campaigns'),
            'hasExtension' => $user->phoneExtensionFor($currentTeam) !== null,
            'campaigns' => $campaigns->map(fn (Campaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'mode' => $c->dial_mode->value,
                'modeLabel' => $c->isCallbackList() ? __('Rappels entrants') : $c->dial_mode->label(),
                'withinHours' => $c->isWithinCallingHours(),
                'callable' => $c->dial_mode === DialMode::Preview ? $this->dialer->callable($c)->count() : null,
                // Progressive / power: what keeps calls from coming (empty = they should).
                'blockers' => $c->dial_mode->dialsAutomatically()
                    ? [
                        ...(CampaignDialer::isRunning() ? [] : [__('The automatic dialer (php artisan campaigns:dial) is not running on the server.')]),
                        ...$this->dialer->diagnose($c, $members),
                    ]
                    : [],
            ])->values()->all(),
            'current' => $contact ? [
                'campaign' => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'mode' => $campaign->dial_mode->value,
                    'script' => $this->renderScript($campaign, $contact),
                    'dispositions' => $campaign->dispositions->map(fn (CampaignDisposition $d) => [
                        'id' => $d->id,
                        'label' => $d->label,
                        'outcome' => $d->outcome->value,
                    ])->values()->all(),
                ],
                'contact' => $contact->toCard(),
                'survey' => $this->surveyFor($campaign, $contact),
                'history' => $contact->attemptsLog()->with(['agent:id,name', 'disposition:id,label'])->limit(10)->get()
                    ->map(fn (CampaignAttempt $a) => [
                        'id' => $a->id,
                        'at' => $a->created_at->toIso8601String(),
                        'result' => $a->result?->label(),
                        'disposition' => $a->disposition?->label,
                        'agent' => $a->agent?->name,
                        'notes' => $a->notes,
                    ])->all(),
                'attempt' => $attempt ? [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                    'result' => $attempt->result?->value,
                    'resultLabel' => $attempt->result?->label(),
                    'connectedAt' => $attempt->connected_at?->toIso8601String(),
                    'dispositioned' => $attempt->dispositioned_at !== null,
                ] : null,
            ] : null,
            'callbacks' => CampaignContact::query()
                ->whereIn('campaign_id', $campaigns->pluck('id'))
                ->where('status', ContactStatus::Callback)
                ->where('callback_user_id', $user->id)
                ->with('campaign:id,name')
                ->orderBy('next_attempt_at')
                ->limit(50)
                ->get()
                ->map(fn (CampaignContact $c) => [
                    ...$c->toCard(),
                    'campaignId' => $c->campaign_id,
                    'campaignName' => $c->campaign->name,
                    'due' => $c->next_attempt_at === null || $c->next_attempt_at->isPast(),
                ])->all(),
            'today' => $this->today($user, $currentTeam),
        ]);
    }

    /** Preview: reserve the next contact of the campaign for this agent. */
    public function next(Request $request, Team $currentTeam, Campaign $campaign): RedirectResponse
    {
        $this->ensureAgentOf($request->user(), $currentTeam, $campaign);

        if ($campaign->dial_mode !== DialMode::Preview) {
            throw ValidationException::withMessages(['campaign' => __('Calls of this campaign are placed automatically.')]);
        }

        $this->ensureCanDial($campaign);

        if ($this->currentAttempt($request->user(), $currentTeam) !== null) {
            throw ValidationException::withMessages(['campaign' => __('Qualify your current call first.')]);
        }

        if (CampaignContact::query()->where('reserved_by', $request->user()->id)->where('status', ContactStatus::Reserved)->exists()) {
            return back();
        }

        if ($this->dialer->claim($campaign, 1, ContactStatus::Reserved, $request->user())->isEmpty()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('No contact to call right now in this campaign.')]);
        }

        return back();
    }

    /** Ring the agent, then the customer (preview contact or personal callback). */
    public function call(Request $request, Team $currentTeam, Campaign $campaign, CampaignContact $contact): RedirectResponse
    {
        $user = $request->user();
        $this->ensureAgentOf($user, $currentTeam, $campaign);
        abort_unless($contact->campaign_id === $campaign->id, 404);

        $mine = ($contact->status === ContactStatus::Reserved && $contact->reserved_by === $user->id)
            || ($contact->status === ContactStatus::Callback && $contact->callback_user_id === $user->id);

        abort_unless($mine, 403, __('This contact is not assigned to you.'));

        $this->ensureCanDial($campaign, checkHours: $contact->status !== ContactStatus::Callback);

        if ($currentTeam->dncNumbers()->where('phone', $contact->phone)->exists()) {
            $contact->update(['status' => ContactStatus::Dnc, 'reserved_by' => null]);

            throw ValidationException::withMessages(['contact' => __('This number is on the Do-Not-Call list.')]);
        }

        try {
            $this->dialer->placePreviewCall($campaign, $contact, $user);
        } catch (RuntimeException $exception) {
            report($exception);

            throw ValidationException::withMessages(['contact' => __('The call could not be placed. Check that your softphone is connected.')]);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Answer your softphone: the customer is dialled as soon as you pick up.')]);

        return back();
    }

    /** Preview: not now. The contact goes back to the list for later. */
    public function skip(Request $request, Team $currentTeam, Campaign $campaign, CampaignContact $contact): RedirectResponse
    {
        $this->ensureAgentOf($request->user(), $currentTeam, $campaign);
        abort_unless($contact->campaign_id === $campaign->id && $contact->reserved_by === $request->user()->id, 403);

        if ($contact->status === ContactStatus::Reserved) {
            $contact->update([
                'status' => ContactStatus::Pending,
                'reserved_by' => null,
                'reserved_until' => null,
                'next_attempt_at' => now()->addMinutes(30),
            ]);
        }

        return back();
    }

    /**
     * The campaign call the softphone just had, so the dialer's wrap-up card
     * can show the campaign's codes. Campaign calls reach the agent as an
     * incoming SIP call: they are recognised by the customer number the
     * dialer shows as caller ID, among this agent's recent unqualified
     * attempts.
     */
    public function active(Request $request, Team $currentTeam): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'since' => ['nullable', 'date'],
        ]);

        $digits = preg_replace('/\D/', '', $data['number']);

        if (strlen($digits) < 6) {
            return response()->json(['attempt' => null]);
        }

        $since = isset($data['since']) ? Carbon::parse($data['since'])->subMinutes(2) : now()->subHour();
        $tail = substr($digits, -8);

        $attempt = CampaignAttempt::query()
            ->with(['campaign.dispositions', 'contact'])
            ->where('team_id', $currentTeam->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('dispositioned_at')
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit(10)
            ->get()
            ->first(fn (CampaignAttempt $a) => str_ends_with(preg_replace('/\D/', '', (string) ($a->phone ?: $a->contact?->phone)), $tail));

        if ($attempt === null) {
            return response()->json(['attempt' => null]);
        }

        return response()->json(['attempt' => [
            'id' => $attempt->id,
            'campaign' => $attempt->campaign->name,
            'callbackList' => $attempt->campaign->isCallbackList(),
            'contact' => $attempt->contact?->fullName() ?: $attempt->phone,
            'phone' => $attempt->phone ?: $attempt->contact?->phone,
            'dispositions' => $attempt->campaign->dispositions->map(fn (CampaignDisposition $d) => [
                'id' => $d->id,
                'label' => $d->label,
                'outcome' => $d->outcome->value,
            ])->values()->all(),
        ]]);
    }

    public function dispose(Request $request, Team $currentTeam, CampaignAttempt $attempt): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($attempt->team_id === $currentTeam->id, 404);
        abort_unless($attempt->user_id === $user->id || $this->canOperate($user, $currentTeam), 403);

        $data = $request->validate([
            'disposition_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'callback_at' => ['nullable', 'date'],
        ]);

        $disposition = CampaignDisposition::query()->where('campaign_id', $attempt->campaign_id)->find($data['disposition_id']);

        if ($disposition === null) {
            throw ValidationException::withMessages(['disposition_id' => __('Choose a wrap-up code.')]);
        }

        $callbackAt = isset($data['callback_at'])
            ? Carbon::parse($data['callback_at'], $attempt->campaign->timezone)->utc()
            : null;

        $this->outcomes->dispose($attempt, $disposition, $user, $data['notes'] ?? null, $callbackAt);

        // The dialer's wrap-up card qualifies without leaving the current page.
        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Call qualified.')]);

        return to_route('campaigns.workspace', ['current_team' => $currentTeam->slug]);
    }

    /**
     * The campaign questionnaire with what this contact already answered
     * (null when the campaign has none).
     *
     * @return array<string, mixed>|null
     */
    private function surveyFor(Campaign $campaign, CampaignContact $contact): ?array
    {
        $questions = $campaign->surveyQuestions()->get();

        if ($questions->isEmpty()) {
            return null;
        }

        $response = $campaign->surveyResponses()->where('campaign_contact_id', $contact->id)->first();

        return [
            'questions' => $questions->map(fn (SurveyQuestion $q) => $q->toBuilder())->values()->all(),
            'answers' => (object) ($response?->answers ?? []),
            'completed' => $response?->completed_at !== null,
        ];
    }

    /**
     * Live queue members, only when an automatic campaign needs them (null = AMI unreachable).
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return Collection<int, array<string, string>>|null
     */
    private function queueMembers(Collection $campaigns): ?Collection
    {
        if ($campaigns->doesntContain(fn (Campaign $c) => $c->dial_mode->dialsAutomatically())) {
            return collect();
        }

        try {
            return collect(app(AsteriskAmi::class)->snapshot()['queues']['members'] ?? []);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return Collection<int, Campaign> running campaigns this user is an agent of */
    private function campaignsFor(User $user, Team $team): Collection
    {
        return $team->campaigns()
            ->with('dispositions')
            ->where('status', CampaignStatus::Running)
            ->whereHas('queue.members', fn ($query) => $query->where('users.id', $user->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * The agent's call in progress, or the last connected call still
     * waiting for its wrap-up code.
     */
    private function currentAttempt(User $user, Team $team, ?int $preferred = null): ?CampaignAttempt
    {
        return CampaignAttempt::query()
            ->with(['campaign.dispositions', 'contact'])
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->whereNull('dispositioned_at')
            ->where(fn ($query) => $query
                ->where('status', '!=', CampaignAttempt::ENDED)
                ->orWhere(fn ($query) => $query->whereNotNull('connected_at')))
            ->where('created_at', '>=', now()->subHours(12))
            ->when($preferred, fn ($query, $id) => $query->orderByRaw('case when id = ? then 0 else 1 end', [$id]))
            ->latest('id')
            ->first();
    }

    /** Placeholders like {first_name} or {company}; unknown ones stay visible. */
    private function renderScript(Campaign $campaign, CampaignContact $contact): string
    {
        $values = $contact->placeholders();

        return (string) preg_replace_callback('/\{\s*([A-Za-z0-9_]+)\s*\}/', fn ($m) => array_key_exists(strtolower($m[1]), $values)
            ? (string) $values[strtolower($m[1])]
            : $m[0], (string) $campaign->script);
    }

    /** @return array{calls: int, connected: int, qualified: int} */
    private function today(User $user, Team $team): array
    {
        $row = CampaignAttempt::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('count(*) as calls, sum(case when connected_at is not null then 1 else 0 end) as connected, sum(case when disposition_id is not null then 1 else 0 end) as qualified')
            ->first();

        return [
            'calls' => (int) ($row->calls ?? 0),
            'connected' => (int) ($row->connected ?? 0),
            'qualified' => (int) ($row->qualified ?? 0),
        ];
    }

    private function ensureAgentOf(User $user, Team $team, Campaign $campaign): void
    {
        $this->ensureTeamCampaign($team, $campaign);

        abort_unless($campaign->queue?->members()->where('users.id', $user->id)->exists(), 403, __('You are not an agent of this campaign.'));
    }

    private function ensureCanDial(Campaign $campaign, bool $checkHours = true): void
    {
        if (! $campaign->isRunning()) {
            throw ValidationException::withMessages(['campaign' => __('This campaign is not running.')]);
        }

        // Callback lists belong to inbound queues: every plan has them.
        $planAllows = $campaign->isCallbackList() || $campaign->team->plan()->allows('campaigns');

        if (! $planAllows || ! $campaign->team->hasUsableSubscription()) {
            throw ValidationException::withMessages(['campaign' => __('This feature is not included in your :plan plan.', ['plan' => $campaign->team->plan()->name])]);
        }

        if ($checkHours && ! $campaign->isWithinCallingHours()) {
            throw ValidationException::withMessages(['campaign' => __('Outside the calling hours of this campaign.')]);
        }
    }
}
