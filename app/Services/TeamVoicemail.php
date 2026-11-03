<?php

namespace App\Services;

use App\Models\Team;
use App\Services\Concerns\UsesAsteriskConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Voicemail of a team, in the Asterisk realtime tables:
 *  - `voicemail`: one box per extension, in the voicemail context named
 *    after the team key (so two teams can both have a box 7001);
 *  - `voicemail_messages`: the messages, when Asterisk stores them in the
 *    database (voicemail.conf odbcstorage). Their `dir` is
 *    …/voicemail/{context}/{mailbox}/{folder}.
 *
 * The team dialplan sends unanswered, busy and offline calls to the box
 * when it exists (VM_INFO), and agents dial *97 to listen.
 */
class TeamVoicemail
{
    use UsesAsteriskConnection;

    public function available(): bool
    {
        try {
            return $this->connection()->getSchemaBuilder()->hasTable('voicemail');
        } catch (Throwable) {
            return false;
        }
    }

    public function messagesAvailable(): bool
    {
        try {
            return $this->connection()->getSchemaBuilder()->hasTable('voicemail_messages');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, array{mailbox: string, fullname: ?string, email: ?string, has_pin: bool}> */
    public function boxes(Team $team): array
    {
        $columns = $this->asteriskColumns('voicemail');

        return $this->connection()->table('voicemail')
            ->where('context', $team->asterisk_key)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->mailbox => [
                'mailbox' => (string) $row->mailbox,
                'fullname' => in_array('fullname', $columns, true) ? $row->fullname : null,
                'email' => in_array('email', $columns, true) ? $row->email : null,
                'has_pin' => ($row->password ?? '') !== '',
            ]])
            ->all();
    }

    /**
     * Create or update the box of an extension. A new box needs a PIN; an
     * existing one keeps its PIN when none is given.
     *
     * @param  array{pin?: ?string, fullname?: ?string, email?: ?string}  $data
     */
    public function saveBox(Team $team, string $extension, array $data): void
    {
        $columns = $this->asteriskColumns('voicemail');
        $query = fn () => $this->connection()->table('voicemail')
            ->where('context', $team->asterisk_key)
            ->where('mailbox', $extension);

        $values = array_filter([
            'fullname' => $data['fullname'] ?? null,
            'email' => $data['email'] ?? null,
            // With an email, Asterisk attaches the recording to the notification.
            'attach' => ! empty($data['email']) ? 'yes' : 'no',
        ], fn ($value, $column) => in_array($column, $columns, true), ARRAY_FILTER_USE_BOTH);

        if (! empty($data['pin'])) {
            $values['password'] = (string) $data['pin'];
        }

        if ($query()->exists()) {
            $query()->update($values);

            return;
        }

        if (empty($data['pin'])) {
            throw ValidationException::withMessages(['pin' => __('Choose a PIN for the new voicemail box.')]);
        }

        $this->connection()->table('voicemail')->insert([
            'context' => $team->asterisk_key,
            'mailbox' => $extension,
            ...$values,
        ]);
    }

    public function deleteBox(Team $team, string $extension): void
    {
        if (! $this->available()) {
            return;
        }

        $this->connection()->table('voicemail')
            ->where('context', $team->asterisk_key)
            ->where('mailbox', $extension)
            ->delete();

        if ($this->messagesAvailable()) {
            $this->connection()->table('voicemail_messages')
                ->where('dir', 'like', '%/'.$team->asterisk_key.'/'.$extension.'/%')
                ->delete();
        }
    }

    public function deleteTeam(Team $team): void
    {
        if ($this->available()) {
            $this->connection()->table('voicemail')->where('context', $team->asterisk_key)->delete();
        }

        if ($this->messagesAvailable()) {
            $this->connection()->table('voicemail_messages')->where('dir', 'like', '%/'.$team->asterisk_key.'/%')->delete();
        }
    }

    /**
     * Messages of the team (or of one box), newest first, without audio.
     *
     * @return list<array{id: string, mailbox: string, folder: string, msgnum: int, callerid: string, received_at: ?string, duration: int, size: int}>
     */
    public function messages(Team $team, ?string $mailbox = null): array
    {
        if (! $this->messagesAvailable()) {
            return [];
        }

        $columns = $this->asteriskColumns('voicemail_messages');
        $length = $this->connection()->getDriverName() === 'pgsql' ? 'octet_length(recording)' : 'length(recording)';

        return $this->connection()->table('voicemail_messages')
            ->where('dir', 'like', '%/'.$team->asterisk_key.'/'.($mailbox !== null ? $mailbox.'/' : '').'%')
            ->select(array_values(array_intersect(['dir', 'msgnum', 'callerid', 'origtime', 'duration'], $columns)))
            ->selectRaw($length.' as size')
            ->orderByDesc(in_array('origtime', $columns, true) ? 'origtime' : 'msgnum')
            ->limit(200)
            ->get()
            ->map(function ($row) use ($team) {
                $parsed = $this->parseDir((string) $row->dir);

                if ($parsed === null || $parsed['context'] !== $team->asterisk_key) {
                    return null;
                }

                $time = isset($row->origtime) && is_numeric($row->origtime) ? (int) $row->origtime : null;

                return [
                    'id' => base64_encode($row->dir.'|'.$row->msgnum),
                    'mailbox' => $parsed['mailbox'],
                    'folder' => $parsed['folder'],
                    'msgnum' => (int) $row->msgnum,
                    'callerid' => (string) ($row->callerid ?? ''),
                    'received_at' => $time !== null ? Date::createFromTimestamp($time)->toIso8601String() : null,
                    'duration' => (int) ($row->duration ?? 0),
                    'size' => (int) ($row->size ?? 0),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** The recording of one message of the team (WAV, as Asterisk stored it). */
    public function recording(Team $team, string $id): ?string
    {
        [$dir, $msgnum] = $this->decode($team, $id);

        $value = $this->connection()->table('voicemail_messages')
            ->where('dir', $dir)
            ->where('msgnum', $msgnum)
            ->value('recording');

        return is_resource($value) ? (string) stream_get_contents($value) : ($value !== null ? (string) $value : null);
    }

    public function deleteMessage(Team $team, string $id): void
    {
        [$dir, $msgnum] = $this->decode($team, $id);

        $this->connection()->table('voicemail_messages')
            ->where('dir', $dir)
            ->where('msgnum', $msgnum)
            ->delete();
    }

    /** @return array{0: string, 1: int} */
    private function decode(Team $team, string $id): array
    {
        $decoded = (string) base64_decode($id, true);
        [$dir, $msgnum] = array_pad(explode('|', $decoded, 2), 2, '');
        $parsed = $this->parseDir($dir);

        // Only this team's messages: the id comes from the browser.
        abort_if($parsed === null || $parsed['context'] !== $team->asterisk_key || ! is_numeric($msgnum), 404);

        return [$dir, (int) $msgnum];
    }

    /** @return array{context: string, mailbox: string, folder: string}|null */
    private function parseDir(string $dir): ?array
    {
        if (preg_match('#/([^/]+)/([^/]+)/([^/]+)/?$#', $dir, $m) !== 1) {
            return null;
        }

        return ['context' => $m[1], 'mailbox' => $m[2], 'folder' => $m[3]];
    }
}
