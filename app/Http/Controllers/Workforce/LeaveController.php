<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Team;
use App\Services\Workforce\Leaves;
use App\Services\Workforce\Planner;
use App\Support\Workforce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Leave: balances and requests of the agent; the team's upcoming leave for supervisors. */
class LeaveController extends Controller
{
    use WorkforceAccess;

    public function __construct(private Planner $planner, private Leaves $leaves) {}

    public function index(Request $request, Team $currentTeam): Response
    {
        $user = $request->user();
        $shell = $this->shell($request, $currentTeam, $this->planner);
        $year = (int) ($request->integer('year') ?: Workforce::today()->year);

        return Inertia::render('workforce/Leave', [
            ...$shell,
            'year' => $year,
            'balances' => $this->leaves->balances($currentTeam, $user, $year),
            'types' => collect(Planner::leaveTypeCards($currentTeam))->where('active', true)->values()->all(),
            'requests' => $currentTeam->leaveRequests()->with(['type', 'reviewer:id,name'])
                ->where('user_id', $user->id)->latest('start_date')->limit(50)->get()
                ->map(fn (LeaveRequest $l) => $l->toCard())->all(),
            // Supervisors: who is off in the coming 60 days.
            'teamLeave' => $this->isSupervisor($user, $currentTeam)
                ? $currentTeam->leaveRequests()->with(['type', 'user:id,name'])
                    ->whereIn('user_id', $this->planner->managedIds($currentTeam, $user))
                    ->whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::PENDING])
                    ->whereDate('end_date', '>=', Workforce::today()->toDateString())
                    ->whereDate('start_date', '<=', Workforce::today()->addDays(60)->toDateString())
                    ->orderBy('start_date')->get()->map(fn (LeaveRequest $l) => $l->toCard())->all()
                : [],
            'hoursPerDay' => (float) config('workforce.hours_per_day', 8),
        ]);
    }

    public function store(Request $request, Team $currentTeam): RedirectResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'partial' => ['boolean'],
            'start_time' => ['nullable', 'required_if:partial,true', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_if:partial,true', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $request->boolean('partial')) {
            $data['start_time'] = $data['end_time'] = null;
        }

        $this->planner->ensureSetup($currentTeam);
        $this->leaves->submit($currentTeam, $request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Leave request sent to your supervisor.')]);

        return back();
    }

    public function cancel(Request $request, Team $currentTeam, LeaveRequest $leave): RedirectResponse
    {
        $this->leaves->cancel($currentTeam, $leave, $request->user());

        return back();
    }
}
