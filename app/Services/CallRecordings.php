<?php

namespace App\Services;

use App\Models\CallRecording;
use App\Models\Team;
use App\Models\User;
use App\Services\Concerns\UsesAsteriskConnection;
use Illuminate\Http\UploadedFile;

/**
 * Call recordings: storing uploads, finding them for CDR rows, deciding
 * who may listen, and retention.
 */
class CallRecordings
{
    use UsesAsteriskConnection;

    public function store(Team $team, string $uniqueid, ?string $linkedid, UploadedFile $file): CallRecording
    {
        $disk = (string) config('asterisk.recordings.disk', 'local');
        $directory = 'recordings/'.$team->id.'/'.now()->format('Y/m');
        $name = str_replace('.', '-', $uniqueid).'.wav';
        $path = $file->storeAs($directory, $name, $disk);

        $existing = $team->callRecordings()->where('uniqueid', $uniqueid)->first();

        // Re-upload of the same call (the script retries): replace the old file.
        if ($existing !== null && ($existing->disk !== $disk || $existing->path !== $path)) {
            $existing->delete();
            $existing = null;
        }

        $attributes = [
            'linkedid' => $linkedid !== '' ? $linkedid : null,
            'disk' => $disk,
            'path' => (string) $path,
            'size' => (int) $file->getSize(),
            'duration' => self::wavDuration((string) $file->getRealPath()),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return $team->callRecordings()->create(['uniqueid' => $uniqueid, ...$attributes]);
    }

    /**
     * Recordings for a page of CDR rows, keyed by the row's uniqueid. A row
     * matches its own uniqueid first, then any recording of the same call.
     *
     * @param  iterable<object>  $rows  CDR rows with uniqueid and linkedid
     * @return array<string, CallRecording>
     */
    public function forCdrRows(Team $team, iterable $rows): array
    {
        $rows = collect($rows);
        $ids = $rows->pluck('uniqueid')->merge($rows->pluck('linkedid'))->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $found = $team->callRecordings()
            ->where(fn ($q) => $q->whereIn('uniqueid', $ids)->orWhereIn('linkedid', $ids))
            ->get();

        $byUniqueid = $found->keyBy('uniqueid');
        $byLinkedid = $found->filter(fn (CallRecording $r) => $r->linkedid !== null)->groupBy('linkedid');

        $matches = [];

        foreach ($rows as $row) {
            $recording = $byUniqueid->get((string) $row->uniqueid)
                ?? $byLinkedid->get((string) $row->linkedid)?->first()
                ?? $byUniqueid->get((string) $row->linkedid);

            if ($recording !== null) {
                $matches[(string) $row->uniqueid] = $recording;
            }
        }

        return $matches;
    }

    /**
     * Supervisors and up hear every recording of the team; an agent only
     * the calls their own extension took part in.
     */
    public function canListen(User $user, Team $team, CallRecording $recording, bool $canViewTeam): bool
    {
        if ($recording->team_id !== $team->id) {
            return false;
        }

        if ($canViewTeam) {
            return true;
        }

        $extension = $user->phoneExtensionFor($team)?->extension;

        if ($extension === null) {
            return false;
        }

        $leg = 'PJSIP/'.$team->asterisk_key.'_'.$extension.'-%';
        $ids = array_values(array_filter([$recording->uniqueid, $recording->linkedid]));

        return $this->connection()->table('cdr')
            ->where('accountcode', $team->asterisk_key)
            ->where(fn ($q) => $q->whereIn('uniqueid', $ids)->orWhereIn('linkedid', $ids))
            ->where(fn ($q) => $q->where('channel', 'like', $leg)->orWhere('dstchannel', 'like', $leg))
            ->exists();
    }

    /** Days a team keeps its recordings (fallback after a downgrade). */
    public function retentionDays(Team $team): int
    {
        $plan = $team->plan();

        return $plan->allows('call_recording')
            ? max(1, (int) ($plan->limits['recording_days'] ?? 0))
            : (int) config('asterisk.recordings.fallback_days', 30);
    }

    /** Delete recordings older than each team's retention. Returns the count. */
    public function prune(): int
    {
        $deleted = 0;

        Team::query()
            ->whereHas('callRecordings')
            ->each(function (Team $team) use (&$deleted) {
                $team->callRecordings()
                    ->where('created_at', '<', now()->subDays($this->retentionDays($team)))
                    ->each(function (CallRecording $recording) use (&$deleted) {
                        $recording->delete();
                        $deleted++;
                    });
            });

        return $deleted;
    }

    /** Length in seconds from a WAV header, or null if it is not a plain WAV. */
    public static function wavDuration(string $path): ?int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = (string) fread($handle, 12);

            if (strlen($header) < 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
                return null;
            }

            $byteRate = null;

            while (! feof($handle)) {
                $chunk = (string) fread($handle, 8);

                if (strlen($chunk) < 8) {
                    return null;
                }

                $id = substr($chunk, 0, 4);
                $size = unpack('V', substr($chunk, 4, 4))[1];

                if ($id === 'fmt ') {
                    $fmt = (string) fread($handle, $size);
                    $byteRate = unpack('V', substr($fmt, 8, 4))[1] ?? null;

                    continue;
                }

                if ($id === 'data') {
                    // Asterisk may leave the size at 0 while writing: use the file size.
                    $dataSize = $size > 0 ? $size : max(0, filesize($path) - ftell($handle));

                    return $byteRate ? (int) round($dataSize / $byteRate) : null;
                }

                fseek($handle, $size + ($size % 2), SEEK_CUR);
            }

            return null;
        } finally {
            fclose($handle);
        }
    }
}
