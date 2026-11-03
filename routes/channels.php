<?php

use App\Models\Meeting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

// Live monitoring pings: only people allowed to monitor agents on that team.
Broadcast::channel('team.{teamId}.monitoring', function (User $user, int $teamId): bool {
    $team = Team::find($teamId);

    return $team !== null && Gate::forUser($user)->allows('monitorAgents', $team);
});

// Personal notifications (the bell: meeting invitations, reminders, changes).
Broadcast::channel('users.{userId}', fn (User $user, int $userId): bool => $user->id === $userId);

// Meeting list refresh pings: any member of the team.
Broadcast::channel('team.{teamId}.meetings', function (User $user, int $teamId): bool {
    $team = Team::find($teamId);

    return $team !== null && $user->belongsToTeam($team);
});

// One meeting: shared notes and "the meeting has ended" for anyone of its team.
Broadcast::channel('meeting.{uuid}', function (User $user, string $uuid): bool {
    $meeting = Meeting::query()->where('uuid', $uuid)->first();

    return $meeting !== null && $user->belongsToTeam($meeting->team);
});
