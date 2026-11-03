<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\TeamRole;
use App\Models\Campaign;
use App\Models\Team;
use App\Models\User;

trait AuthorizesCampaigns
{
    /** Supervisors and up follow campaigns and start/pause them. */
    protected function canOperate(User $user, Team $team): bool
    {
        return (bool) $user->teamRole($team)?->isAtLeast(TeamRole::Supervisor);
    }

    protected function ensureTeamCampaign(Team $team, Campaign $campaign): void
    {
        abort_unless($campaign->team_id === $team->id, 404);
        $campaign->setRelation('team', $team);
    }
}
