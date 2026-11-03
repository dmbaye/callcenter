<?php

namespace App\Http\Controllers;

use App\Models\CallOutcome;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CallOutcomesController extends Controller
{
    /**
     * Store (or replace, on the natural key) a call outcome in the team.
     *
     * The same call answered twice — e.g. an agent saves a wrap-up,
     * then reopens and corrects it — must not create duplicates.
     */
    public function store(Request $request, Team $currentTeam): RedirectResponse
    {
        $data = $this->validated($request);

        $request->user()->callOutcomes()->updateOrCreate(
            [
                'team_id' => $currentTeam->id,
                'caller' => $data['caller'],
                'started_at' => $data['started_at'],
            ],
            $data,
        );

        return back();
    }

    public function update(Request $request, Team $currentTeam, CallOutcome $callOutcome): RedirectResponse
    {
        $this->authorizeOwnOutcome($request, $currentTeam, $callOutcome);

        // The history page edits the classification only; the call itself is immutable.
        $callOutcome->update($request->validate([
            'outcome' => ['required', 'string', Rule::in(CallOutcome::OUTCOMES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]));

        return back();
    }

    public function destroy(Request $request, Team $currentTeam, CallOutcome $callOutcome): RedirectResponse
    {
        $this->authorizeOwnOutcome($request, $currentTeam, $callOutcome);

        $callOutcome->delete();

        return back();
    }

    private function authorizeOwnOutcome(Request $request, Team $team, CallOutcome $callOutcome): void
    {
        abort_unless($callOutcome->user_id === $request->user()->id && $callOutcome->team_id === $team->id, 403);
    }

    /**
     * @return array{caller: string, direction: string, started_at: string, ended_at: string|null, duration: int|null, outcome: string, notes: string|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'caller' => ['required', 'string', 'max:40'],
            'direction' => ['required', Rule::in(['inbound', 'outbound'])],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'outcome' => ['required', 'string', Rule::in(CallOutcome::OUTCOMES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['started_at'] = Carbon::parse($data['started_at'])->format('Y-m-d H:i:s');
        $data['ended_at'] = isset($data['ended_at'])
            ? Carbon::parse($data['ended_at'])->format('Y-m-d H:i:s')
            : null;

        return $data;
    }
}
