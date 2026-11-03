<?php

namespace App\Http\Controllers;

use App\Enums\AgentState;
use App\Enums\TeamRole;
use App\Events\MeetingNotesUpdated;
use App\Events\MeetingsChanged;
use App\Models\AgentStatus;
use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Meetings\InstantMeetingInvitation;
use App\Notifications\Meetings\MeetingCancelled;
use App\Notifications\Meetings\MeetingScheduled;
use App\Notifications\Meetings\MeetingStarted;
use App\Notifications\Meetings\MeetingUpdated;
use App\Services\AgentStates;
use App\Services\LiveKit;
use App\Services\MeetingCalendar;
use App\Services\MeetingRooms;
use App\Services\PlanLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Team video meetings on the self-hosted LiveKit SFU.
 *
 * Two kinds: instant (started now, invitees get a live notification with
 * a "Rejoindre" button) and planned (a date and a duration; invitees get
 * an e-mail with a calendar invitation, a notification, a reminder before
 * the start and a "started" notice when someone opens the room).
 *
 * Laravel owns who may join what: a member of the team gets a short-lived
 * join token for one room of that team. The participant cap of the plan is
 * set on the LiveKit room, so LiveKit enforces it.
 *
 * An agent who joins a meeting goes Non prêt – Réunion vidéo once the
 * browser is connected to the room (joined(), plus the LiveKit webhook as
 * a backstop), so queue calls stop ringing while they are on camera.
 */
class MeetingsController extends Controller
{
    public function __construct(
        private LiveKit $liveKit,
        private PlanLimits $limits,
        private AgentStates $states,
        private MeetingCalendar $calendar,
        private MeetingRooms $rooms,
    ) {}

    public function index(Request $request, Team $currentTeam): Response
    {
        $user = $request->user();
        $canSeeAll = $this->isManager($user, $currentTeam);
        $scope = $canSeeAll && $request->query('scope') === 'all' ? 'all' : 'mine';

        $base = fn () => $currentTeam->meetings()
            ->with(['creator:id,name', 'invitees:users.id,users.name', 'notesEditor:id,name'])
            ->when($scope === 'mine', fn (Builder $query) => $query->where(fn (Builder $mine) => $mine
                ->where('created_by', $user->id)
                ->orWhereHas('invitees', fn (Builder $invitee) => $invitee->where('users.id', $user->id))));

        $live = $base()->whereNotNull('started_at')->whereNull('ended_at')->whereNull('cancelled_at')
            ->latest('started_at')->limit(20)->get();

        $upcoming = $base()->whereNotNull('scheduled_at')->whereNull('started_at')->whereNull('ended_at')->whereNull('cancelled_at')
            // A planned meeting nobody opened stays listed until its planned end (+1 h grace).
            ->where('scheduled_at', '>=', now()->subHours(4))
            ->orderBy('scheduled_at')->limit(50)->get()
            ->filter(fn (Meeting $meeting) => $meeting->plannedEnd()->addHour()->isFuture())
            ->values();

        $past = $base()->where(fn (Builder $query) => $query->whereNotNull('ended_at')->orWhereNotNull('cancelled_at'))
            ->where(fn (Builder $query) => $query->where('ended_at', '>=', now()->subDays(30))->orWhere('cancelled_at', '>=', now()->subDays(30)))
            ->latest('updated_at')->limit(30)->get();

        return Inertia::render('meetings/Index', [
            'videoEnabled' => $currentTeam->plan()->allows('video'),
            'configured' => $this->liveKit->isConfigured(),
            'maxParticipants' => $this->limits->limits($currentTeam)['meeting_participants'],
            'planName' => $currentTeam->plan()->name,
            'canManageBilling' => (bool) $user->teamRole($currentTeam)?->isAtLeast(TeamRole::Admin),
            'scope' => $scope,
            'canSeeAll' => $canSeeAll,
            'durations' => $this->durations($currentTeam),
            'meetingMinutes' => $this->limits->limits($currentTeam)['meeting_minutes'],
            'joinEarlyMinutes' => (int) config('meetings.join_early_minutes', 10),
            'live' => $live->map(fn (Meeting $meeting) => $this->row($meeting, $user, $currentTeam))->all(),
            'upcoming' => $upcoming->map(fn (Meeting $meeting) => $this->row($meeting, $user, $currentTeam))->all(),
            'past' => $past->map(fn (Meeting $meeting) => $this->row($meeting, $user, $currentTeam))->all(),
            'members' => $this->members($currentTeam, $user),
        ]);
    }

    /** Start an instant meeting, or plan one for later. */
    public function store(Request $request, Team $currentTeam): RedirectResponse
    {
        $this->limits->ensureFeature($currentTeam, 'video', 'title');

        $data = $this->validated($request, $currentTeam);
        $user = $request->user();
        $planned = ($data['mode'] ?? 'instant') === 'scheduled';

        if (! $planned) {
            $this->ensureConfigured('title');
        }

        $uuid = (string) Str::uuid();
        $max = (int) $this->limits->limits($currentTeam)['meeting_participants'];

        $meeting = $currentTeam->meetings()->create([
            'uuid' => $uuid,
            'title' => $data['title'] ?: __('Meeting of :name', ['name' => $user->name]),
            'description' => $data['description'] ?? null,
            'room_name' => Meeting::roomNameFor($currentTeam, $uuid),
            'max_participants' => $max,
            'created_by' => $user->id,
            'scheduled_at' => $planned ? $data['scheduled_at'] : null,
            'duration_minutes' => $planned ? (int) $data['duration_minutes'] : null,
        ]);
        $meeting->setRelation('team', $currentTeam);

        $inviteeIds = $this->otherIds($data['invitees'] ?? [], $user);
        $meeting->invitees()->sync($inviteeIds);

        if ($planned) {
            // The room is opened by the first person who joins (token()).
            $this->notify($this->users($inviteeIds)->push($user), new MeetingScheduled($meeting, $user));
            $this->broadcast(new MeetingsChanged($currentTeam->id));

            Inertia::flash('toast', ['type' => 'success', 'message' => $inviteeIds === []
                ? __('Meeting scheduled.')
                : __('Meeting scheduled. Invitations sent to :count participant(s).', ['count' => count($inviteeIds)])]);

            return to_route('meetings.index', ['current_team' => $currentTeam->slug]);
        }

        try {
            $this->liveKit->createRoom($meeting->room_name, $max, ['team' => $currentTeam->id, 'meeting' => $meeting->uuid]);
        } catch (RuntimeException $exception) {
            report($exception);
            $meeting->delete();

            throw ValidationException::withMessages(['title' => __('The video server is unreachable. Please try again in a moment.')]);
        }

        $this->notify($this->users($inviteeIds), new InstantMeetingInvitation($meeting, $user));
        $this->broadcast(new MeetingsChanged($currentTeam->id));

        return to_route('meetings.show', ['current_team' => $currentTeam->slug, 'meeting' => $meeting->uuid]);
    }

    /**
     * Change a planned meeting that has not started. Calendars follow:
     * kept attendees get the updated invitation, new ones an invitation,
     * removed ones a cancellation.
     */
    public function update(Request $request, Team $currentTeam, Meeting $meeting): RedirectResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        $user = $request->user();
        abort_unless($this->canManage($user, $currentTeam, $meeting), 403);
        abort_unless($meeting->isUpcoming(), 422, __('Only a planned meeting that has not started can be changed.'));

        $data = $this->validated($request, $currentTeam, scheduledOnly: true);
        $meeting->setRelation('team', $currentTeam);

        $before = $meeting->invitees()->pluck('users.id')->all();
        $after = $this->otherIds($data['invitees'] ?? [], $meeting->creator ?? $user);
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        $meeting->fill([
            'title' => $data['title'] ?: $meeting->title,
            'description' => $data['description'] ?? null,
            'scheduled_at' => $data['scheduled_at'],
            'duration_minutes' => (int) $data['duration_minutes'],
        ]);

        $changed = $meeting->isDirty(['title', 'description', 'scheduled_at', 'duration_minutes']);

        if ($meeting->isDirty('scheduled_at')) {
            $meeting->reminded_at = null;
        }

        if ($changed || $added !== [] || $removed !== []) {
            $meeting->calendar_sequence++;
        }

        $meeting->save();
        $meeting->invitees()->sync($after);

        if ($removed !== []) {
            $removedUsers = $this->users($removed);
            $this->notify($removedUsers, new MeetingCancelled($meeting, $user, $removedUsers->all()));
        }

        if ($added !== []) {
            $this->notify($this->users($added), new MeetingScheduled($meeting, $user));
        }

        $kept = array_values(array_diff($after, $added));

        if ($changed) {
            $this->notify($this->users($kept)->push($meeting->creator ?? $user), new MeetingUpdated($meeting, $user));
        }

        $this->broadcast(new MeetingsChanged($currentTeam->id));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Meeting updated. Participants have been notified.')]);

        return to_route('meetings.index', ['current_team' => $currentTeam->slug]);
    }

    /** Cancel a planned meeting before it starts: calendars remove the event. */
    public function cancel(Request $request, Team $currentTeam, Meeting $meeting): RedirectResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        $user = $request->user();
        abort_unless($this->canManage($user, $currentTeam, $meeting), 403);
        abort_unless($meeting->isUpcoming(), 422, __('Only a planned meeting that has not started can be cancelled.'));

        $meeting->setRelation('team', $currentTeam);
        $meeting->update(['cancelled_at' => now(), 'calendar_sequence' => $meeting->calendar_sequence + 1]);

        $this->notify(
            $meeting->invitees()->get()->push($meeting->creator ?? $user),
            new MeetingCancelled($meeting, $user),
        );
        $this->broadcast(new MeetingsChanged($currentTeam->id));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Meeting cancelled. Participants have been notified.')]);

        return to_route('meetings.index', ['current_team' => $currentTeam->slug]);
    }

    /** The meeting as an .ics file, to import into any calendar. */
    public function calendar(Team $currentTeam, Meeting $meeting): HttpResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        abort_unless($meeting->isScheduled(), 404);

        $meeting->setRelation('team', $currentTeam);
        $method = $meeting->isCancelled() ? MeetingCalendar::METHOD_CANCEL : MeetingCalendar::METHOD_PUBLISH;

        return response($this->calendar->ics($meeting, $method), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->calendar->filename($meeting).'"',
        ]);
    }

    public function show(Request $request, Team $currentTeam, Meeting $meeting): Response|RedirectResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);

        if ($meeting->isEnded()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => $meeting->isCancelled()
                ? __('This meeting was cancelled.')
                : __('This meeting has ended.')]);

            return to_route('meetings.index', ['current_team' => $currentTeam->slug]);
        }

        $user = $request->user();
        $meeting->load(['creator:id,name', 'invitees:users.id,users.name', 'notesEditor:id,name']);

        return Inertia::render('meetings/Show', [
            'meeting' => $this->row($meeting, $user, $currentTeam),
            'identity' => $this->identity($user),
            'me' => ['id' => $user->id, 'name' => $user->name],
            'canEnd' => $this->canManage($user, $currentTeam, $meeting),
            'canRecord' => $this->canManage($user, $currentTeam, $meeting) && $this->recordingAvailable($currentTeam),
            'members' => $this->members($currentTeam, $user),
            'planName' => $currentTeam->plan()->name,
            'meetingMinutes' => $this->limits->limits($currentTeam)['meeting_minutes'],
            'canManageBilling' => (bool) $user->teamRole($currentTeam)?->isAtLeast(TeamRole::Admin),
        ]);
    }

    /** Short-lived join token, asked for right before connecting. */
    public function token(Request $request, Team $currentTeam, Meeting $meeting): JsonResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        abort_if($meeting->isEnded(), 410, __('This meeting has ended.'));
        abort_unless($currentTeam->plan()->allows('video'), 403, __('This feature is not included in your :plan plan.', ['plan' => $currentTeam->plan()->name]));
        abort_unless($this->liveKit->isConfigured(), 503, __('Video meetings are not configured on this server.'));

        $user = $request->user();

        if ($meeting->hasReachedTimeLimit()) {
            $meeting->setRelation('team', $currentTeam);
            $this->rooms->close($meeting, MeetingRooms::REASON_LIMIT);

            abort(410, __('This meeting reached the :minutes-minute limit of your plan.', ['minutes' => $meeting->time_limit_minutes]));
        }

        try {
            // Opens the room of a planned meeting, or re-creates one LiveKit closed while empty.
            $this->liveKit->createRoom($meeting->room_name, $meeting->max_participants, ['team' => $currentTeam->id, 'meeting' => $meeting->uuid]);
        } catch (RuntimeException $exception) {
            report($exception);
            abort(503, __('The video server is unreachable. Please try again in a moment.'));
        }

        $alreadyIn = $meeting->invitees()->whereNotNull('meeting_user.joined_at')->pluck('users.id')->all();
        $meeting->invitees()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);

        if ($meeting->started_at === null) {
            // The clock of the plan's time limit starts with the first person in.
            $meeting->update([
                'started_at' => now(),
                'time_limit_minutes' => $this->limits->limits($currentTeam)['meeting_minutes'],
            ]);
            $meeting->setRelation('team', $currentTeam);

            // A planned meeting just opened: those not in yet get the link.
            if ($meeting->isScheduled()) {
                $waiting = $meeting->invitees()
                    ->whereNotIn('users.id', [...$alreadyIn, $user->id])
                    ->get();
                $creator = $meeting->creator;

                if ($creator && ! $creator->is($user) && ! in_array($creator->id, $alreadyIn, true)) {
                    $waiting->push($creator);
                }

                $this->notify($waiting->unique('id'), new MeetingStarted($meeting, $user));
            }

            $this->broadcast(new MeetingsChanged($currentTeam->id));
        }

        return response()->json([
            'url' => $this->liveKit->url(),
            // Video quality of the plan (sd, hd, full_hd): the browser captures and publishes at this level.
            'quality' => match (true) {
                $currentTeam->plan()->allows('full_hd_video') => 'full_hd',
                $currentTeam->plan()->allows('hd_video') => 'hd',
                default => 'sd',
            },
            'token' => $this->liveKit->joinToken(
                $meeting->room_name,
                $this->identity($user),
                $user->name,
                ['roomAdmin' => $this->canManage($user, $currentTeam, $meeting)],
                ['user_id' => $user->id],
            ),
        ]);
    }

    /**
     * The browser is connected to the room: only now does the agent go
     * Non prêt – Réunion vidéo, so a failed connection (unreachable video
     * server, refused camera) never leaves them paused for nothing.
     */
    public function joined(Request $request, Team $currentTeam, Meeting $meeting): JsonResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        abort_if($meeting->isEnded(), 410, __('This meeting has ended.'));

        $user = $request->user();
        $agentStatus = $this->pauseForMeeting($user, $currentTeam);

        return response()->json([
            // The status menu refreshes from this (null: the user takes no calls).
            'agentState' => $agentStatus ? $this->states->payload($user, $currentTeam, $agentStatus) : null,
        ]);
    }

    /** Invite more team members: a planned meeting sends invitations, a running one live notifications. */
    public function invite(Request $request, Team $currentTeam, Meeting $meeting): RedirectResponse|JsonResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        abort_if($meeting->isEnded(), 410, __('This meeting has ended.'));

        $data = $request->validate([
            'invitees' => ['required', 'array', 'min:1', 'max:100'],
            'invitees.*' => ['integer', Rule::exists('team_members', 'user_id')->where('team_id', $currentTeam->id)],
        ]);

        $user = $request->user();
        $meeting->setRelation('team', $currentTeam);
        $already = $meeting->invitees()->pluck('users.id')->all();
        $new = array_values(array_diff($this->otherIds($data['invitees'], $user), $already));

        $meeting->invitees()->syncWithoutDetaching($new);

        if ($new !== []) {
            if ($meeting->isUpcoming()) {
                $meeting->increment('calendar_sequence');
                $this->notify($this->users($new), new MeetingScheduled($meeting, $user));
            } else {
                $this->notify($this->users($new), new InstantMeetingInvitation($meeting, $user));
            }
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['invited' => count($new)]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Team $team, bool $scheduledOnly = false): array
    {
        $scheduled = $scheduledOnly || $request->input('mode') === 'scheduled';

        return $request->validate([
            'mode' => ['nullable', Rule::in(['instant', 'scheduled'])],
            'title' => [$scheduled ? 'required' : 'nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scheduled_at' => [$scheduled ? 'required' : 'nullable', 'date', 'after:'.now()->subMinutes(5)->toIso8601String()],
            'duration_minutes' => [$scheduled ? 'required' : 'nullable', 'integer', Rule::in($this->durations($team))],
            'invitees' => ['array', 'max:100'],
            'invitees.*' => ['integer', Rule::exists('team_members', 'user_id')->where('team_id', $team->id)],
        ], [
            'scheduled_at.after' => __('Choose a date and time in the future.'),
            'duration_minutes.in' => __('Your :plan plan allows meetings of up to :minutes minutes.', [
                'plan' => $team->plan()->name,
                'minutes' => $this->limits->limits($team)['meeting_minutes'],
            ]),
        ], [
            'title' => __('title'),
            'scheduled_at' => __('date and time'),
            'duration_minutes' => __('duration'),
        ]);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function otherIds(array $ids, User $organizer): array
    {
        return array_values(array_unique(array_diff(array_map('intval', $ids), [$organizer->id])));
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function users(array $ids): Collection
    {
        return $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get()->toBase();
    }

    /**
     * Send without failing the request: e-mails are queued, and an
     * unreachable Reverb must not block creating the meeting.
     *
     * @param  iterable<User>  $users
     */
    private function notify(iterable $users, object $notification): void
    {
        $users = collect($users)->filter()->unique('id')->values();

        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::send($users, $notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return list<array{id: int, name: string, role: string}> */
    private function members(Team $team, User $user): array
    {
        return $team->members()
            ->where('users.id', '!=', $user->id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->pivot->role instanceof TeamRole ? $member->pivot->role->label() : (string) $member->pivot->role,
            ])->all();
    }

    private function isManager(User $user, Team $team): bool
    {
        return (bool) $user->teamRole($team)?->isAtLeast(TeamRole::Supervisor);
    }

    public function end(Request $request, Team $currentTeam, Meeting $meeting): RedirectResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        abort_unless($this->canManage($request->user(), $currentTeam, $meeting), 403);

        $meeting->setRelation('team', $currentTeam);
        $this->rooms->close($meeting, MeetingRooms::REASON_HOST);

        return to_route('meetings.index', ['current_team' => $currentTeam->slug]);
    }

    /**
     * Save the shared notes (before, during or after the meeting). Last
     * write wins, except over someone else's newer save: then 409 with
     * their text, and the editor chooses.
     */
    public function notes(Request $request, Team $currentTeam, Meeting $meeting): JsonResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        $user = $request->user();
        abort_unless($this->canEditNotes($user, $currentTeam, $meeting), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:50000'],
            'base' => ['nullable', 'date'],
            'force' => ['boolean'],
        ]);

        // `base` is the version the editor opened (null: there were no notes yet).
        $base = isset($data['base']) ? Carbon::parse($data['base']) : null;
        $stale = ! ($data['force'] ?? false)
            && $meeting->notes_updated_at !== null
            && $meeting->notes_updated_by !== $user->id
            && ($base === null || $meeting->notes_updated_at->gt($base->copy()->addSecond()));

        $meeting->loadMissing('notesEditor:id,name');

        if ($stale) {
            return response()->json([
                'message' => __('These notes were changed by :name.', ['name' => $meeting->notesEditor?->name ?? __('A colleague')]),
                ...$this->notesPayload($meeting),
            ], 409);
        }

        $meeting->forceFill([
            'notes' => $data['notes'] ?? null,
            'notes_updated_at' => now(),
            'notes_updated_by' => $user->id,
        ])->save();
        $meeting->setRelation('notesEditor', $user);

        $this->broadcast(new MeetingNotesUpdated($meeting));

        return response()->json($this->notesPayload($meeting));
    }

    /** @return array<string, mixed> */
    private function notesPayload(Meeting $meeting): array
    {
        return [
            'notes' => (string) $meeting->notes,
            'updatedAt' => $meeting->notes_updated_at?->toIso8601String(),
            'updatedBy' => $meeting->notesEditor?->name,
            'updatedById' => $meeting->notes_updated_by,
        ];
    }

    /** Organizer, invitees and supervisors and up. */
    private function canEditNotes(User $user, Team $team, Meeting $meeting): bool
    {
        if ($meeting->created_by === $user->id || $this->isManager($user, $team)) {
            return true;
        }

        return $meeting->relationLoaded('invitees')
            ? $meeting->invitees->contains('id', $user->id)
            : $meeting->invitees()->where('users.id', $user->id)->exists();
    }

    /**
     * Durations offered when planning, up to the plan's time limit.
     *
     * @return list<int>
     */
    private function durations(Team $team): array
    {
        $limit = $this->limits->limits($team)['meeting_minutes'];

        return array_values(array_filter(
            array_map('intval', (array) config('meetings.durations')),
            fn (int $minutes) => $limit === null || $minutes <= $limit,
        ));
    }

    public function startRecording(Request $request, Team $currentTeam, Meeting $meeting): JsonResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        abort_unless($this->canManage($request->user(), $currentTeam, $meeting), 403);
        abort_unless($this->recordingAvailable($currentTeam), 403, __('This feature is not included in your :plan plan.', ['plan' => $currentTeam->plan()->name]));
        abort_if($meeting->isEnded(), 410, __('This meeting has ended.'));

        if (! $meeting->isRecording()) {
            try {
                $meeting->update([
                    'recording_egress_id' => $this->liveKit->startRecording($meeting->room_name),
                    'recording_started_at' => now(),
                ]);
            } catch (RuntimeException $exception) {
                report($exception);
                abort(503, __('The recording could not be started.'));
            }
        }

        return response()->json(['recording' => true]);
    }

    public function stopRecording(Request $request, Team $currentTeam, Meeting $meeting): JsonResponse
    {
        $this->ensureTeamMeeting($currentTeam, $meeting);
        abort_unless($this->canManage($request->user(), $currentTeam, $meeting), 403);

        if ($meeting->recording_egress_id) {
            try {
                $this->liveKit->stopRecording($meeting->recording_egress_id);
            } catch (RuntimeException $exception) {
                report($exception);
            }

            $meeting->update(['recording_egress_id' => null]);
        }

        return response()->json(['recording' => false]);
    }

    /** @return array<string, mixed> */
    private function row(Meeting $meeting, User $user, Team $team): array
    {
        $meeting->setRelation('team', $team);
        $invitees = $meeting->relationLoaded('invitees') ? $meeting->invitees : collect();
        $creatorId = $meeting->created_by;

        return [
            'uuid' => $meeting->uuid,
            'title' => $meeting->title,
            'description' => $meeting->description,
            'status' => match (true) {
                $meeting->isCancelled() => 'cancelled',
                $meeting->ended_at !== null => 'ended',
                $meeting->isLive() => 'live',
                default => 'upcoming',
            },
            'kind' => $meeting->isScheduled() ? 'scheduled' : 'instant',
            'creator' => $meeting->creator?->name,
            'isOrganizer' => $creatorId === $user->id,
            'canManage' => $this->canManage($user, $team, $meeting),
            'participants' => $invitees
                ->reject(fn (User $invitee) => $invitee->id === $creatorId)
                ->map(fn (User $invitee) => [
                    'id' => $invitee->id,
                    'name' => $invitee->name,
                    'joined' => $invitee->pivot?->joined_at !== null,
                ])
                ->values()
                ->all(),
            'maxParticipants' => $meeting->max_participants,
            'scheduledAt' => $meeting->scheduled_at?->toIso8601String(),
            'durationMinutes' => $meeting->duration_minutes,
            'endsAt' => $meeting->plannedEnd()?->toIso8601String(),
            'createdAt' => $meeting->created_at->toIso8601String(),
            'startedAt' => $meeting->started_at?->toIso8601String(),
            'endedAt' => ($meeting->ended_at ?? $meeting->cancelled_at)?->toIso8601String(),
            'endedReason' => $meeting->ended_reason,
            'timeLimitMinutes' => $meeting->time_limit_minutes,
            'limitEndsAt' => $meeting->limitEndsAt()?->toIso8601String(),
            'notes' => $meeting->notes,
            'notesUpdatedAt' => $meeting->notes_updated_at?->toIso8601String(),
            'notesUpdatedBy' => $meeting->notesEditor?->name,
            'canEditNotes' => $this->canEditNotes($user, $team, $meeting),
            'recording' => $meeting->isRecording(),
            'hasRecording' => $meeting->recording_location !== null,
            'url' => $meeting->joinUrl(),
            'calendar' => $meeting->isScheduled() ? [
                'google' => $this->calendar->googleUrl($meeting),
                'outlook' => $this->calendar->outlookUrl($meeting),
                'ics' => route('meetings.calendar', ['current_team' => $team->slug, 'meeting' => $meeting->uuid]),
            ] : null,
        ];
    }

    private function identity(User $user): string
    {
        return 'u'.$user->id;
    }

    /** The creator, and owners/admins/supervisors of the team, run the meeting. */
    private function canManage(User $user, Team $team, Meeting $meeting): bool
    {
        return $meeting->created_by === $user->id
            || (bool) $user->teamRole($team)?->isAtLeast(TeamRole::Supervisor);
    }

    private function recordingAvailable(Team $team): bool
    {
        return $team->plan()->allows('recording') && (bool) config('livekit.recording.enabled');
    }

    private function ensureTeamMeeting(Team $team, Meeting $meeting): void
    {
        abort_unless($meeting->team_id === $team->id, 404);
    }

    private function ensureConfigured(string $field): void
    {
        if (! $this->liveKit->isConfigured()) {
            throw ValidationException::withMessages([$field => __('Video meetings are not configured on this server.')]);
        }
    }

    /**
     * Stop queue calls while the agent is in the meeting. A failure to
     * reach the Asterisk database must not keep them out of the meeting.
     */
    private function pauseForMeeting(User $user, Team $team): ?AgentStatus
    {
        // No queues in internal-only organizations: nothing to pause, nothing to resume.
        if ($team->isInternalOnly()) {
            $this->clearMeetingPause($user, $team);

            return null;
        }

        try {
            return $this->states->enterMeeting($user, $team);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** A meeting pause left from before the organization switched to internal-only. */
    private function clearMeetingPause(User $user, Team $team): void
    {
        try {
            $current = $this->states->current($user, $team);

            if ($current->exists && $this->states->isInMeetingPause($current)) {
                $this->states->set($user, $team, AgentState::Ready);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Push is best effort: an unreachable Reverb must not fail the request. */
    private function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
