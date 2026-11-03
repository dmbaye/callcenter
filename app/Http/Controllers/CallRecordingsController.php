<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\CallRecording;
use App\Models\Team;
use App\Services\CallRecordings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallRecordingsController extends Controller
{
    /** Stream a recording to the call history player (supports seeking). */
    public function show(Request $request, Team $currentTeam, CallRecording $recording, CallRecordings $recordings): StreamedResponse
    {
        $user = $request->user();
        $canViewTeam = $user->hasTeamPermission($currentTeam, TeamPermission::MonitorAgents);

        abort_unless($recordings->canListen($user, $currentTeam, $recording, $canViewTeam), 404);

        $disk = Storage::disk($recording->disk);
        abort_unless($disk->exists($recording->path), 404);

        $name = 'appel-'.str_replace('.', '-', $recording->uniqueid).'.wav';

        return $request->boolean('download')
            ? $disk->download($recording->path, $name, ['Content-Type' => 'audio/wav'])
            : $disk->response($recording->path, $name, [
                'Content-Type' => 'audio/wav',
                'Cache-Control' => 'private, max-age=600',
            ]);
    }
}
