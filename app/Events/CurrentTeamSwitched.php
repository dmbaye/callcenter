<?php

namespace App\Events;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class CurrentTeamSwitched
{
    use Dispatchable;

    public function __construct(public User $user, public ?Team $previous, public ?Team $current) {}
}
