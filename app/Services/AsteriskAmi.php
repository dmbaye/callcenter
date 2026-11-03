<?php

namespace App\Services;

use RuntimeException;

/**
 * Client for the long-lived Asterisk AMI listener.
 *
 * HTTP requests never open an AMI TCP connection. They use the local Unix
 * socket exposed by `asterisk:ami-listen`, while the daemon keeps the AMI
 * connection alive and continuously refreshes the live snapshot.
 */
class AsteriskAmi
{
    /**
     * @return array{channels: list<array<string, string>>, queues: array{entries: list<array<string, string>>, queues: array<string, array<string, string>>, updated_at: int}}
     */
    public function snapshot(): array
    {
        return $this->publishedSnapshot() ?? $this->request('snapshot')['snapshot'];
    }

    /**
     * The snapshot file the listener rewrites after each refresh (and
     * touches every 2 s while alive). Reading it costs no IPC round trip, so
     * the dialer, the monitoring page and the agent desktop never queue
     * behind each other on the socket. Null = missing or stale: ask the daemon.
     *
     * @return array<string, mixed>|null
     */
    private function publishedSnapshot(): ?array
    {
        $file = (string) config('asterisk.ami.snapshot_file');

        if ($file === '' || ! is_file($file)) {
            return null;
        }

        clearstatcache(true, $file);
        $maxAge = max(10, 3 * (int) config('asterisk.ami.snapshot_interval', 2));

        if (time() - (int) @filemtime($file) > $maxAge) {
            return null;
        }

        $contents = @file_get_contents($file);
        $snapshot = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($snapshot) && isset($snapshot['channels'], $snapshot['queues']) ? $snapshot : null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function coreShowChannels(): array
    {
        return $this->snapshot()['channels'];
    }

    /**
     * @param  list<string>|null  $queues
     * @return array{entries: list<array<string, string>>, queues: array<string, array<string, string>>}
     */
    public function queueStatus(?array $queues = null): array
    {
        $snapshot = $this->snapshot();
        $state = $snapshot['queues'];

        if ($queues === null) {
            return $state;
        }

        $names = array_fill_keys($queues, true);

        return [
            'entries' => array_values(array_filter(
                $state['entries'],
                fn (array $entry) => isset($names[$entry['Queue'] ?? '']),
            )),
            'queues' => array_intersect_key($state['queues'], $names),
            'updated_at' => $state['updated_at'] ?? 0,
        ];
    }

    public function startSpy(
        string $supervisorExtension,
        string $targetExtension,
        bool $whisper = false,
        ?string $asteriskKey = null,
    ): void {
        $this->request('start_spy', [
            'supervisor_extension' => self::safeExtension($supervisorExtension),
            'target_extension' => self::safeExtension($targetExtension),
            'asterisk_key' => $asteriskKey !== null ? self::safeAsteriskKey($asteriskKey) : null,
            'whisper' => $whisper,
        ]);
    }

    /**
     * @return array{channel: string, bridged_channel: string}|null
     */
    public function activeChannelForExtension(string $extension, ?string $asteriskKey = null): ?array
    {
        return $this->findActiveChannel($this->coreShowChannels(), $extension, $asteriskKey);
    }

    /**
     * @param  array<int, array<string, string>>  $channels
     * @return array{channel: string, bridged_channel: string}|null
     */
    public function findActiveChannel(array $channels, string $extension, ?string $asteriskKey = null): ?array
    {
        $endpoint = $asteriskKey !== null
            ? self::safeAsteriskKey($asteriskKey).'_'.self::safeExtension($extension)
            : self::safeExtension($extension);
        $prefix = 'PJSIP/'.$endpoint.'-';

        $candidates = array_values(array_filter(
            $channels,
            fn (array $channel) => str_starts_with($channel['Channel'] ?? '', $prefix)
                && ($channel['ChannelStateDesc'] ?? '') === 'Up',
        ));

        if ($candidates === []) {
            return null;
        }

        $channel = $candidates[0];

        foreach ($candidates as $candidate) {
            if (($candidate['BridgeId'] ?? '') !== '' || ($candidate['BridgedChannel'] ?? '') !== '') {
                $channel = $candidate;
                break;
            }
        }

        $peer = $channel['BridgedChannel'] ?? '';

        if ($peer === '' && ($channel['BridgeId'] ?? '') !== '') {
            foreach ($channels as $other) {
                if (($other['BridgeId'] ?? null) === $channel['BridgeId']
                    && ($other['Channel'] ?? null) !== $channel['Channel']) {
                    $peer = $other['Channel'];
                    break;
                }
            }
        }

        return ['channel' => $channel['Channel'], 'bridged_channel' => $peer];
    }

    public function blindTransfer(string $channel, string $exten, string $context): void
    {
        $this->request('blind_transfer', [
            'channel' => $channel,
            'exten' => $exten,
            'context' => $context,
        ]);
    }

    /**
     * Originate a campaign call into the campaign context (async: the result
     * arrives later as AMI events, handled by CampaignCallEvents).
     *
     * @param  array{attempt_id: int, channel: string, exten: string, caller_id: string, account: string, timeout: int, variables: array<string, string|int>}  $call
     */
    public function originateCampaignCall(array $call): void
    {
        $this->request('originate_campaign', $call);
    }

    public function reloadDialplan(): void
    {
        $this->request('reload_dialplan');
    }

    /** Asterisk caches realtime music-on-hold classes once loaded. */
    public function reloadMusicOnHold(): void
    {
        $this->request('reload_moh');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function request(string $command, array $arguments = []): array
    {
        $path = (string) config('asterisk.ami.socket');

        if ($path === '') {
            throw new RuntimeException('Asterisk AMI listener socket is not configured.');
        }

        $socket = @stream_socket_client(
            'unix://'.$path,
            $errno,
            $error,
            (float) config('asterisk.ami.ipc_timeout', 5),
        );

        if (! is_resource($socket)) {
            throw new RuntimeException(
                'Asterisk AMI listener is unavailable. Start `php artisan asterisk:ami-listen`.'
            );
        }

        stream_set_timeout($socket, (int) config('asterisk.ami.ipc_timeout', 5));

        try {
            fwrite($socket, json_encode([
                'command' => $command,
                'arguments' => $arguments,
            ], JSON_THROW_ON_ERROR)."\n");

            $line = fgets($socket);

            if ($line === false) {
                throw new RuntimeException('Asterisk AMI listener closed the IPC connection.');
            }

            $response = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

            if (! ($response['ok'] ?? false)) {
                throw new RuntimeException((string) ($response['error'] ?? 'Asterisk AMI listener request failed.'));
            }

            return $response;
        } catch (\JsonException $exception) {
            throw new RuntimeException('Invalid response from Asterisk AMI listener.', 0, $exception);
        } finally {
            fclose($socket);
        }
    }

    private static function safeAsteriskKey(string $key): string
    {
        if (! preg_match('/^[a-z0-9]{10}$/', $key)) {
            throw new RuntimeException('Invalid Asterisk tenant key.');
        }

        return $key;
    }

    private static function safeExtension(string $extension): string
    {
        if (! preg_match('/^\d{2,20}$/', $extension)) {
            throw new RuntimeException('Invalid SIP extension.');
        }

        return $extension;
    }
}
