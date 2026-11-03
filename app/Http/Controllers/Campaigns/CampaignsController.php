<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\ContactStatus;
use App\Enums\DialMode;
use App\Enums\DispositionOutcome;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\CampaignDisposition;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskQueueMembers;
use App\Services\CampaignManager;
use App\Services\CampaignStats;
use App\Services\PlanLimits;
use App\Services\TeamAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Campaign administration. Admins and owners build campaigns; supervisors
 * follow them and start/pause them; agents work them from the workspace.
 */
class CampaignsController extends Controller
{
    use AuthorizesCampaigns;

    public function __construct(private CampaignManager $manager, private PlanLimits $limits) {}

    public function index(Request $request, Team $currentTeam): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $this->canOperate($user, $currentTeam)) {
            return to_route('campaigns.workspace', ['current_team' => $currentTeam->slug]);
        }

        $campaigns = $currentTeam->campaigns()
            ->withCount([
                'contacts',
                'contacts as open_contacts_count' => fn ($query) => $query->whereIn('status', array_map(fn (ContactStatus $s) => $s->value, ContactStatus::open())),
                'attempts',
                'attempts as connected_count' => fn ($query) => $query->where('result', 'connected'),
            ])
            ->latest()
            ->get();

        return Inertia::render('campaigns/Index', [
            'enabled' => $currentTeam->plan()->allows('campaigns'),
            'planName' => $currentTeam->plan()->name,
            'canManage' => $this->canManage($user, $currentTeam),
            'campaigns' => $campaigns->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'kind' => $campaign->kind,
                'status' => $campaign->status->value,
                'statusLabel' => $campaign->status->label(),
                'mode' => $campaign->dial_mode->value,
                'modeLabel' => $campaign->dial_mode->label(),
                'contacts' => (int) $campaign->contacts_count,
                'open' => (int) $campaign->open_contacts_count,
                'attempts' => (int) $campaign->attempts_count,
                'connected' => (int) $campaign->connected_count,
                'withinHours' => $campaign->isWithinCallingHours(),
                'createdAt' => $campaign->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function create(Request $request, Team $currentTeam, TeamAgents $agents): Response
    {
        $this->authorizeManage($request->user(), $currentTeam);

        return Inertia::render('campaigns/Form', [
            'campaign' => null,
            ...$this->formOptions($currentTeam, $agents),
        ]);
    }

    public function store(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->authorizeManage($request->user(), $currentTeam);
        $this->limits->ensureFeature($currentTeam, 'campaigns', 'name');

        [$data, $agentIds, $dispositions] = $this->validated($request, $currentTeam);

        $campaign = $this->manager->create($currentTeam, $request->user(), $data, $agentIds, $dispositions);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign created. Import your contacts to start.')]);

        return to_route('campaigns.show', ['current_team' => $currentTeam->slug, 'campaign' => $campaign->id]);
    }

    public function show(Request $request, Team $currentTeam, Campaign $campaign, CampaignStats $stats, AsteriskQueueMembers $presence): Response
    {
        $this->ensureTeamCampaign($currentTeam, $campaign);
        abort_unless($this->canOperate($request->user(), $currentTeam), 403);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ContactStatus::class)],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        $contacts = $campaign->contacts()
            ->with('disposition:id,label')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($query) => $query->where('phone', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('company', 'like', $like));
            })
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CampaignContact $contact) => [
                ...$contact->toCard(),
                'disposition' => $contact->disposition?->label,
            ]);

        $agents = $campaign->queue?->members()->orderBy('name')->get(['users.id', 'users.name']) ?? collect();
        $statuses = $agents->isEmpty() ? [] : rescue(fn () => $presence->statusesFor($agents, $currentTeam), [], false);

        return Inertia::render('campaigns/Show', [
            'campaign' => $this->campaignData($campaign),
            'stats' => $stats->summary($campaign),
            'agentStats' => $stats->agents($campaign),
            'agents' => $agents->map(fn (User $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'presence' => $statuses[$agent->id] ?? AsteriskQueueMembers::OFFLINE,
            ])->values()->all(),
            'contacts' => $contacts,
            'filters' => $filters,
            'contactStatuses' => collect(ContactStatus::cases())->map(fn (ContactStatus $s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            'canManage' => $this->canManage($request->user(), $currentTeam),
            'enabled' => $currentTeam->plan()->allows('campaigns'),
            'survey' => [
                'questions' => $campaign->surveyQuestions()->count(),
                'responses' => $campaign->surveyResponses()->count(),
                'completed' => $campaign->surveyResponses()->whereNotNull('completed_at')->count(),
            ],
        ]);
    }

    public function edit(Request $request, Team $currentTeam, Campaign $campaign, TeamAgents $agents): Response
    {
        $this->ensureTeamCampaign($currentTeam, $campaign);
        $this->authorizeManage($request->user(), $currentTeam);

        return Inertia::render('campaigns/Form', [
            'campaign' => [
                ...$this->campaignData($campaign),
                'agentIds' => $campaign->queue?->members()->pluck('users.id')->all() ?? [],
            ],
            ...$this->formOptions($currentTeam, $agents),
        ]);
    }

    public function update(Request $request, Team $currentTeam, Campaign $campaign): RedirectResponse
    {
        $this->ensureTeamCampaign($currentTeam, $campaign);
        $this->authorizeManage($request->user(), $currentTeam);
        if (! $campaign->isCallbackList()) {
            $this->limits->ensureFeature($currentTeam, 'campaigns', 'name');
        }

        [$data, $agentIds, $dispositions] = $this->validated($request, $currentTeam);

        $this->manager->update($campaign, $data, $agentIds, $dispositions);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign saved.')]);

        return to_route('campaigns.show', ['current_team' => $currentTeam->slug, 'campaign' => $campaign->id]);
    }

    public function destroy(Request $request, Team $currentTeam, Campaign $campaign): RedirectResponse
    {
        $this->ensureTeamCampaign($currentTeam, $campaign);
        $this->authorizeManage($request->user(), $currentTeam);

        $this->manager->delete($campaign);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign deleted.')]);

        return to_route('campaigns.index', ['current_team' => $currentTeam->slug]);
    }

    /** start | pause | complete */
    public function transition(Request $request, Team $currentTeam, Campaign $campaign, string $action): RedirectResponse
    {
        $this->ensureTeamCampaign($currentTeam, $campaign);
        abort_unless($this->canOperate($request->user(), $currentTeam), 403);

        if ($action === 'start') {
            if (! $campaign->isCallbackList()) {
                $this->limits->ensureFeature($currentTeam, 'campaigns', 'campaign');
            }

            $this->manager->start($campaign);
        } elseif ($action === 'pause') {
            $campaign->update(['status' => CampaignStatus::Paused]);
        } elseif ($action === 'complete') {
            $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
        } else {
            abort(404);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $campaign->refresh()->status->label()]);

        return back();
    }

    public function recycle(Request $request, Team $currentTeam, Campaign $campaign): RedirectResponse
    {
        $this->ensureTeamCampaign($currentTeam, $campaign);
        $this->authorizeManage($request->user(), $currentTeam);

        $count = $this->manager->recycle($campaign);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count contact back in the list.|:count contacts back in the list.', $count, ['count' => $count])]);

        return back();
    }

    /** Contacts with their latest result and wrap-up code, as CSV (Excel-friendly). */
    public function export(Request $request, Team $currentTeam, Campaign $campaign): StreamedResponse
    {
        $this->ensureTeamCampaign($currentTeam, $campaign);
        abort_unless($this->canOperate($request->user(), $currentTeam), 403);

        $filename = 'campagne-'.$campaign->id.'-'.now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['telephone', 'prenom', 'nom', 'entreprise', 'email', 'statut', 'tentatives', 'dernier_resultat', 'qualification', 'prochain_appel', 'notes'], ';', '"', '');

            $campaign->contacts()->with('disposition:id,label')->orderBy('id')
                ->chunkById(1000, function ($contacts) use ($out) {
                    foreach ($contacts as $contact) {
                        fputcsv($out, [
                            $contact->phone, $contact->first_name, $contact->last_name, $contact->company, $contact->email,
                            $contact->status->label(), $contact->attempts, $contact->last_result?->label(),
                            $contact->disposition?->label, $contact->next_attempt_at?->format('Y-m-d H:i'), $contact->notes,
                        ], ';', '"', '');
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function campaignData(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'kind' => $campaign->kind,
            'description' => $campaign->description,
            'status' => $campaign->status->value,
            'statusLabel' => $campaign->status->label(),
            'dialMode' => $campaign->dial_mode->value,
            'dialModeLabel' => $campaign->dial_mode->label(),
            'dialRatio' => $campaign->dial_ratio,
            'maxAbandonRate' => $campaign->max_abandon_rate,
            'maxLines' => $campaign->max_lines,
            'callerId' => $campaign->caller_id,
            'dialPrefix' => $campaign->dial_prefix,
            'ringTimeout' => $campaign->ring_timeout,
            'queueTimeout' => $campaign->queue_timeout,
            'wrapupSeconds' => $campaign->wrapup_seconds,
            'amdEnabled' => $campaign->amd_enabled,
            'voicemailSound' => $campaign->voicemail_sound,
            'abandonSound' => $campaign->abandon_sound,
            'maxAttempts' => $campaign->max_attempts,
            'retryMinutes' => [...Campaign::DEFAULT_RETRY_MINUTES, ...(array) ($campaign->retry_minutes ?? [])],
            'timezone' => $campaign->timezone,
            'schedule' => $campaign->scheduleWindow(),
            'script' => $campaign->script,
            'startedAt' => $campaign->started_at?->toIso8601String(),
            'dispositions' => $campaign->dispositions->map(fn (CampaignDisposition $d) => [
                'id' => $d->id,
                'label' => $d->label,
                'outcome' => $d->outcome->value,
                'retry_minutes' => $d->retry_minutes,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(Team $team, TeamAgents $agents): array
    {
        return [
            'agents' => $agents->for($team)->map(fn (User $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'extension' => $agent->phoneExtensionFor($team)?->extension,
            ])->values()->all(),
            'dialModes' => collect(DialMode::cases())->map(fn (DialMode $m) => ['value' => $m->value, 'label' => $m->label()])->all(),
            'outcomes' => collect(DispositionOutcome::cases())->map(fn (DispositionOutcome $o) => ['value' => $o->value, 'label' => $o->label()])->all(),
            'defaultDispositions' => CampaignDisposition::DEFAULTS,
            'defaultRetryMinutes' => Campaign::DEFAULT_RETRY_MINUTES,
            'defaultSchedule' => Campaign::DEFAULT_SCHEDULE,
            'defaultDialPrefix' => config('asterisk.campaigns.dial_prefix'),
            // Shown when "Numéro présenté" is left empty (the team's main number).
            'defaultCallerId' => $team->mainNumber(),
            'teamNumbers' => $team->inboundNumbers()->orderBy('number')->pluck('number')->all(),
            'timezones' => ['Africa/Bamako', 'Africa/Abidjan', 'Africa/Dakar', 'Africa/Ouagadougou', 'Africa/Niamey', 'Africa/Conakry', 'Africa/Lagos', 'Europe/Paris', 'UTC'],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>, 2: list<array<string, mixed>>}
     */
    private function validated(Request $request, Team $team): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'dial_mode' => ['required', Rule::enum(DialMode::class)],
            'dial_ratio' => ['required', 'numeric', 'min:1', 'max:5'],
            'max_abandon_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'max_lines' => ['nullable', 'integer', 'min:1', 'max:500'],
            'caller_id' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ]{3,20}$/'],
            'dial_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[0-9+*#]*$/'],
            'ring_timeout' => ['required', 'integer', 'min:10', 'max:90'],
            'queue_timeout' => ['required', 'integer', 'min:2', 'max:120'],
            'wrapup_seconds' => ['required', 'integer', 'min:0', 'max:600'],
            'amd_enabled' => ['boolean'],
            'voicemail_sound' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/.-]+$/'],
            'abandon_sound' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/.-]+$/'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'retry_minutes' => ['required', 'array'],
            'retry_minutes.*' => ['integer', 'min:0', 'max:43200'],
            'timezone' => ['required', 'timezone'],
            'schedule.days' => ['required', 'array', 'min:1'],
            'schedule.days.*' => ['integer', 'between:1,7'],
            'schedule.start' => ['required', 'date_format:H:i'],
            'schedule.end' => ['required', 'date_format:H:i', 'after:schedule.start'],
            'script' => ['nullable', 'string', 'max:20000'],
            'agent_ids' => ['array'],
            'agent_ids.*' => ['integer', Rule::exists('team_members', 'user_id')->where('team_id', $team->id)],
            'dispositions' => ['required', 'array', 'min:1', 'max:30'],
            'dispositions.*.id' => ['nullable', 'integer'],
            'dispositions.*.label' => ['required', 'string', 'max:80'],
            'dispositions.*.outcome' => ['required', Rule::enum(DispositionOutcome::class)],
            'dispositions.*.retry_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
        ]);

        $fields = collect($data)->except(['agent_ids', 'dispositions', 'retry_minutes', 'schedule'])->all();
        $fields['retry_minutes'] = array_intersect_key(array_map('intval', $data['retry_minutes']), Campaign::DEFAULT_RETRY_MINUTES);
        $fields['schedule'] = [
            'days' => array_values(array_unique(array_map('intval', $data['schedule']['days']))),
            'start' => $data['schedule']['start'],
            'end' => $data['schedule']['end'],
        ];
        $fields['amd_enabled'] = (bool) ($data['amd_enabled'] ?? false);
        $fields['caller_id'] = isset($data['caller_id']) ? str_replace(' ', '', $data['caller_id']) : null;

        return [$fields, array_map('intval', $data['agent_ids'] ?? []), $data['dispositions']];
    }

    private function authorizeManage(User $user, Team $team): void
    {
        abort_unless($this->canManage($user, $team), 403);
    }

    private function canManage(User $user, Team $team): bool
    {
        return (bool) $user->teamRole($team)?->isAtLeast(TeamRole::Admin);
    }
}
