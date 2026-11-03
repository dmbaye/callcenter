<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * Asterisk settings act on the user's current team and need admin rights
 * in it. The X-Team header check (EnsureCurrentTeamMatches) guarantees the
 * current team is the one the page was rendered for.
 */
trait AuthorizesAsteriskSettings
{
    protected function authorizedTeam(Request $request): Team
    {
        $user = $request->user();
        $team = $user?->currentTeam;

        abort_unless($team !== null, 404);
        abort_unless($user->teamRole($team)?->isAtLeast(TeamRole::Admin), 403);

        return $team;
    }
}
