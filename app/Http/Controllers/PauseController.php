<?php

namespace App\Http\Controllers;

use App\Enums\AgentState;
use App\Models\Team;
use App\Services\AgentStates;
use App\Services\AsteriskQueueMembers;
use App\Services\MonitoringNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The agent status menu (Prêt, Non prêt + motif, Post-appel, Ne pas
 * déranger, Connexion / Déconnexion) and the queue presence driven by the
 * softphone (join / heartbeat / leave).
 */
class PauseController extends Controller
{
    public function __construct(
        private AsteriskQueueMembers $members,
        private AgentStates $states,
        private MonitoringNotifier $notifier,
    ) {}

    /** Current state, plus the reasons offered in the menu. */
    public function status(Request $request, Team $currentTeam): JsonResponse
    {
        return response()->json([
            ...$this->states->payload($request->user(), $currentTeam),
            'reasons' => $this->states->reasons($currentTeam),
        ]);
    }

    /** Choose a state from the status menu. */
    public function update(Request $request, Team $currentTeam): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::enum(AgentState::class)],
            'reason_id' => [
                'nullable',
                'integer',
                Rule::exists('pause_reasons', 'id')->where('team_id', $currentTeam->id),
            ],
        ]);

        $state = AgentState::from($data['state']);
        $reason = isset($data['reason_id']) ? $currentTeam->pauseReasons()->find($data['reason_id']) : null;

        $status = $this->states->set($request->user(), $currentTeam, $state, $reason);

        return response()->json($this->states->payload($request->user(), $currentTeam, $status));
    }

    /** Pause in every team queue (Non prêt without a reason). */
    public function store(Request $request, Team $currentTeam): RedirectResponse|JsonResponse
    {
        $this->states->set($request->user(), $currentTeam, AgentState::NotReady);

        return $this->respond($request, $currentTeam);
    }

    /** Resume in every team queue (Prêt). */
    public function destroy(Request $request, Team $currentTeam): RedirectResponse|JsonResponse
    {
        $this->states->set($request->user(), $currentTeam, AgentState::Ready);

        return $this->respond($request, $currentTeam);
    }

    /**
     * The softphone registered (or is still registered): join the team
     * queues in the chosen state and refresh the heartbeat. An agent who
     * chose Déconnexion stays out of the queues.
     */
    public function join(Request $request, Team $currentTeam): JsonResponse
    {
        $user = $request->user();
        $before = $this->members->status($user, $currentTeam);

        $status = $this->states->heartbeat($user, $currentTeam);
        $payload = $this->states->payload($user, $currentTeam, $status);

        if ($payload['status'] !== $before) {
            $this->notifier->notifyTeam($currentTeam);
        }

        return response()->json([
            ...$payload,
            'heartbeat' => (int) config('asterisk.presence.heartbeat_interval', 60),
        ]);
    }

    /** The softphone unregistered (tab closed, logout, team switch). The chosen state is kept. */
    public function leave(Request $request, Team $currentTeam): JsonResponse
    {
        $this->members->leave($request->user(), $currentTeam);
        $this->notifier->notifyTeam($currentTeam);

        return response()->json(['status' => AsteriskQueueMembers::OFFLINE]);
    }

    private function respond(Request $request, Team $team): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json($this->states->payload($request->user(), $team));
        }

        return redirect()->back();
    }
}
