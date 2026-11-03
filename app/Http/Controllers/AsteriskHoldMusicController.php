<?php

namespace App\Http\Controllers;

use App\Models\HoldMusicTrack;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hold-music files fetched by Asterisk (res_http_media_cache) from the
 * playlist entries of a team's class. No session: the URL carries an HMAC
 * of the track, so only URLs written by the app work.
 */
class AsteriskHoldMusicController extends Controller
{
    // The webhooks group has no route-model binding: the id is looked up here.
    public function __invoke(string $track, string $signature): StreamedResponse
    {
        $track = HoldMusicTrack::query()->findOrFail((int) $track);

        abort_unless(hash_equals($track->signature(), $signature), 404);
        abort_unless(Storage::disk($track->disk)->exists($track->path), 404);

        return Storage::disk($track->disk)->response($track->path, 'moh-'.$track->id.'.wav', [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
