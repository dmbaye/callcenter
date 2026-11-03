<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\MeetingRooms;
use Illuminate\Console\Command;

class EnforceMeetingLimits extends Command
{
    protected $signature = 'meetings:enforce-limits';

    protected $description = 'Close video meetings that reached the time limit of their plan';

    public function handle(MeetingRooms $rooms): int
    {
        $closed = 0;

        Meeting::query()
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('time_limit_minutes')
            ->each(function (Meeting $meeting) use ($rooms, &$closed) {
                if ($meeting->hasReachedTimeLimit()) {
                    $rooms->close($meeting, MeetingRooms::REASON_LIMIT);
                    $closed++;
                }
            });

        $this->info("{$closed} meeting(s) closed.");

        return self::SUCCESS;
    }
}
