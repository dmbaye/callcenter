<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\QueueClosure;
use App\Models\Team;
use App\Services\AsteriskDialplan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * When a queue is open: the weekly hours (saved as one grid) and closure
 * days (holidays). Every change rewrites the queue entry in the dialplan.
 * The emergency switch is a queue setting (QueuesController).
 */
class QueueScheduleController extends Controller
{
    use AuthorizesAsteriskSettings;

    public function __construct(private AsteriskDialplan $dialplan) {}

    /**
     * Replace the weekly hours. `days` holds the open days only; a queue with
     * no open day at all is treated as always open (no hours check).
     */
    public function hours(Request $request, CallQueue $queue): RedirectResponse
    {
        $team = $this->ownedQueue($request, $queue);

        $data = $request->validate([
            'days' => ['present', 'array', 'max:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.opens_at' => ['required', 'date_format:H:i'],
            'days.*.closes_at' => ['required', 'date_format:H:i'],
        ]);

        foreach ($data['days'] as $index => $day) {
            if ($day['closes_at'] <= $day['opens_at']) {
                throw ValidationException::withMessages([
                    "days.{$index}.closes_at" => __('The closing time must be after the opening time.'),
                ]);
            }
        }

        DB::transaction(function () use ($queue, $data): void {
            $queue->hours()->delete();

            foreach ($data['days'] as $day) {
                $queue->hours()->create([
                    'weekday' => (int) $day['weekday'],
                    'opens_at' => $day['opens_at'].':00',
                    'closes_at' => $day['closes_at'].':00',
                ]);
            }
        });

        $this->dialplan->syncTeamRouting($team);

        return back();
    }

    public function storeClosure(Request $request, CallQueue $queue): RedirectResponse
    {
        $team = $this->ownedQueue($request, $queue);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today', 'before:+1 year'],
            'label' => ['nullable', 'string', 'max:120'],
            'all_queues' => ['boolean'],
        ]);

        $targets = ($data['all_queues'] ?? false) ? $team->callQueues()->get() : collect([$queue]);

        foreach ($targets as $target) {
            $existing = $target->closures()->whereDate('date', $data['date'])->first();

            $existing
                ? $existing->update(['label' => $data['label'] ?? null])
                : $target->closures()->create(['date' => $data['date'], 'label' => $data['label'] ?? null]);
        }

        $this->dialplan->syncTeamRouting($team);

        return back();
    }

    public function destroyClosure(Request $request, QueueClosure $closure): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($closure->queue?->team_id === $team->id, 404);

        $closure->delete();
        $this->dialplan->syncTeamRouting($team);

        return back();
    }

    private function ownedQueue(Request $request, CallQueue $queue): Team
    {
        $team = $this->authorizedTeam($request);
        abort_unless($queue->team_id === $team->id, 404);
        $queue->setRelation('team', $team);

        return $team;
    }
}
