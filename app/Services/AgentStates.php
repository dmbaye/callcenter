<?php

namespace App\Services;

use App\Enums\AgentState;
use App\Models\AgentStateLog;
use App\Models\AgentStatus;
use App\Models\PauseReason;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The agent status menu: Prêt, Non prêt (with a reason), Traitement
 * post-appel, Ne pas déranger, Connexion / Déconnexion.
 *
 * The chosen state lives in the app database so it survives a reload, a
 * second tab or a softphone reconnect. AsteriskQueueMembers applies it to
 * queue_members: paused states set paused = 1 with a reason_paused,
 * Déconnecté removes the agent from the queues and keeps them out even
 * while the softphone heartbeat continues.
 */
class AgentStates
{
    /** Reason shown while an agent is in a video meeting (status menu, Asterisk, agent log). */
    public const MEETING_REASON = 'Réunion vidéo';

    public function __construct(
        private AsteriskQueueMembers $members,
        private MonitoringNotifier $notifier,
    ) {}

    /** The agent's current state (an agent who never chose one is Prêt). */
    public function current(User $user, Team $team): AgentStatus
    {
        return AgentStatus::query()->firstOrNew(
            ['team_id' => $team->id, 'user_id' => $user->id],
            ['state' => AgentState::Ready, 'changed_at' => now()],
        );
    }

    /**
     * Current states of many agents in one query (no row = Prêt).
     *
     * @param  iterable<User>  $users
     * @return array<int, AgentStatus> user id => state
     */
    public function forUsers(iterable $users, Team $team): array
    {
        $ids = collect($users)->pluck('id')->all();
        $rows = $ids === [] ? collect() : AgentStatus::query()
            ->where('team_id', $team->id)
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');

        return collect($ids)->mapWithKeys(fn (int $id) => [
            $id => $rows->get($id) ?? new AgentStatus([
                'team_id' => $team->id,
                'user_id' => $id,
                'state' => AgentState::Ready,
                'changed_at' => null,
            ]),
        ])->all();
    }

    /**
     * Change the agent's state and apply it to the queues at once.
     * A reason only applies to Non prêt: a team pause reason, or a system
     * label ($label, e.g. "Réunion vidéo") that is not one of the team's.
     */
    public function set(User $user, Team $team, AgentState $state, ?PauseReason $reason = null, ?string $label = null): AgentStatus
    {
        $reason = $state === AgentState::NotReady ? $reason : null;
        $reasonName = $state === AgentState::NotReady ? ($reason?->name ?? $label) : null;
        $current = $this->current($user, $team);

        $unchanged = $current->exists
            && $current->state === $state
            && $current->pause_reason_id === $reason?->id
            && $current->reason === $reasonName;

        if (! $unchanged) {
            DB::transaction(function () use ($current, $user, $team, $state, $reason, $reasonName) {
                $now = now();

                $this->closeLog($user, $team, $now);

                $current->fill([
                    'state' => $state,
                    'pause_reason_id' => $reason?->id,
                    'reason' => $reasonName,
                    'changed_at' => $now,
                ])->save();

                AgentStateLog::query()->create([
                    'team_id' => $team->id,
                    'user_id' => $user->id,
                    'state' => $state,
                    'reason' => $reasonName,
                    'started_at' => $now,
                ]);
            });
        }

        $this->apply($user, $team, $current);
        $this->notifier->notifyTeam($team);

        return $current;
    }

    /**
     * The agent joined a video meeting: queue calls must stop reaching them,
     * so a Prêt (or Post-appel) agent goes Non prêt – Réunion vidéo.
     *
     * An agent who already chose a pause (Non prêt with a reason, Ne pas
     * déranger, Déconnexion) keeps it: they are already out of reach and
     * their choice says more than "in a meeting". Users without a phone
     * extension in the team take no calls and are left alone.
     */
    public function enterMeeting(User $user, Team $team): ?AgentStatus
    {
        if ($user->phoneExtensionFor($team) === null) {
            return null;
        }

        $current = $this->current($user, $team);

        if (! in_array($current->state, [AgentState::Ready, AgentState::WrapUp], true)) {
            return $current;
        }

        return $this->set($user, $team, AgentState::NotReady, label: self::MEETING_REASON);
    }

    /** Whether the agent's current pause was set by joining a meeting. */
    public function isInMeetingPause(AgentStatus $status): bool
    {
        return $status->state === AgentState::NotReady
            && $status->pause_reason_id === null
            && $status->reason === self::MEETING_REASON;
    }

    /**
     * Softphone heartbeat: join the queues in the chosen state, or stay out
     * of them when the agent chose Déconnexion.
     */
    public function heartbeat(User $user, Team $team): AgentStatus
    {
        $current = $this->current($user, $team);

        if ($current->state === AgentState::LoggedOut) {
            $this->members->leave($user, $team);
            $this->members->touch($user, $team);

            return $current;
        }

        $this->members->join($user, $team, $this->pauseFor($current));

        return $current;
    }

    /** Push the state to queue_members (only when the softphone is online). */
    public function apply(User $user, Team $team, AgentStatus $status): void
    {
        if ($status->state === AgentState::LoggedOut) {
            $this->members->leave($user, $team);

            return;
        }

        $online = $this->members->lastSeen($user, $team) !== null
            || $this->members->status($user, $team) !== AsteriskQueueMembers::OFFLINE;

        if ($online) {
            $this->members->join($user, $team, $this->pauseFor($status));
        }
    }

    /**
     * The state as the status menu and the dashboards read it.
     *
     * @return array<string, mixed>
     */
    public function payload(User $user, Team $team, ?AgentStatus $status = null): array
    {
        $status ??= $this->current($user, $team);

        return [
            'status' => $this->members->status($user, $team),
            'state' => $status->state->value,
            'stateLabel' => $status->label(),
            'reason' => $status->reason,
            'reasonId' => $status->pause_reason_id,
            'since' => $status->changed_at?->toIso8601String(),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    public function reasons(Team $team): array
    {
        return $team->pauseReasons()->get(['id', 'name'])
            ->map(fn (PauseReason $reason) => ['id' => $reason->id, 'name' => $reason->name])
            ->all();
    }

    /** @return array{paused: bool, reason: string} */
    private function pauseFor(AgentStatus $status): array
    {
        return [
            'paused' => $status->state->pausesQueues(),
            'reason' => $status->state->asteriskReason($status->reason),
        ];
    }

    private function closeLog(User $user, Team $team, CarbonInterface $at): void
    {
        AgentStateLog::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => $at]);
    }
}
