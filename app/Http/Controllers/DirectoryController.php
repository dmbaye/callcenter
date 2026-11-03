<?php

namespace App\Http\Controllers;

use App\Models\AgentStateLog;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskQueueMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The colleagues one can call from the softphone: every member with an
 * extension in the team, with the status they chose (Prêt, Non prêt…).
 * Same directory as the internal-mode dashboard, for call centers too.
 */
class DirectoryController extends Controller
{
    public function index(Request $request, Team $currentTeam, AsteriskQueueMembers $presence): JsonResponse
    {
        $staleAfter = (int) config('asterisk.presence.stale_after', 180);
        $me = $request->user();

        $members = $currentTeam->members()
            ->with(['phoneExtensions' => fn ($query) => $query->where('team_id', $currentTeam->id)])
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.avatar_path', 'users.job_title', 'users.department'])
            ->reject(fn (User $member) => $member->is($me) || $member->phoneExtensions->isEmpty())
            ->values();

        // Current status: the open entry of the agent log.
        $states = AgentStateLog::query()
            ->where('team_id', $currentTeam->id)
            ->whereIn('user_id', $members->pluck('id'))
            ->whereNull('ended_at')
            ->latest('started_at')
            ->get(['user_id', 'state', 'reason'])
            ->unique('user_id')
            ->keyBy('user_id');

        return response()->json([
            'colleagues' => $members->map(function (User $member) use ($states, $presence, $currentTeam, $staleAfter) {
                $state = $states->get($member->id);
                // Softphone heartbeat: no recent beat = tab closed, cannot be called.
                $seen = $presence->lastSeen($member, $currentTeam);
                $online = $seen !== null && $seen >= now()->getTimestamp() - $staleAfter;

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'avatar' => $member->avatar,
                    'jobTitle' => $member->job_title,
                    'department' => $member->department,
                    'extension' => $member->phoneExtensions->first()->extension,
                    'online' => $online,
                    'state' => $online ? $state?->state?->value : null,
                    'stateLabel' => $online ? $state?->state?->labelWith($state->reason) : __('Hors ligne'),
                ];
            })->all(),
        ]);
    }
}
