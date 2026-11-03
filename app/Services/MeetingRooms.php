<?php

namespace App\Services;

use App\Events\MeetingEnded;
use App\Events\MeetingsChanged;
use App\Models\Meeting;
use RuntimeException;
use Throwable;

/**
 * Closing a meeting, whoever decides it: the organizer ("Terminer pour
 * tous"), the plan's time limit (meetings:enforce-limits) or LiveKit once
 * everyone has left. Deleting the LiveKit room disconnects anyone still in.
 */
class MeetingRooms
{
    public const REASON_HOST = 'host';

    public const REASON_LIMIT = 'limit';

    public const REASON_EMPTY = 'empty';

    public function __construct(private LiveKit $liveKit) {}

    public function close(Meeting $meeting, string $reason, bool $deleteRoom = true): void
    {
        if ($meeting->ended_at !== null) {
            return;
        }

        if ($deleteRoom) {
            try {
                if ($meeting->recording_egress_id) {
                    $this->liveKit->stopRecording($meeting->recording_egress_id);
                }

                $this->liveKit->deleteRoom($meeting->room_name);
            } catch (RuntimeException $exception) {
                report($exception);
            }
        }

        $meeting->update(['ended_at' => now(), 'ended_reason' => $reason, 'recording_egress_id' => null]);

        $this->broadcast(new MeetingEnded($meeting));
        $this->broadcast(new MeetingsChanged($meeting->team_id));
    }

    /** Push is best effort: an unreachable Reverb must not block closing. */
    private function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
