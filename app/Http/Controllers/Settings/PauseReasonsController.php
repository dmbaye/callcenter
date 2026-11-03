<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Asterisk\AuthorizesAsteriskSettings;
use App\Models\PauseReason;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Non prêt" reasons offered in the agent status menu (team admins).
 * Deleting a reason keeps its name on agents currently paused with it.
 */
class PauseReasonsController extends Controller
{
    use AuthorizesAsteriskSettings;

    public function index(Request $request): Response
    {
        $team = $this->authorizedTeam($request);

        return Inertia::render('settings/PauseReasons', [
            'reasons' => $team->pauseReasons()->withCount([
                'agentStatuses as in_use' => fn ($query) => $query->where('state', 'not_ready'),
            ])->get()->map(fn (PauseReason $reason) => [
                'id' => $reason->id,
                'name' => $reason->name,
                'inUse' => (int) $reason->in_use,
            ])->all(),
            'max' => (int) config('agents.max_pause_reasons', 30),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $name = $this->validatedName($request, $team);

        if ($team->pauseReasons()->count() >= (int) config('agents.max_pause_reasons', 30)) {
            throw ValidationException::withMessages(['name' => 'Vous avez atteint le nombre maximum de motifs.']);
        }

        $team->pauseReasons()->create([
            'name' => $name,
            'position' => (int) $team->pauseReasons()->max('position') + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Motif ajouté.']);

        return back(303);
    }

    public function update(Request $request, PauseReason $reason): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($reason->team_id === $team->id, 404);

        $reason->update(['name' => $this->validatedName($request, $team, $reason)]);

        return back(303);
    }

    /** New menu order: the ids, top to bottom. */
    public function reorder(Request $request): RedirectResponse
    {
        $team = $this->authorizedTeam($request);

        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('pause_reasons', 'id')->where('team_id', $team->id)],
        ])['ids'];

        foreach (array_values(array_unique($ids)) as $position => $id) {
            $team->pauseReasons()->whereKey($id)->update(['position' => $position]);
        }

        return back(303);
    }

    public function destroy(Request $request, PauseReason $reason): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($reason->team_id === $team->id, 404);

        $reason->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Motif supprimé.']);

        return back(303);
    }

    private function validatedName(Request $request, Team $team, ?PauseReason $ignore = null): string
    {
        return trim($request->validate([
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('pause_reasons', 'name')->where('team_id', $team->id)->ignore($ignore?->id),
            ],
        ], [
            'name.unique' => 'Ce motif existe déjà.',
        ])['name']);
    }
}
