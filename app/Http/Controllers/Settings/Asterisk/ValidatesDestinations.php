<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Enums\RouteDestination;
use App\Models\Team;
use App\Services\AsteriskDialplan;
use Illuminate\Validation\ValidationException;

trait ValidatesDestinations
{
    /** Checks that a destination target exists in this team; returns the normalised target. */
    protected function destinationTarget(Team $team, ?string $type, mixed $target, string $field): ?string
    {
        $destination = RouteDestination::tryFrom((string) $type);
        $target = $target === null ? null : trim((string) $target);

        $valid = match ($destination) {
            RouteDestination::Queue, RouteDestination::Callback => $team->callQueues()->whereKey((int) $target)->exists(),
            RouteDestination::Ivr => $team->ivrMenus()->whereKey((int) $target)->exists(),
            RouteDestination::Extension => (bool) preg_match('/^\d{2,20}$/', (string) $target),
            RouteDestination::Message => AsteriskDialplan::isSoundName((string) $target),
            RouteDestination::Hangup, null => true,
        };

        if (! $valid) {
            throw ValidationException::withMessages([$field => __('Choose a valid destination.')]);
        }

        return in_array($destination, [RouteDestination::Hangup, null], true) ? null : $target;
    }
}
