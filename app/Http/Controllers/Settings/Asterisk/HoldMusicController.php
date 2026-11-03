<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Http\Controllers\Controller;
use App\Models\HoldMusicTrack;
use App\Services\TeamHoldMusic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Paramètres → Musique d'attente: the team's own hold music, played to
 * callers waiting in its queues and to calls its agents put on hold.
 */
class HoldMusicController extends Controller
{
    use AuthorizesAsteriskSettings;

    public function __construct(private TeamHoldMusic $music) {}

    public function index(Request $request): Response
    {
        $team = $this->authorizedTeam($request);

        return Inertia::render('settings/asterisk/HoldMusic', [
            'available' => $this->music->available(),
            'className' => $this->music->className($team),
            'tracks' => $team->holdMusicTracks()->get()->map(fn (HoldMusicTrack $track) => [
                'id' => $track->id,
                'name' => $track->name,
                'size' => $track->size,
                'duration' => $track->duration,
                'uploadedAt' => $track->created_at?->toIso8601String(),
            ])->all(),
            'maxTracks' => (int) config('asterisk.moh.max_tracks', 10),
            'maxUploadKb' => (int) config('asterisk.moh.max_upload_kb', 20480),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->authorizedTeam($request);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('asterisk.moh.max_upload_kb', 20480), 'mimes:wav,mp3,ogg,oga,m4a,aac,flac'],
        ]);

        $this->music->add($team, $request->file('file'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Hold music added.')]);

        return back();
    }

    public function destroy(Request $request, HoldMusicTrack $track): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($track->team_id === $team->id, 404);

        $this->music->remove($track);

        return back();
    }

    /** Preview in the settings page. */
    public function play(Request $request, HoldMusicTrack $track): StreamedResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($track->team_id === $team->id, 404);

        return Storage::disk($track->disk)->response($track->path, $track->name.'.wav', ['Content-Type' => 'audio/wav']);
    }
}
