<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Data\Plan;
use App\Enums\RouteDestination;
use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\Campaign;
use App\Models\QueueClosure;
use App\Models\QueueHour;
use App\Models\User;
use App\Services\AsteriskDialplan;
use App\Services\AsteriskQueueMembers;
use App\Services\AsteriskQueues;
use App\Services\NumberPool;
use App\Services\PlanLimits;
use App\Services\TeamAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Everything about a queue, on one page: Asterisk settings, agents,
 * wait limit / overflow / callback, announcements and survey. Opening
 * hours and closure days are in QueueScheduleController.
 */
class QueuesController extends Controller
{
    use AuthorizesAsteriskSettings, ValidatesDestinations;

    /** Asterisk queue strategies offered in the form. */
    public const STRATEGIES = [
        'ringall',
        'roundrobin',
        'leastrecent',
        'fewestcalls',
        'random',
        'rrmemory',
        'linear',
        'wrandom',
    ];

    /** Routing options stored on call_queues (they shape the generated dialplan). */
    private const ROUTING_FIELDS = [
        'max_wait', 'overflow_type', 'overflow_target', 'callback_offer', 'survey_enabled', 'emergency_closed', 'emergency_sound', 'closed_sound',
        'record_calls', 'recording_notice',
    ];

    private const SOUND = ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/.-]+$/'];

    public function index(Request $request, AsteriskQueues $queues, TeamAgents $agents): Response
    {
        $team = $this->authorizedTeam($request);
        $asterisk = collect(rescue(fn () => $queues->all($team), [], false))->keyBy('id');

        $campaignQueueIds = Campaign::query()->where('team_id', $team->id)
            ->where('kind', Campaign::KIND_OUTBOUND)
            ->whereNotNull('call_queue_id')
            ->pluck('call_queue_id')
            ->all();

        return Inertia::render('settings/asterisk/Queues', [
            'queues' => $team->callQueues()
                ->with(['hours', 'closures', 'members:users.id'])
                ->orderBy('name')
                ->get()
                ->map(fn (CallQueue $queue) => [
                    ...array_intersect_key((array) ($asterisk[$queue->id] ?? []), array_flip(AsteriskQueues::EDITABLE)),
                    'id' => $queue->id,
                    'name' => $queue->name,
                    'asterisk_name' => $queue->asterisk_name,
                    'campaign' => in_array($queue->id, $campaignQueueIds, true),
                    'max_wait' => $queue->max_wait,
                    'overflow_type' => $queue->overflow_type,
                    'overflow_target' => $queue->overflow_target,
                    'callback_offer' => $queue->callback_offer,
                    'survey_enabled' => $queue->survey_enabled,
                    'emergency_closed' => $queue->emergency_closed,
                    'emergency_sound' => $queue->emergency_sound,
                    'closed_sound' => $queue->closed_sound,
                    'record_calls' => $queue->record_calls,
                    'recording_notice' => $queue->recording_notice,
                    'agents' => $queue->members->mapWithKeys(fn (User $user) => [$user->id => (int) $user->pivot->penalty]),
                    'hours' => $queue->hours->sortBy('weekday')->map(fn (QueueHour $hour) => [
                        'weekday' => $hour->weekday,
                        'opens_at' => substr((string) $hour->opens_at, 0, 5),
                        'closes_at' => substr((string) $hour->closes_at, 0, 5),
                    ])->values(),
                    'closures' => $queue->closures
                        ->filter(fn (QueueClosure $closure) => $closure->date->gte(now()->startOfDay()))
                        ->map(fn (QueueClosure $closure) => ['id' => $closure->id, 'date' => $closure->date->toDateString(), 'label' => $closure->label])
                        ->values(),
                ])->values(),
            'agents' => $agents->members($team)->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'extension' => $user->phoneExtensionFor($team)?->extension,
            ])->values(),
            'ivrMenus' => $team->ivrMenus()->orderBy('name')->get(['id', 'name']),
            'strategies' => self::STRATEGIES,
            'destinations' => RouteDestination::options(),
            'sounds' => config('asterisk.inbound.sounds'),
            'closedSound' => config('asterisk.dialplan.closed_sound'),
            'serviceLevel' => [
                'enabled' => $team->service_level_enabled,
                'target' => $team->service_level_target,
            ],
            // The team's own hold music, once it uploaded some (Musique d'attente).
            'holdMusicClass' => $team->holdMusicTracks()->exists() ? 'moh_'.$team->asterisk_key : null,
            // Call recording is a Pro / Entreprise feature.
            'recording' => [
                'allowed' => $team->plan()->allows('call_recording'),
                'days' => (int) ($team->plan()->limits['recording_days'] ?? 0),
            ],
        ]);
    }

    public function store(Request $request, AsteriskQueues $queues, PlanLimits $limits, NumberPool $numbers): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $data = $this->validated($request);

        $limits->ensureCanAddQueue($team);

        if ($team->callQueues()->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages([
                'name' => __('A queue with this name already exists.'),
            ]);
        }

        $queue = $queues->create($team, $data);

        // First queue: the team gets its number from the pool, ringing this queue.
        try {
            $numbers->ensureFor($team);
            $numbers->routeUnrouted($team, $queue);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return back();
    }

    /**
     * One save for every queue setting. Only the fields sent are changed,
     * so each tab of the page can save its own part.
     */
    public function update(Request $request, AsteriskQueues $queues, AsteriskDialplan $dialplan): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $data = $this->validated($request);

        abort_unless(isset($data['id']), 404);

        if (($data['record_calls'] ?? false) && ! $team->plan()->allows('call_recording')) {
            throw ValidationException::withMessages([
                'record_calls' => __('Call recording is included in the :plans plans.', ['plans' => Plan::namesWith('call_recording')]),
            ]);
        }

        $queue = $team->callQueues()->findOrFail((int) $data['id']);
        $queue->setRelation('team', $team);

        try {
            $queues->update($team, $queue->id, $data);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        // update() skips empty values; write the sent ones as given so a setting can be cleared.
        $queues->setColumns($queue, array_intersect_key($data, array_flip(AsteriskQueues::EDITABLE)));

        $routing = array_intersect_key($data, array_flip(self::ROUTING_FIELDS));

        if ($routing !== []) {
            if (array_key_exists('overflow_type', $routing)) {
                $routing['overflow_target'] = $this->destinationTarget($team, $routing['overflow_type'], $routing['overflow_target'] ?? null, 'overflow_target');

                if ($routing['overflow_type'] === RouteDestination::Queue->value && (int) $routing['overflow_target'] === $queue->id) {
                    throw ValidationException::withMessages(['overflow_target' => __('A queue cannot overflow to itself.')]);
                }
            }

            $queue->update($routing);
        }

        $dialplan->syncTeamRouting($team);

        return back();
    }

    /** Who answers the queue, and at which level (0 = primary, 1 = backup, 2 = last resort). */
    public function agents(Request $request, CallQueue $queue, AsteriskQueueMembers $members): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($queue->team_id === $team->id, 404);

        $data = $request->validate([
            'agents' => ['present', 'array'],
            'agents.*' => ['integer', 'between:0,9'],
        ]);

        $ids = array_map('intval', array_keys($data['agents']));
        $valid = $team->members()->whereIn('users.id', $ids)->pluck('users.id')->all();

        if (count($valid) !== count($ids)) {
            throw ValidationException::withMessages(['agents' => __('Only members of the team can answer its queues.')]);
        }

        $queue->setRelation('team', $team);
        $members->syncQueueAgents($queue, array_map('intval', $data['agents']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Queue agents saved.')]);

        return back();
    }

    public function destroy(Request $request, AsteriskQueues $queues, AsteriskDialplan $dialplan): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $data = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        $campaigns = Campaign::query()->where('team_id', $team->id)->where('call_queue_id', (int) $data['id']);

        if ((clone $campaigns)->where('kind', Campaign::KIND_OUTBOUND)->exists()) {
            throw ValidationException::withMessages(['name' => __('This queue belongs to a campaign: delete the campaign instead.')]);
        }

        // The queue's callback list goes with it.
        (clone $campaigns)->where('kind', Campaign::KIND_CALLBACK)->delete();

        $queues->delete($team, (int) $data['id']);

        // Overflows, IVR options and numbers pointing at it now hang up cleanly.
        $dialplan->syncTeamRouting($team);

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:60'],
            // Asterisk queue settings
            'strategy' => ['nullable', Rule::in(self::STRATEGIES)],
            'servicelevel' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'timeout' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'retry' => ['nullable', 'integer', 'min:0', 'max:99'],
            'wrapuptime' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'maxlen' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:99'],
            'musiconhold' => ['nullable', 'string', 'max:60', 'regex:/^[\w-]+$/', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                // Team classes are moh_{key}: never another team's music.
                $own = 'moh_'.$request->user()?->currentTeam?->asterisk_key;

                if (is_string($value) && str_starts_with($value, 'moh_') && $value !== $own) {
                    $fail(__('This hold music belongs to another organization.'));
                }
            }],
            'joinempty' => ['nullable', Rule::in(['yes', 'no'])],
            'leavewhenempty' => ['nullable', Rule::in(['yes', 'no'])],
            'ringinuse' => ['nullable', Rule::in(['yes', 'no'])],
            // Announcements (Asterisk queue settings too)
            'announce_position' => ['nullable', Rule::in(['yes', 'no', 'more', 'limit'])],
            'announce_holdtime' => ['nullable', Rule::in(['yes', 'no', 'once'])],
            'announce_frequency' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'periodic_announce' => self::SOUND,
            'periodic_announce_frequency' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'announce' => self::SOUND,
            // Routing around the queue
            'max_wait' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'overflow_type' => ['nullable', Rule::enum(RouteDestination::class)],
            'overflow_target' => ['nullable'],
            'callback_offer' => ['boolean'],
            'survey_enabled' => ['boolean'],
            'emergency_closed' => ['boolean'],
            'emergency_sound' => self::SOUND,
            'closed_sound' => self::SOUND,
            'record_calls' => ['boolean'],
            'recording_notice' => ['boolean'],
        ]);

        // Keep only the keys that were actually sent (partial saves per tab).
        return array_intersect_key($data, $request->all());
    }

    /** Turn service level tracking on or off for the team, with its target. */
    public function serviceLevel(Request $request, AsteriskQueues $queues): RedirectResponse
    {
        $team = $this->authorizedTeam($request);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'target' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $team->update([
            'service_level_enabled' => $data['enabled'],
            'service_level_target' => $data['target'],
        ]);

        if ($data['enabled']) {
            rescue(fn () => $queues->ensureServiceLevelThreshold($team), report: true);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service level settings saved.')]);

        return back();
    }
}
