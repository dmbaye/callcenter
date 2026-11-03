<?php

namespace App\Services;

use RuntimeException;

class AsteriskAmiListener
{
    private $ami = null;

    private $server = null;

    /** @var array{channels: list<array<string,string>>, queues: array{entries:list<array<string,string>>,queues:array<string,array<string,string>>,updated_at:int}} */
    private array $snapshot = [
        'channels' => [],
        'queues' => ['entries' => [], 'queues' => [], 'updated_at' => 0],
    ];

    private int $lastRefresh = 0;

    private float $lastRefreshAt = 0.0;

    /** An interesting AMI event arrived since the last snapshot refresh. */
    private bool $dirty = false;

    /** @var callable(list<string>): void|null */
    private $onChange = null;

    /** @var callable(array<string,string>): void|null */
    private $onCampaignEvent = null;

    /** @var list<array<string,string>> campaign events captured while reading, handled in the main loop */
    private array $campaignEvents = [];

    private ?MonitoringChangeDetector $detector = null;

    /** Where the snapshot is published for readers (no IPC round trip needed). */
    private string $snapshotFile = '';

    private float $lastSnapshotWrite = 0.0;

    private float $lastHangupLog = 0.0;

    /** A step of the loop that took longer than this is logged: it delays every IPC client. */
    private const SLOW_STEP_SECONDS = 1.0;

    /** Clients served per loop round at most (the rest wait for the next round). */
    private const MAX_CLIENTS_PER_ROUND = 50;

    /** AMI events that can change what the monitoring screen shows. */
    private const RELEVANT_EVENTS = [
        'Newstate', 'Hangup', 'DialBegin', 'DialEnd', 'BridgeEnter', 'BridgeLeave',
        'QueueCallerJoin', 'QueueCallerLeave', 'QueueCallerAbandon',
        'QueueMemberAdded', 'QueueMemberRemoved', 'QueueMemberPause', 'QueueMemberStatus',
        'AgentConnect', 'AgentComplete', 'AgentRingNoAnswer',
    ];

    /**
     * @param  callable(list<string>): void|null  $onChange  called with the tenant keys whose live state changed
     * @param  callable(array<string,string>): void|null  $onCampaignEvent  called with call-center events (originate results, dialplan UserEvents, AgentCalled/AgentConnect)
     */
    public function run(int $intervalSeconds = 2, ?string $socketPath = null, ?callable $log = null, ?callable $onChange = null, ?callable $onCampaignEvent = null): void
    {
        $this->onChange = $onChange;
        $this->onCampaignEvent = $onCampaignEvent;
        $this->detector = new MonitoringChangeDetector;

        $socketPath ??= (string) config('asterisk.ami.socket');

        if ($socketPath === '') {
            throw new RuntimeException('Asterisk AMI listener socket is not configured.');
        }

        $this->prepareServer($socketPath);
        $log ??= static fn (string $message) => fwrite(STDOUT, $message.PHP_EOL);
        $this->snapshotFile = (string) config('asterisk.ami.snapshot_file');

        try {
            $this->connectAmi($log);

            while (true) {
                if (! is_resource($this->ami)) {
                    $this->connectAmi($log);
                }

                $this->timed('AMI snapshot refresh', fn () => $this->refreshIfDue($intervalSeconds, $log), $log);
                $this->keepSnapshotFresh();

                $read = [$this->server];
                if (is_resource($this->ami)) {
                    $read[] = $this->ami;
                }

                $write = null;
                $except = null;

                // 250 ms ticks: short enough for the event debounce below.
                if (@stream_select($read, $write, $except, 0, 250000) === false) {
                    continue;
                }

                // Clients first: they wait with a timeout, AMI events do not.
                if (in_array($this->server, $read, true)) {
                    $this->handleClients($log);
                }

                if (is_resource($this->ami) && in_array($this->ami, $read, true)) {
                    $this->timed('AMI event read', fn () => $this->drainEvents($log), $log);
                }

                $this->timed('Campaign/inbound event handling', fn () => $this->flushCampaignEvents($log), $log);
            }
        } finally {
            $this->closeAmi();
            if (is_resource($this->server)) {
                fclose($this->server);
            }
            @unlink($socketPath);

            // Daemon gone: readers must not take the last snapshot for live data.
            if ($this->snapshotFile !== '') {
                @unlink($this->snapshotFile);
            }
        }
    }

    /** Run a loop step and log it when it blocks the loop for too long. */
    private function timed(string $step, callable $callback, callable $log): void
    {
        $started = microtime(true);
        $callback();
        $elapsed = microtime(true) - $started;

        if ($elapsed >= self::SLOW_STEP_SECONDS) {
            $log(sprintf('%s took %.1f s (requests to the listener wait meanwhile).', $step, $elapsed));
        }
    }

    /**
     * Publish the snapshot atomically (write + rename): web requests and the
     * dialer read it from disk instead of queueing on the IPC socket.
     */
    private function writeSnapshotFile(): void
    {
        if ($this->snapshotFile === '') {
            return;
        }

        $temporary = $this->snapshotFile.'.'.getmypid().'.tmp';

        try {
            if (@file_put_contents($temporary, json_encode($this->snapshot, JSON_THROW_ON_ERROR)) !== false) {
                @chmod($temporary, 0640);
                @rename($temporary, $this->snapshotFile);
            }
        } catch (\JsonException) {
            // Keep the previous file.
        } finally {
            @unlink($temporary);
        }

        $this->lastSnapshotWrite = microtime(true);
    }

    /** Touch the file while the daemon lives, even when nothing changed (readers check its age). */
    private function keepSnapshotFresh(): void
    {
        if ($this->snapshotFile === '' || microtime(true) - $this->lastSnapshotWrite < 2) {
            return;
        }

        is_file($this->snapshotFile) ? @touch($this->snapshotFile) : $this->writeSnapshotFile();
        $this->lastSnapshotWrite = microtime(true);
    }

    private function prepareServer(string $socketPath): void
    {
        $directory = dirname($socketPath);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create AMI socket directory [{$directory}].");
        }

        @unlink($socketPath);

        $server = @stream_socket_server(
            'unix://'.$socketPath,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if (! is_resource($server)) {
            throw new RuntimeException("Unable to create AMI listener socket: {$error}");
        }

        chmod($socketPath, 0660);
        stream_set_blocking($server, false);
        $this->server = $server;
    }

    private function connectAmi(callable $log): void
    {
        $this->closeAmi();

        $host = (string) config('asterisk.ami.host');
        $port = (int) config('asterisk.ami.port', 5038);
        $username = (string) config('asterisk.ami.username');
        $secret = (string) config('asterisk.ami.secret');

        if ($host === '' || $username === '' || $secret === '') {
            throw new RuntimeException('Asterisk AMI is not configured.');
        }

        $errno = 0;
        $error = '';
        $socket = @fsockopen($host, $port, $errno, $error, 3);

        if (! is_resource($socket)) {
            $log("AMI connection failed: {$error}; retrying.");
            sleep(2);

            return;
        }

        stream_set_timeout($socket, 3);
        $this->ami = $socket;
        $this->readMessage($socket); // banner

        $actionId = $this->actionId('login');
        $this->send($socket, [
            'Action' => 'Login',
            'ActionID' => $actionId,
            'Username' => $username,
            'Secret' => $secret,
            'Events' => (string) config('asterisk.ami.events', 'call,agent'),
        ]);

        $login = $this->readUntilAction($socket, $actionId);

        if (($login['Response'] ?? null) !== 'Success') {
            $this->closeAmi();
            throw new RuntimeException($login['Message'] ?? 'Asterisk AMI login failed.');
        }

        // Fresh connection: take a full snapshot right away.
        $this->dirty = true;
        $this->lastRefreshAt = 0.0;

        $log('Asterisk AMI listener connected.');
    }

    private function refreshIfDue(int $intervalSeconds, callable $log): void
    {
        if (! is_resource($this->ami)) {
            return;
        }

        $now = microtime(true);
        $debounce = max(50, (int) config('asterisk.ami.debounce_ms', 300)) / 1000;

        $eventDue = $this->dirty && $now - $this->lastRefreshAt >= $debounce;
        $periodicDue = time() - $this->lastRefresh >= $intervalSeconds;

        if (! $eventDue && ! $periodicDue) {
            return;
        }

        $this->dirty = false;

        try {
            $this->snapshot['channels'] = $this->requestChannels();
            $this->snapshot['queues'] = $this->requestQueues();
            $this->snapshot['queues']['updated_at'] = time();
            $this->lastRefresh = time();
            $this->lastRefreshAt = microtime(true);
            $this->writeSnapshotFile();
        } catch (\Throwable $exception) {
            $log('AMI snapshot refresh failed: '.$exception->getMessage());
            $this->closeAmi();

            return;
        }

        $this->timed('Monitoring push (Reverb)', fn () => $this->pushChanges($log), $log);
    }

    /** Tell listeners which tenants changed; a failing push must never stop the daemon. */
    private function pushChanges(callable $log): void
    {
        $changed = $this->detector?->changedKeys($this->snapshot) ?? [];

        if ($changed === [] || $this->onChange === null) {
            return;
        }

        try {
            ($this->onChange)($changed);
        } catch (\Throwable $exception) {
            $log('Monitoring push failed: '.$exception->getMessage());
        }
    }

    /** Consume pending AMI events (they only mark the snapshot as dirty). */
    private function drainEvents(callable $log): void
    {
        try {
            do {
                $this->readMessage($this->ami);
                $meta = is_resource($this->ami) ? stream_get_meta_data($this->ami) : ['unread_bytes' => 0, 'eof' => true];
            } while (($meta['unread_bytes'] ?? 0) > 0);

            if (! is_resource($this->ami) || feof($this->ami)) {
                $log('AMI connection closed; reconnecting.');
                $this->closeAmi();
            }
        } catch (\Throwable $exception) {
            $log('AMI event read failed: '.$exception->getMessage());
            $this->closeAmi();
        }
    }

    /**
     * Campaign call events are captured wherever they are read (they can
     * arrive in the middle of another action's response) and handled after.
     *
     * @param  array<string,string>  $message
     */
    private function captureCampaignEvent(array $message): void
    {
        if ($this->onCampaignEvent === null) {
            return;
        }

        $event = $message['Event'] ?? null;

        $relevant = ($event === 'OriginateResponse' && str_starts_with($message['ActionID'] ?? '', 'cc-camp-'))
            || ($event === 'UserEvent' && in_array($message['UserEvent'] ?? '', ['CcCampaign', 'CcCampaignEnd', 'CcInbound', 'CcCallback', 'CcSurvey'], true))
            || $event === 'AgentConnect'
            || $event === 'AgentCalled';

        if ($relevant) {
            $this->campaignEvents[] = $message;
        }
    }

    private function flushCampaignEvents(callable $log): void
    {
        while (($event = array_shift($this->campaignEvents)) !== null) {
            try {
                ($this->onCampaignEvent)($event);
            } catch (\Throwable $exception) {
                $log('Campaign event failed: '.$exception->getMessage());
            }
        }
    }

    /** @param array<string,string> $message */
    private function noteEvent(array $message): void
    {
        $this->captureCampaignEvent($message);

        if (isset($message['Event']) && ! isset($message['ActionID']) && in_array($message['Event'], self::RELEVANT_EVENTS, true)) {
            $this->dirty = true;
        }
    }

    /** Serve every client already waiting, not one per loop round. */
    private function handleClients(callable $log): void
    {
        for ($i = 0; $i < self::MAX_CLIENTS_PER_ROUND; $i++) {
            $client = @stream_socket_accept($this->server, 0);

            if (! is_resource($client)) {
                return;
            }

            $this->handleClient($client, $log);
        }
    }

    private function handleClient($client, callable $log): void
    {
        stream_set_timeout($client, (int) config('asterisk.ami.ipc_timeout', 5));

        try {
            $line = fgets($client);

            if ($line === false) {
                return; // Client already gone.
            }

            $request = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            $command = (string) ($request['command'] ?? '');

            try {
                $reply = ['ok' => true, ...$this->dispatch($command, (array) ($request['arguments'] ?? []))];
            } catch (\Throwable $exception) {
                $log("AMI IPC request [{$command}] failed: ".$exception->getMessage());
                $reply = ['ok' => false, 'error' => $exception->getMessage()];
            }

            $this->reply($client, $reply, $command, $log);
        } catch (\JsonException) {
            $this->reply($client, ['ok' => false, 'error' => 'Invalid AMI listener request.'], 'invalid', $log);
        } finally {
            @fclose($client);
        }
    }

    /**
     * The client may have hung up (its timeout passed while the loop was
     * busy): that is not an error of the request, just say so once a minute.
     *
     * @param  array<string,mixed>  $reply
     */
    private function reply($client, array $reply, string $command, callable $log): void
    {
        $payload = json_encode($reply, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";

        if (@fwrite($client, $payload) === false && microtime(true) - $this->lastHangupLog >= 60) {
            $this->lastHangupLog = microtime(true);
            $log("IPC client left before the [{$command}] reply (it waits ASTERISK_AMI_IPC_TIMEOUT s); the listener was busy, see slow steps above.");
        }
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function dispatch(string $command, array $arguments): array
    {
        return match ($command) {
            'snapshot' => ['snapshot' => $this->snapshot],
            'start_spy' => $this->startSpy($arguments),
            'blind_transfer' => $this->blindTransfer($arguments),
            'reload_dialplan' => $this->reloadDialplan(),
            'reload_moh' => $this->runCommand('moh reload'),
            'originate_campaign' => $this->originateCampaign($arguments),
            default => throw new RuntimeException("Unknown AMI listener command [{$command}]."),
        };
    }

    private function startSpy(array $arguments): array
    {
        $supervisor = $this->safeEndpoint((string) ($arguments['asterisk_key'] ?? ''), (string) ($arguments['supervisor_extension'] ?? ''));
        $target = $this->safeEndpoint((string) ($arguments['asterisk_key'] ?? ''), (string) ($arguments['target_extension'] ?? ''));
        $whisper = (bool) ($arguments['whisper'] ?? false);

        $this->send($this->ami, [
            'Action' => 'Originate',
            'ActionID' => $id = $this->actionId('spy'),
            'Channel' => 'PJSIP/'.$supervisor,
            'Context' => config('asterisk.ami.monitor_context', 'supervisor-monitor'),
            'Exten' => config('asterisk.ami.monitor_extension', 'spy'),
            'Priority' => '1',
            'Timeout' => (string) config('asterisk.ami.originate_timeout', 30000),
            'CallerID' => config('asterisk.ami.monitor_caller_id', 'Supervisor Monitor'),
            'Variable' => 'SPY_TARGET='.$target.',SPY_WHISPER='.($whisper ? '1' : '0'),
            'Async' => 'true',
        ]);

        $response = $this->readUntilAction($this->ami, $id);

        if (($response['Response'] ?? null) !== 'Success') {
            throw new RuntimeException($response['Message'] ?? 'Asterisk rejected the monitoring request.');
        }

        return [];
    }

    private function blindTransfer(array $arguments): array
    {
        $this->send($this->ami, [
            'Action' => 'BlindTransfer',
            'ActionID' => $id = $this->actionId('transfer'),
            'Channel' => (string) ($arguments['channel'] ?? ''),
            'Exten' => (string) ($arguments['exten'] ?? ''),
            'Context' => (string) ($arguments['context'] ?? ''),
        ]);

        $response = $this->readUntilAction($this->ami, $id);

        if (($response['Response'] ?? null) !== 'Success') {
            throw new RuntimeException($response['Message'] ?? 'Asterisk rejected the transfer.');
        }

        return [];
    }

    /**
     * Campaign calls may only enter the campaign context, through a channel
     * built by the dialer; variables are restricted to CC_* names.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function originateCampaign(array $arguments): array
    {
        $attemptId = (int) ($arguments['attempt_id'] ?? 0);
        $channel = (string) ($arguments['channel'] ?? '');
        $exten = (string) ($arguments['exten'] ?? '');

        if ($attemptId <= 0) {
            throw new RuntimeException('Invalid campaign attempt.');
        }
        if (! preg_match('#^(PJSIP|SIP|IAX2|DAHDI)/[\w.+@*\#-]{1,120}$#', $channel)) {
            throw new RuntimeException('Invalid campaign channel.');
        }
        if (! in_array($exten, ['s', 'preview'], true)) {
            throw new RuntimeException('Invalid campaign extension.');
        }

        $variables = [];
        foreach ((array) ($arguments['variables'] ?? []) as $name => $value) {
            if (! preg_match('/^CC_[A-Z_]{1,30}$/', (string) $name)) {
                throw new RuntimeException('Invalid campaign variable.');
            }
            // Commas separate variables and new lines would inject AMI headers.
            $variables[] = $name.'='.str_replace([',', "\r", "\n", '"'], ' ', (string) $value);
        }

        $this->send($this->ami, [
            'Action' => 'Originate',
            'ActionID' => $id = 'cc-camp-'.$attemptId.'-'.bin2hex(random_bytes(4)),
            'Channel' => $channel,
            'Context' => (string) config('asterisk.campaigns.context', 'cc-campaign'),
            'Exten' => $exten,
            'Priority' => '1',
            'Timeout' => (string) max(5000, min(120000, (int) ($arguments['timeout'] ?? 30000))),
            'CallerID' => str_replace(["\r", "\n"], '', (string) ($arguments['caller_id'] ?? '')),
            'Account' => preg_replace('/[^a-z0-9]/', '', (string) ($arguments['account'] ?? '')),
            'Variable' => implode(',', $variables),
            'Async' => 'true',
        ]);

        $response = $this->readUntilAction($this->ami, $id);

        if (($response['Response'] ?? null) !== 'Success') {
            throw new RuntimeException($response['Message'] ?? 'Asterisk rejected the campaign call.');
        }

        return [];
    }

    /** A fixed CLI command (never built from a request). */
    private function runCommand(string $command): array
    {
        $this->send($this->ami, [
            'Action' => 'Command',
            'ActionID' => $id = $this->actionId('command'),
            'Command' => $command,
        ]);

        $this->readUntilAction($this->ami, $id);

        return [];
    }

    private function reloadDialplan(): array
    {
        $this->send($this->ami, [
            'Action' => 'Command',
            'ActionID' => $id = $this->actionId('reload'),
            'Command' => 'dialplan reload',
        ]);

        $this->readUntilAction($this->ami, $id);

        return [];
    }

    /** @return list<array<string,string>> */
    private function requestChannels(): array
    {
        $this->send($this->ami, [
            'Action' => 'CoreShowChannels',
            'ActionID' => $id = $this->actionId('channels'),
        ]);

        $channels = [];

        while (($event = $this->readMessage($this->ami)) !== null) {
            if (($event['ActionID'] ?? null) !== $id) {
                continue;
            }
            if (($event['Response'] ?? null) === 'Error') {
                throw new RuntimeException($event['Message'] ?? 'Asterisk rejected the channel list request.');
            }
            if (($event['Event'] ?? null) === 'CoreShowChannel') {
                $channels[] = $event;
            }
            if (($event['Event'] ?? null) === 'CoreShowChannelsComplete') {
                break;
            }
        }

        return $channels;
    }

    /** @return array{entries:list<array<string,string>>,queues:array<string,array<string,string>>,updated_at:int} */
    private function requestQueues(): array
    {
        $this->send($this->ami, [
            'Action' => 'QueueStatus',
            'ActionID' => $id = $this->actionId('queues'),
        ]);

        $entries = [];
        $params = [];
        $members = [];

        while (($event = $this->readMessage($this->ami)) !== null) {
            if (($event['ActionID'] ?? null) !== $id) {
                continue;
            }
            if (($event['Response'] ?? null) === 'Error') {
                throw new RuntimeException($event['Message'] ?? 'Asterisk rejected the queue status request.');
            }

            $name = $event['Queue'] ?? null;
            match ($event['Event'] ?? null) {
                'QueueParams' => $name !== null ? $params[$name] = $event : null,
                'QueueEntry' => $name !== null ? $entries[] = $event : null,
                'QueueMember' => $name !== null ? $members[] = $event : null,
                default => null,
            };

            if (($event['Event'] ?? null) === 'QueueStatusComplete') {
                break;
            }
        }

        return ['entries' => $entries, 'queues' => $params, 'members' => $members, 'updated_at' => time()];
    }

    private function send($socket, array $fields): void
    {
        $payload = collect($fields)
            ->map(fn ($value, $key) => $key.': '.$value)
            ->implode("\r\n")."\r\n\r\n";

        if (@fwrite($socket, $payload) === false) {
            throw new RuntimeException('Unable to write to Asterisk AMI.');
        }
    }

    /** @return array<string,string>|null */
    private function readMessage($socket): ?array
    {
        $message = [];

        while (($line = fgets($socket)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($message === []) {
                    return null;
                }

                $this->noteEvent($message);

                return $message;
            }
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $message[trim($key)] = ltrim($value);
        }

        return $message === [] ? null : $message;
    }

    /** @return array<string,string> */
    private function readUntilAction($socket, string $actionId): array
    {
        while (($event = $this->readMessage($socket)) !== null) {
            if (($event['ActionID'] ?? null) === $actionId) {
                return $event;
            }
        }

        throw new RuntimeException('Asterisk AMI closed the connection unexpectedly.');
    }

    private function actionId(string $prefix): string
    {
        return 'cc-'.$prefix.'-'.bin2hex(random_bytes(8));
    }

    private function closeAmi(): void
    {
        if (is_resource($this->ami)) {
            @fclose($this->ami);
        }
        $this->ami = null;
    }

    private function safeEndpoint(string $key, string $extension): string
    {
        $key = strtolower($key);
        if (! preg_match('/^[a-z0-9]{10}$/', $key)) {
            throw new RuntimeException('Invalid Asterisk tenant key.');
        }

        $extension = $this->safeExtension($extension);

        return $key.'_'.$extension;
    }

    private function safeExtension(string $extension): string
    {
        if (! preg_match('/^\d{2,20}$/', $extension)) {
            throw new RuntimeException('Invalid SIP extension.');
        }

        return $extension;
    }
}
