<?php

namespace App\Services;

use App\Models\HoldMusicTrack;
use App\Models\Team;
use App\Models\User;
use App\Services\Concerns\UsesAsteriskConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Hold music of a team.
 *
 * The files stay in the app; Asterisk plays them from a signed URL through
 * a realtime music-on-hold class `moh_{team key}` in playlist mode
 * (`musiconhold` + one `musiconhold_entry` per file). The class is used by
 * the team's queues (callers waiting) and suggested by its softphones
 * (`moh_suggest`) when an agent puts a call on hold.
 */
class TeamHoldMusic
{
    use UsesAsteriskConnection;

    public function __construct(private AsteriskAmi $ami) {}

    public function className(Team $team): string
    {
        return 'moh_'.$team->asterisk_key;
    }

    public function available(): bool
    {
        try {
            $schema = $this->connection()->getSchemaBuilder();

            return $schema->hasTable('musiconhold') && $schema->hasTable('musiconhold_entry');
        } catch (Throwable) {
            return false;
        }
    }

    /** The URL Asterisk fetches (ends in .wav so it knows the format). */
    public function url(HoldMusicTrack $track): string
    {
        $base = rtrim((string) config('asterisk.moh.base_url'), '/');

        return $base.'/webhooks/asterisk/moh/'.$track->id.'/'.$track->signature().'.wav';
    }

    public function add(Team $team, UploadedFile $file, ?User $user): HoldMusicTrack
    {
        if ($team->holdMusicTracks()->count() >= (int) config('asterisk.moh.max_tracks', 10)) {
            throw ValidationException::withMessages(['file' => __('Remove a track before adding another one.')]);
        }

        $wav = $this->toAsteriskWav($file);
        $disk = (string) config('asterisk.moh.disk', 'local');
        $path = 'moh/'.$team->id.'/'.Str::uuid().'.wav';

        Storage::disk($disk)->put($path, $wav);

        $track = $team->holdMusicTracks()->create([
            'name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 120, ''),
            'disk' => $disk,
            'path' => $path,
            'size' => strlen($wav),
            // 8 kHz × 16-bit mono = 16 000 bytes per second after the 44-byte header.
            'duration' => (int) round(max(0, strlen($wav) - 44) / 16000),
            'position' => (int) $team->holdMusicTracks()->max('position') + 1,
            'uploaded_by' => $user?->id,
        ]);

        $wasEmpty = $team->holdMusicTracks()->count() === 1;
        $this->sync($team, switchQueues: $wasEmpty);

        return $track;
    }

    public function remove(HoldMusicTrack $track): void
    {
        $team = $track->team;

        Storage::disk($track->disk)->delete($track->path);
        $track->delete();

        $this->sync($team);
    }

    /**
     * Write the class and its entries, and point the team's softphones at
     * it; with no file left, remove it and go back to the default music.
     * The first upload also moves the queues still on "default" to it.
     */
    public function sync(Team $team, bool $switchQueues = false): void
    {
        if (! $this->available()) {
            return;
        }

        $class = $this->className($team);
        $tracks = $team->holdMusicTracks()->get();

        $this->connection()->transaction(function () use ($class, $tracks, $team, $switchQueues): void {
            $this->connection()->table('musiconhold_entry')->where('name', $class)->delete();

            if ($tracks->isEmpty()) {
                $this->connection()->table('musiconhold')->where('name', $class)->delete();
                $this->applyToQueues($team, $class, 'default');
                $this->applyToEndpoints($team, 'default');

                return;
            }

            $columns = $this->asteriskColumns('musiconhold');
            $row = array_intersect_key([
                'name' => $class,
                'mode' => 'playlist',
                'sort' => 'random',
            ], array_flip($columns));

            $this->connection()->table('musiconhold')->upsert($row, ['name'], array_keys($row));

            $this->connection()->table('musiconhold_entry')->insert(
                $tracks->values()->map(fn (HoldMusicTrack $track, int $i) => [
                    'name' => $class,
                    'position' => $i + 1,
                    'entry' => $this->url($track),
                ])->all(),
            );

            if ($switchQueues) {
                $this->applyToQueues($team, 'default', $class);
            }

            $this->applyToEndpoints($team, $class);
        });

        // Realtime classes are cached by Asterisk once loaded.
        rescue(fn () => $this->ami->reloadMusicOnHold(), report: false);
    }

    /** A new softphone of the team suggests the team's music too. */
    public function applyToEndpoint(Team $team, string $endpointId): void
    {
        if (! $this->hasMohSuggest()) {
            return;
        }

        $class = $team->holdMusicTracks()->exists() ? $this->className($team) : 'default';

        $this->connection()->table('ps_endpoints')->where('id', $endpointId)->update(['moh_suggest' => $class]);
    }

    public function deleteTeam(Team $team): void
    {
        foreach ($team->holdMusicTracks()->get() as $track) {
            Storage::disk($track->disk)->delete($track->path);
        }

        if ($this->available()) {
            $class = $this->className($team);
            $this->connection()->table('musiconhold_entry')->where('name', $class)->delete();
            $this->connection()->table('musiconhold')->where('name', $class)->delete();
        }
    }

    private function applyToQueues(Team $team, string $from, string $to): void
    {
        $names = $team->callQueues()->pluck('asterisk_name')->all();

        if ($names === []) {
            return;
        }

        $identifier = in_array('name', $this->asteriskColumns('queues'), true) ? 'name' : 'queue_name';

        $this->connection()->table('queues')
            ->whereIn($identifier, $names)
            ->where(fn ($query) => $from === 'default'
                ? $query->whereNull('musiconhold')->orWhere('musiconhold', '')->orWhere('musiconhold', 'default')
                : $query->where('musiconhold', $from))
            ->update(['musiconhold' => $to]);
    }

    private function applyToEndpoints(Team $team, string $class): void
    {
        if (! $this->hasMohSuggest()) {
            return;
        }

        $this->connection()->table('ps_endpoints')
            ->where('id', 'like', $team->asterisk_key.'_%')
            ->update(['moh_suggest' => $class]);
    }

    private function hasMohSuggest(): bool
    {
        return in_array('moh_suggest', $this->asteriskColumns('ps_endpoints'), true);
    }

    /**
     * 8 kHz mono 16-bit PCM WAV, what Asterisk plays without transcoding.
     * Other files are converted with ffmpeg when it is installed.
     */
    private function toAsteriskWav(UploadedFile $file): string
    {
        $content = (string) file_get_contents($file->getRealPath());

        if ($this->isAsteriskWav($content)) {
            return $content;
        }

        $base = (string) tempnam(sys_get_temp_dir(), 'moh');
        $output = $base.'.wav';

        try {
            $process = new Process([
                (string) config('asterisk.moh.ffmpeg', 'ffmpeg'),
                '-hide_banner', '-loglevel', 'error', '-y',
                '-i', $file->getRealPath(),
                '-ac', '1', '-ar', '8000', '-acodec', 'pcm_s16le',
                '-t', '1800',
                $output,
            ]);
            $process->setTimeout(120)->run();

            if (! $process->isSuccessful() || ! is_file($output)) {
                throw ValidationException::withMessages(['file' => __('This file could not be converted. Upload a WAV in 8 kHz, mono, 16 bits.')]);
            }

            return (string) file_get_contents($output);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('Audio conversion is not installed on the server: upload a WAV in 8 kHz, mono, 16 bits.')]);
        } finally {
            @unlink($output);
            @unlink($base);
        }
    }

    private function isAsteriskWav(string $content): bool
    {
        if (strlen($content) < 44 || substr($content, 0, 4) !== 'RIFF' || substr($content, 8, 4) !== 'WAVE') {
            return false;
        }

        $format = unpack('vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr($content, 20, 16));

        return $format !== false
            && $format['format'] === 1
            && $format['channels'] === 1
            && $format['rate'] === 8000
            && $format['bits'] === 16;
    }
}
