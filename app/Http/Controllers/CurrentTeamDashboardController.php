<?php

namespace App\Http\Controllers;

use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /dashboard: Fortify's `home` and other team-less links land here and
 * are sent to the current team's dashboard.
 */
class CurrentTeamDashboardController extends Controller
{
    use RedirectsToCurrentTeam;

    public function __invoke(Request $request): RedirectResponse
    {
        return redirect($this->redirectPathForCurrentTeam($request, '/dashboard'));
    }
}
