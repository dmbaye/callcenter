<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Notifications\Meetings\MeetingStartingSoon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

class RemindMeetings extends Command
{
    protected $signature = 'meetings:remind';

    protected $description = 'Remind participants a few minutes before a planned video meeting starts';

    public function handle(): int
    {
        $minutes = (int) config('meetings.reminder_minutes', 10);
        $sent = 0;

        Meeting::query()
            ->with(['team', 'creator', 'invitees'])
            ->whereNotNull('scheduled_at')
            ->whereNull('reminded_at')
            ->whereNull('started_at')
            ->whereNull('ended_at')
            ->whereNull('cancelled_at')
            ->whereBetween('scheduled_at', [now(), now()->addMinutes($minutes)])
            ->each(function (Meeting $meeting) use (&$sent) {
                // Marked first: a failing push must not remind twice on the next run.
                $meeting->update(['reminded_at' => now()]);

                $people = $meeting->invitees->push($meeting->creator)->filter()->unique('id');

                try {
                    Notification::send($people, new MeetingStartingSoon($meeting));
                    $sent += $people->count();
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
