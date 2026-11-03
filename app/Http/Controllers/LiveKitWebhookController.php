<?php

namespace App\Http\Controllers;

use App\Events\MeetingsChanged;
use App\Models\Meeting;
use App\Models\User;
use App\Services\AgentStates;
use App\Services\LiveKit;
use App\Services\MeetingRooms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * LiveKit server webhooks: keeps meeting state in line with the SFU
 * (room closed after everyone left, recording file ready).
 */
class LiveKitWebhookController extends Controller
{
    public function __invoke(Request $request, LiveKit $liveKit, AgentStates $states): JsonResponse
    {
        $event = $liveKit->verifyWebhook($request->getContent(), $request->header('Authorization'));

        if ($event === null) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $type = (string) ($event['event'] ?? '');
        $egress = (array) ($event['egressInfo'] ?? $event['egress_info'] ?? []);
        $roomName = (string) (data_get($event, 'room.name') ?? $egress['roomName'] ?? $egress['room_name'] ?? '');

        $meeting = $roomName !== '' ? Meeting::query()->where('room_name', $roomName)->first() : null;

        if ($meeting === null) {
            return response()->json(['ok' => true]);
        }

        $changed = match ($type) {
            'room_started' => $this->started($meeting),
            'room_finished' => $this->finished($meeting),
            'egress_ended' => $this->recordingEnded($meeting, $egress),
            'participant_joined' => $this->participantJoined($meeting, $event, $states),
            'participant_left' => true,
            default => false,
        };

        if ($changed) {
            try {
                event(new MeetingsChanged($meeting->team_id));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Backstop for the join token: an agent who reconnected (network drop,
     * second device) is paused again even if the token request could not
     * reach the Asterisk database.
     *
     * @param  array<string, mixed>  $event
     */
    private function participantJoined(Meeting $meeting, array $event, AgentStates $states): bool
    {
        $identity = (string) data_get($event, 'participant.identity', '');

        if (preg_match('/^u(\d+)$/', $identity, $matches) === 1) {
            $user = User::query()->find((int) $matches[1]);

            if ($user !== null && $user->belongsToTeam($meeting->team)) {
                try {
                    $states->enterMeeting($user, $meeting->team);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return true;
    }

    private function started(Meeting $meeting): bool
    {
        if ($meeting->started_at !== null) {
            return false;
        }

        $meeting->update(['started_at' => now()]);

        return true;
    }

    /** Everyone left and the empty timeout passed: the meeting is over. */
    private function finished(Meeting $meeting): bool
    {
        if ($meeting->ended_at !== null) {
            return false;
        }

        // LiveKit already closed the room: just record it.
        app(MeetingRooms::class)->close($meeting, MeetingRooms::REASON_EMPTY, deleteRoom: false);

        return false;
    }

    /** @param  array<string, mixed>  $egress */
    private function recordingEnded(Meeting $meeting, array $egress): bool
    {
        $file = (array) (data_get($egress, 'fileResults.0') ?? data_get($egress, 'file_results.0') ?? data_get($egress, 'file') ?? []);
        $location = $file['location'] ?? $file['filename'] ?? null;

        $meeting->update([
            'recording_location' => is_string($location) && $location !== '' ? $location : $meeting->recording_location,
            'recording_egress_id' => ($egress['egressId'] ?? $egress['egress_id'] ?? null) === $meeting->recording_egress_id
                ? null
                : $meeting->recording_egress_id,
        ]);

        return true;
    }
}
