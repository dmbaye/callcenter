<?php

namespace App\Services;

use App\Enums\RouteDestination;
use App\Models\CallQueue;
use App\Models\InboundNumber;
use App\Models\IvrMenu;
use App\Models\QueueClosure;
use App\Models\Team;
use App\Services\Concerns\UsesAsteriskConnection;
use RuntimeException;

/**
 * Manages the Asterisk realtime `extensions` table (the dialplan lives in
 * the database, not in extensions.conf).
 *
 * Every tenant owns one context, t_{asterisk_key}, containing:
 *  - the internal dial pattern (agent to agent, any 2-20 digit extension),
 *  - one exact extension per queue, named after the queue's immutable
 *    asterisk_name. It stamps the CDR accountcode, applies the opening
 *    hours, then enters the queue. Inbound DIDs and transfers both land here.
 */
class AsteriskDialplan
{
    use UsesAsteriskConnection;

    /** Matches every extension the app accepts (2-20 digits); exact queue extensions always win. */
    public const INTERNAL_PATTERN = '_X.';

    /**
     * Calls to outside numbers: the softphone sends them as 00 + international
     * number. Asterisk's realtime switch takes the first matching pattern in
     * `exten` order, and `_00X.` sorts before `_X.`; extensions may not start
     * with 00, so internal calls never reach this route.
     */
    public const OUTBOUND_PATTERN = '_00X.';

    /** Agents dial it to listen to their own voicemail. */
    public const VOICEMAIL_ACCESS = '*97';

    public function __construct(private QueueOpeningHours $openingHours) {}

    /**
     * Create the tenant context: internal dial pattern plus every queue entry.
     *
     * @return list<array<string, mixed>> the context rows after provisioning
     */
    public function provisionTeamContext(Team $team, bool $replace = false): array
    {
        $context = $team->asteriskContext();

        $this->connection()->transaction(function () use ($team, $context, $replace): void {
            if ($replace) {
                $this->connection()->table('extensions')->where('context', $context)->delete();
            }

            $this->insertInternalDialing($team);
        });

        // syncTeamRouting() → ensureInternalDialing() also writes the outbound route.
        $this->syncTeamRouting($team);

        return $this->rows($context);
    }

    /**
     * Extension-to-extension calls (`_X.` → Dial(PJSIP/{key}_${EXTEN})).
     * Written whenever an extension is saved or the routing is synced, so a
     * team never depends on `asterisk:provision-team` having been run.
     */
    public function ensureInternalDialing(Team $team): void
    {
        $this->connection()->transaction(fn () => $this->insertInternalDialing($team));
        $this->ensureOutboundDialing($team);
    }

    /**
     * The team's route to outside numbers (`_00X.`): stamps the account and
     * the team's main number as caller ID, then hands the call to the shared
     * cc-outbound context, which holds the platform trunk.
     */
    public function ensureOutboundDialing(Team $team): void
    {
        $context = $team->asteriskContext();
        $rows = $this->outboundRows($team);

        $this->connection()->transaction(function () use ($context, $rows): void {
            $this->connection()->table('extensions')
                ->where('context', $context)
                ->where('exten', self::OUTBOUND_PATTERN)
                ->delete();
            $this->connection()->table('extensions')->insert($rows);
        });
    }

    /** @return list<array{context: string, exten: string, priority: int, app: string, appdata: string}> */
    public function outboundRows(Team $team): array
    {
        $cli = $team->mainNumber();

        return $this->compose($team->asteriskContext(), self::OUTBOUND_PATTERN, array_values(array_filter([
            [null, 'Set', 'CHANNEL(accountcode)='.$team->asterisk_key],
            $cli !== null ? [null, 'Set', 'CALLERID(num)='.$cli] : null,
            [null, 'Goto', $this->outboundContext().',${EXTEN},1'],
        ])));
    }

    /**
     * The shared outbound context: one route, through the platform trunk.
     * Changing the trunk rewrites only this row (and campaigns read it too).
     *
     * @return list<array{context: string, exten: string, priority: int, app: string, appdata: string}>
     */
    public function outboundContextRows(?string $trunk = null): array
    {
        $trunk ??= app(OutboundTrunk::class)->name();
        $context = $this->outboundContext();

        if ($trunk === '') {
            // No trunk chosen yet: fail like a congested network.
            return [$this->row($context, self::OUTBOUND_PATTERN, 1, 'Hangup', '34')];
        }

        $prefix = (string) config('asterisk.campaigns.dial_prefix', '00');
        $timeout = (int) config('asterisk.outbound.dial_timeout', 60);

        return [
            // ${EXTEN} is 00 + international number; the trunk gets the
            // same format as campaign calls ({dial_prefix} + international).
            $this->row($context, self::OUTBOUND_PATTERN, 1, 'Dial', 'PJSIP/'.$prefix.'${EXTEN:2}@'.$trunk.','.$timeout),
            $this->row($context, self::OUTBOUND_PATTERN, 2, 'Hangup', ''),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function provisionOutboundContext(?string $trunk = null): array
    {
        $context = $this->outboundContext();
        $rows = $this->outboundContextRows($trunk);

        $this->connection()->transaction(function () use ($context, $rows): void {
            $this->connection()->table('extensions')->where('context', $context)->delete();
            $this->connection()->table('extensions')->insert($rows);
        });

        return $this->rows($context);
    }

    public function outboundConfigStanza(): string
    {
        $context = $this->outboundContext();
        $family = (string) config('asterisk.dialplan.realtime_family', 'extensions');

        return "[{$context}]\nswitch => Realtime/{$context}@{$family}\n";
    }

    private function outboundContext(): string
    {
        return (string) config('asterisk.outbound.context', 'cc-outbound');
    }

    private function insertInternalDialing(Team $team): void
    {
        $wanted = $this->internalRows($team);
        $current = $this->connection()->table('extensions')
            ->where('context', $team->asteriskContext())
            ->where('exten', self::INTERNAL_PATTERN)
            ->orderBy('priority')
            ->get(['priority', 'app', 'appdata'])
            ->map(fn ($row) => [(int) $row->priority, (string) $row->app, (string) $row->appdata])
            ->all();

        $expected = array_map(fn (array $row) => [$row['priority'], $row['app'], $row['appdata']], $wanted);

        // Missing or written by an older version (no busy check): rewrite it.
        if ($current !== $expected) {
            $this->connection()->table('extensions')
                ->where('context', $team->asteriskContext())
                ->where('exten', self::INTERNAL_PATTERN)
                ->delete();
            $this->connection()->table('extensions')->insert($wanted);
        }

        $this->connection()->table('extensions')
            ->where('context', $team->asteriskContext())
            ->where('exten', self::VOICEMAIL_ACCESS)
            ->delete();
        $this->connection()->table('extensions')->insert($this->voicemailAccessRows($team));
    }

    /**
     * *97: the agent's own box, found from the calling endpoint
     * ({key}_{extension}); the softphone is already authenticated, so no PIN.
     *
     * @return list<array{context: string, exten: string, priority: int, app: string, appdata: string}>
     */
    private function voicemailAccessRows(Team $team): array
    {
        return $this->compose($team->asteriskContext(), self::VOICEMAIL_ACCESS, [
            [null, 'Set', 'CHANNEL(accountcode)='.$team->asterisk_key],
            [null, 'Answer', ''],
            [null, 'VoiceMailMain', '${CUT(CHANNEL(endpoint),_,2)}@'.$team->asterisk_key.',s'],
            [null, 'Hangup', ''],
        ]);
    }

    /**
     * (Re)write a queue's entry extension in its team context, including the
     * opening hours. No hours configured = always open.
     *
     * @return list<array<string, mixed>>
     */
    public function syncQueue(CallQueue $queue, bool $dryRun = false): array
    {
        $team = $queue->team;
        $rows = $this->queueRows($team, $queue);

        if ($dryRun) {
            return $rows;
        }

        if ($this->recordsCalls($team, $queue)) {
            $this->ensureRecordingContext();
        }

        $this->connection()->transaction(function () use ($team, $queue, $rows): void {
            $this->connection()->table('extensions')
                ->where('context', $team->asteriskContext())
                ->where('exten', $queue->asterisk_name)
                ->delete();

            $this->connection()->table('extensions')->insert($rows);
        });

        return $rows;
    }

    public function removeQueue(CallQueue $queue): void
    {
        $this->connection()->table('extensions')
            ->where('context', $queue->team->asteriskContext())
            ->where('exten', $queue->asterisk_name)
            ->delete();
    }

    public function deleteTeamContext(Team $team): int
    {
        return $this->connection()->table('extensions')
            ->where('context', $team->asteriskContext())
            ->delete();
    }

    /** @return list<array<string, mixed>> */
    public function rows(?string $context = null): array
    {
        return $this->connection()->table('extensions')
            ->when($context !== null, fn ($query) => $query->where('context', $context))
            ->orderBy('context')
            ->orderBy('exten')
            ->orderBy('priority')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    public function rowExists(string $context, string $exten, int $priority): bool
    {
        return $this->connection()->table('extensions')
            ->where('context', $context)
            ->where('exten', $exten)
            ->where('priority', $priority)
            ->exists();
    }

    /** @param  array{context: string, exten: string, priority: int, app: string, appdata: string}  $data */
    public function createRow(array $data): void
    {
        $this->connection()->table('extensions')->insert($this->row(
            $data['context'], $data['exten'], $data['priority'], $data['app'], $data['appdata'],
        ));
    }

    /**
     * Replace one row (the key may change), atomically.
     *
     * @param  array{context: string, exten: string, priority: int}  $original
     * @param  array{context: string, exten: string, priority: int, app: string, appdata: string}  $data
     */
    public function updateRow(array $original, array $data): void
    {
        $this->connection()->transaction(function () use ($original, $data): void {
            $deleted = $this->connection()->table('extensions')
                ->where('context', $original['context'])
                ->where('exten', $original['exten'])
                ->where('priority', $original['priority'])
                ->delete();

            if ($deleted === 0) {
                throw new RuntimeException('Dialplan row not found.');
            }

            $this->createRow($data);
        });
    }

    public function deleteRow(string $context, string $exten, int $priority): void
    {
        $deleted = $this->connection()->table('extensions')
            ->where('context', $context)
            ->where('exten', $exten)
            ->where('priority', $priority)
            ->delete();

        if ($deleted === 0) {
            throw new RuntimeException('Dialplan row not found.');
        }
    }

    /**
     * The shared outbound campaign context (same for every tenant: the
     * tenant, queue and numbers arrive as CC_* channel variables set by the
     * dialer, and the accountcode stamps the CDR to the right team).
     *
     *   s        customer answered (progressive/power): optional AMD, then the
     *            campaign queue; nobody free in time = abandon message.
     *   preview  agent answered (preview/callbacks): dial the customer.
     *   answered Dial U() gosub on the customer leg: reports "connected".
     *   report   hangup handler: reports the end of the call.
     *
     * Every step reports through UserEvent, read by the AMI daemon. With
     * CC_RECORD=1 (set by the dialer when the campaign queue records) the
     * call goes through cc-record first.
     *
     * @return list<array{context: string, exten: string, priority: int, app: string, appdata: string}>
     */
    public function campaignRows(): array
    {
        $c = $this->campaignContext();
        $report = $c.',report,1';
        $record = $this->recordingContext().',s,1';

        return [
            // Dial U() always enters a context at s,1: the customer leg of a preview
            // call arrives here with ARG1=answered and is sent to the answered hook.
            $this->row($c, 's', 1, 'GotoIf', '$["${ARG1}" = "answered"]?answered,1'),
            // Customer first (progressive / power).
            $this->row($c, 's', 2, 'Set', 'CHANNEL(accountcode)=${CC_ACCOUNT}'),
            $this->row($c, 's', 3, 'Set', 'CHANNEL(hangup_handler_push)='.$report),
            $this->row($c, 's', 4, 'Set', 'CC_ANSWERED=1'),
            $this->row($c, 's', 5, 'UserEvent', 'CcCampaign,Attempt: ${CC_ATTEMPT},State: answered'),
            $this->row($c, 's', 6, 'GotoIf', '$["${CC_AMD}" != "1"]?9'),
            $this->row($c, 's', 7, 'AMD', ''),
            $this->row($c, 's', 8, 'GotoIf', '$["${AMDSTATUS}" = "MACHINE"]?16'),
            // A person answered: record (if the campaign queue records), then the queue.
            $this->row($c, 's', 9, 'GosubIf', '$["${CC_RECORD}" = "1"]?'.$record.'(${CC_REC_NOTICE})'),
            $this->row($c, 's', 10, 'Set', 'CALLERID(all)=${CC_CONTACT} <${CC_NUMBER}>'),
            $this->row($c, 's', 11, 'Queue', '${CC_QUEUE},,,,${CC_WAIT}'),
            $this->row($c, 's', 12, 'GotoIf', '$["${MEMBERINTERFACE}" != ""]?15'),
            $this->row($c, 's', 13, 'GotoIf', '$["${CC_SORRY}" = ""]?15'),
            $this->row($c, 's', 14, 'Playback', '${CC_SORRY}'),
            $this->row($c, 's', 15, 'Hangup', ''),
            // Answering machine: optional message drop.
            $this->row($c, 's', 16, 'GotoIf', '$["${CC_VOICEMAIL}" = ""]?15'),
            $this->row($c, 's', 17, 'WaitForSilence', '1500,1,8'),
            $this->row($c, 's', 18, 'Playback', '${CC_VOICEMAIL}'),
            $this->row($c, 's', 19, 'Hangup', ''),

            // Agent first (preview and callbacks). Recording runs on the agent leg.
            $this->row($c, 'preview', 1, 'Set', 'CHANNEL(accountcode)=${CC_ACCOUNT}'),
            $this->row($c, 'preview', 2, 'Set', 'CHANNEL(hangup_handler_push)='.$report),
            $this->row($c, 'preview', 3, 'Set', '__CC_ATTEMPT=${CC_ATTEMPT}'),
            $this->row($c, 'preview', 4, 'UserEvent', 'CcCampaign,Attempt: ${CC_ATTEMPT},State: agent'),
            $this->row($c, 'preview', 5, 'Set', 'CALLERID(num)=${CC_CLI}'),
            $this->row($c, 'preview', 6, 'GosubIf', '$["${CC_RECORD}" = "1"]?'.$record.'()'),
            $this->row($c, 'preview', 7, 'Dial', '${CC_DIAL},${CC_RING},U('.$c.'^answered)'),
            $this->row($c, 'preview', 8, 'Hangup', ''),

            $this->row($c, 'answered', 1, 'UserEvent', 'CcCampaign,Attempt: ${CC_ATTEMPT},State: connected'),
            $this->row($c, 'answered', 2, 'Return', ''),

            $this->row($c, 'report', 1, 'UserEvent', 'CcCampaignEnd,Attempt: ${CC_ATTEMPT},Answered: ${CC_ANSWERED},Amd: ${AMDSTATUS},Member: ${MEMBERINTERFACE},Dial: ${DIALSTATUS}'),
            $this->row($c, 'report', 2, 'Return', ''),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function provisionCampaignContext(): array
    {
        $context = $this->campaignContext();

        $this->connection()->transaction(function () use ($context): void {
            $this->connection()->table('extensions')->where('context', $context)->delete();
            $this->connection()->table('extensions')->insert($this->campaignRows());
        });

        return $this->rows($context);
    }

    public function campaignConfigStanza(): string
    {
        $context = $this->campaignContext();
        $family = (string) config('asterisk.dialplan.realtime_family', 'extensions');

        return "[{$context}]\nswitch => Realtime/{$context}@{$family}\n";
    }

    private function campaignContext(): string
    {
        return (string) config('asterisk.campaigns.context', 'cc-campaign');
    }

    /**
     * Shared recording subroutine, entered with Gosub(cc-record,s,1(notice)):
     * plays the optional notice, then records the bridged call. When the
     * recording ends Asterisk runs the upload command on the file, whose
     * name carries the account, uniqueid and linkedid.
     *
     * @return list<array{context: string, exten: string, priority: int, app: string, appdata: string}>
     */
    public function recordingRows(): array
    {
        $c = $this->recordingContext();
        $file = rtrim((string) config('asterisk.recordings.directory'), '/')
            .'/${CHANNEL(accountcode)}__${UNIQUEID}__${CHANNEL(linkedid)}.wav';
        $command = (string) config('asterisk.recordings.command');

        return [
            $this->row($c, 's', 1, 'GotoIf', '$["${ARG1}" = ""]?3'),
            $this->row($c, 's', 2, 'Playback', '${ARG1}'),
            $this->row($c, 's', 3, 'MixMonitor', $file.',b,'.$command.' '.$file),
            $this->row($c, 's', 4, 'Return', ''),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function provisionRecordingContext(): array
    {
        $context = $this->recordingContext();

        $this->connection()->transaction(function () use ($context): void {
            $this->connection()->table('extensions')->where('context', $context)->delete();
            $this->connection()->table('extensions')->insert($this->recordingRows());
        });

        return $this->rows($context);
    }

    public function recordingConfigStanza(): string
    {
        $context = $this->recordingContext();
        $family = (string) config('asterisk.dialplan.realtime_family', 'extensions');

        return "[{$context}]\nswitch => Realtime/{$context}@{$family}\n";
    }

    /** Queue records its calls: switched on and allowed by the team's plan. */
    public function recordsCalls(Team $team, ?CallQueue $queue): bool
    {
        return $queue !== null && $queue->record_calls && $team->plan()->allows('call_recording');
    }

    /** Sound played to the caller before recording, or '' for none. */
    public function recordingNotice(CallQueue $queue): string
    {
        return $queue->recording_notice
            ? (string) config('asterisk.inbound.sounds.recording_notice', 'callcenter-recording-notice')
            : '';
    }

    private function ensureRecordingContext(): void
    {
        $exists = $this->connection()->table('extensions')
            ->where('context', $this->recordingContext())
            ->exists();

        if (! $exists) {
            $this->provisionRecordingContext();
        }
    }

    private function recordingContext(): string
    {
        return (string) config('asterisk.recordings.context', 'cc-record');
    }

    /**
     * extensions.conf stanza Asterisk needs so it looks up a tenant context
     * in the realtime table (a context must exist in extensions.conf before
     * its realtime switch is consulted).
     */
    public function configStanza(Team $team): string
    {
        $context = $team->asteriskContext();
        $family = (string) config('asterisk.dialplan.realtime_family', 'extensions');

        return "[{$context}]\nswitch => Realtime/{$context}@{$family}\n";
    }

    /** @return list<array<string, mixed>> */
    private function internalRows(Team $team): array
    {
        $context = $team->asteriskContext();
        $pattern = self::INTERNAL_PATTERN;
        $timeout = (int) config('asterisk.dialplan.dial_timeout', 30);

        $endpoint = 'PJSIP/'.$team->asterisk_key.'_${EXTEN}';
        // Already on a call (talking, on hold, or ringing a second line): busy
        // instead of a call waiting. Covers colleagues, IVR « Poste » choices
        // and numbers routed straight to an extension.
        $busy = '"${CC_DEV}" = "INUSE" | "${CC_DEV}" = "BUSY" | "${CC_DEV}" = "RINGINUSE" | "${CC_DEV}" = "ONHOLD"';
        // Softphone not registered (closed tab, logged out) or no such extension:
        // answer « unavailable » (cause 20 → SIP 480) instead of failing in Dial.
        $offline = '"${CC_DEV}" = "UNAVAILABLE" | "${CC_DEV}" = "INVALID"';

        // The extension's voicemail box, when the team created one
        // (Paramètres → Messagerie vocale): VM_INFO is 0 without a box.
        $mailbox = '${EXTEN}@'.$team->asterisk_key;
        $hasBox = '$[${VM_INFO('.$mailbox.',exists)}]';

        return $this->compose($context, $pattern, [
            [null, 'Set', 'CHANNEL(accountcode)='.$team->asterisk_key],
            [null, 'Set', 'CC_DEV=${DEVICE_STATE('.$endpoint.')}'],
            [null, 'GotoIf', '$['.$busy.']?{@busy}'],
            [null, 'GotoIf', '$['.$offline.']?{@offline}'],
            [null, 'Dial', $endpoint.','.$timeout],
            // Not answered (no answer, refused): voicemail if any.
            [null, 'GotoIf', '$["${DIALSTATUS}" = "ANSWER"]?{@end}'],
            [null, 'GotoIf', $hasBox.'?{@voicemail}'],
            ['end', 'Hangup', ''],
            ['busy', 'GotoIf', $hasBox.'?{@voicemail_busy}'],
            [null, 'Busy', '5'],
            [null, 'Hangup', ''],
            ['offline', 'GotoIf', $hasBox.'?{@voicemail}'],
            [null, 'Hangup', '20'],
            ['voicemail', 'VoiceMail', $mailbox.',u'],
            [null, 'Hangup', ''],
            ['voicemail_busy', 'VoiceMail', $mailbox.',b'],
            [null, 'Hangup', ''],
        ]);
    }

    /**
     * The queue entry, in the spirit of a Genesys in-queue flow:
     *
     *   accountcode → screen-pop event → [emergency] → [closure days] →
     *   [opening hours] → [recording] → Queue (max wait) → [survey after the agent hangs
     *   up] / [callback offer] → overflow destination.
     *   closed → closed message → [callback offer] → hangup.
     *
     * A queue with none of the options gives the plain entry
     * (Set, UserEvent, [hours], Queue, Hangup, [closed, Hangup]).
     *
     * @return list<array<string, mixed>>
     */
    private function queueRows(Team $team, CallQueue $queue): array
    {
        $name = $queue->asterisk_name;
        $groups = $this->openingHours->timeGroups($queue);
        $closures = $this->upcomingClosures($queue);
        $hasClosedBranch = $groups !== [] || $closures !== [] || $queue->emergency_closed;
        $sounds = (array) config('asterisk.inbound.sounds', []);

        $steps = [
            [null, 'Set', 'CHANNEL(accountcode)='.$team->asterisk_key],
            [null, 'UserEvent', 'CcInbound,Queue: '.$name.',Number: ${CALLERID(num)},Choice: ${CC_IVR_CHOICE}'],
        ];

        if ($queue->emergency_closed) {
            $steps[] = [null, 'Goto', '{@emergency}'];
        }

        foreach ($closures as $closure) {
            // GotoIfTime(times,weekdays,mdays,months?…): fields split on « , » (or « | »), lists use « & ».
            $steps[] = [null, 'GotoIfTime', '*,*,'.$closure->date->format('j').','.strtolower($closure->date->format('M')).'?{@closed}'];
        }

        foreach ($groups as $index => $group) {
            $next = isset($groups[$index + 1]) ? '{@hours'.($index + 1).'}' : '{@closed}';
            $steps[] = ['hours'.$index, 'GotoIfTime', "{$group['times']},{$group['days']},*,*?{@open}:{$next}"];
        }

        $options = 'Tr'.($queue->survey_enabled ? 'c' : '');
        $queueStep = $name.','.$options.($queue->max_wait ? ',,,'.$queue->max_wait : '');

        if ($this->recordsCalls($team, $queue)) {
            $steps[] = ['open', 'Gosub', $this->recordingContext().',s,1('.$this->recordingNotice($queue).')'];
            $steps[] = [null, 'Queue', $queueStep];
        } else {
            $steps[] = ['open', 'Queue', $queueStep];
        }

        if ($queue->survey_enabled) {
            $steps[] = [null, 'GotoIf', '$["${MEMBERINTERFACE}" != ""]?{@survey}'];
        }

        if ($queue->callback_offer) {
            $steps[] = [null, 'Set', 'CC_CB_FROM=open'];
            $steps[] = [null, 'Goto', '{@cboffer}'];
        }

        array_push($steps, ...$this->destinationSteps($team, $queue->overflow_type, $queue->overflow_target, 'overflow', $queue));

        // The queue's own message, else the platform default.
        $closedSound = $queue->closed_sound ?: (string) config('asterisk.dialplan.closed_sound', 'callcenter-closed');

        if ($hasClosedBranch) {
            $steps[] = ['closed', 'Playback', $closedSound];
            array_push($steps, ...$this->afterClosed($queue));
        }

        if ($queue->emergency_closed) {
            $steps[] = ['emergency', 'Playback', $queue->emergency_sound ?: $closedSound];
            array_push($steps, ...$this->afterClosed($queue));
        }

        if ($queue->callback_offer) {
            $steps[] = ['cboffer', 'Read', 'CC_DIGIT,'.($sounds['callback_offer'] ?? 'callcenter-callback-offer').',1,,1,6'];
            $steps[] = [null, 'GotoIf', '$["${CC_DIGIT}" = "1"]?{@cbyes}'];
            $steps[] = [null, 'GotoIf', '$["${CC_CB_FROM}" = "open"]?{@overflow}'];
            $steps[] = [null, 'Hangup', ''];
            array_push($steps, ...$this->callbackSteps($team, $queue, 'cbyes'));
        }

        if ($queue->survey_enabled) {
            $steps[] = ['survey', 'Read', 'CC_CSAT,'.($sounds['survey'] ?? 'callcenter-survey').',1,,1,8'];
            $steps[] = [null, 'GotoIf', '$["${CC_CSAT}" = ""]?{@surveyend}'];
            $steps[] = [null, 'UserEvent', 'CcSurvey,Queue: '.$name.',Member: ${MEMBERINTERFACE},Score: ${CC_CSAT},Number: ${CALLERID(num)},Account: '.$team->asterisk_key];
            $steps[] = [null, 'Playback', $sounds['survey_thanks'] ?? 'callcenter-survey-thanks'];
            $steps[] = ['surveyend', 'Hangup', ''];
        }

        return $this->compose($team->asteriskContext(), $name, $steps);
    }

    /** @return list<array{0: string|null, 1: string, 2: string}> */
    private function afterClosed(CallQueue $queue): array
    {
        return $queue->callback_offer
            ? [[null, 'Set', 'CC_CB_FROM=closed'], [null, 'Goto', '{@cboffer}']]
            : [[null, 'Hangup', '']];
    }

    /**
     * Closure days of the coming year, written as day+month checks
     * (GotoIfTime has no year); `asterisk:sync-queues` runs daily to drop
     * past days before they would come back next year.
     *
     * @return list<QueueClosure>
     */
    private function upcomingClosures(CallQueue $queue): array
    {
        $horizon = (int) config('asterisk.inbound.closure_horizon_days', 365);

        return $queue->closures()
            ->whereDate('date', '>=', now()->toDateString())
            ->whereDate('date', '<', now()->addDays($horizon)->toDateString())
            ->get()
            ->all();
    }

    /**
     * Dialplan steps sending the caller to a destination. Always ends the
     * call (Goto elsewhere or Hangup), so it can close any branch.
     *
     * @return list<array{0: string|null, 1: string, 2: string}>
     */
    public function destinationSteps(Team $team, ?string $type, ?string $target, ?string $label = null, ?CallQueue $from = null): array
    {
        $context = $team->asteriskContext();
        $destination = RouteDestination::tryFrom((string) $type);

        $steps = match ($destination) {
            RouteDestination::Queue => ($queue = $team->callQueues()->find((int) $target)) && $queue->id !== $from?->id
                ? [[null, 'Goto', $context.','.$queue->asterisk_name.',1']]
                : [[null, 'Hangup', '']],
            RouteDestination::Ivr => ($menu = $team->ivrMenus()->find((int) $target))
                ? [[null, 'Goto', $context.','.$menu->extension().',1']]
                : [[null, 'Hangup', '']],
            RouteDestination::Extension => preg_match('/^\d{2,20}$/', (string) $target)
                ? [[null, 'Goto', $context.','.$target.',1']]
                : [[null, 'Hangup', '']],
            RouteDestination::Callback => ($queue = $team->callQueues()->find((int) $target))
                ? $this->callbackSteps($team, $queue)
                : [[null, 'Hangup', '']],
            RouteDestination::Message => self::isSoundName((string) $target)
                ? [[null, 'Playback', (string) $target], [null, 'Hangup', '']]
                : [[null, 'Hangup', '']],
            default => [[null, 'Hangup', '']],
        };

        $steps[0][0] = $label;

        return $steps;
    }

    /**
     * Register a callback request (read by the AMI daemon) and hang up.
     *
     * @return list<array{0: string|null, 1: string, 2: string}>
     */
    private function callbackSteps(Team $team, CallQueue $queue, ?string $label = null): array
    {
        return [
            [$label, 'UserEvent', 'CcCallback,Queue: '.$queue->asterisk_name.',Number: ${CALLERID(num)},Account: '.$team->asterisk_key],
            [null, 'Playback', (string) config('asterisk.inbound.sounds.callback_confirm', 'callcenter-callback-confirm')],
            [null, 'Hangup', ''],
        ];
    }

    /**
     * IVR menu: Read() one digit (keeps the menu inside the team context,
     * no per-menu context to declare), retries on silence or a wrong key,
     * then the fallback destination.
     *
     * @return list<array<string, mixed>>
     */
    public function ivrRows(IvrMenu $menu): array
    {
        $team = $menu->team;
        $options = collect($menu->options)->filter(fn ($option) => preg_match('/^[0-9*]$/', (string) ($option['digit'] ?? '')))->values();

        $steps = [
            [null, 'Set', 'CC_TRY=0'],
            ['start', 'Read', 'CC_IVR,'.$menu->greeting_sound.',1,,1,'.max(1, $menu->timeout)],
        ];

        foreach ($options as $option) {
            $steps[] = [null, 'GotoIf', '$["${CC_IVR}" = "'.$option['digit'].'"]?{@opt'.$this->digitLabel($option['digit']).'}'];
        }

        $steps[] = [null, 'GotoIf', '$["${CC_IVR}" = ""]?{@retry}'];

        if ($menu->invalid_sound) {
            $steps[] = [null, 'Playback', $menu->invalid_sound];
        }

        $steps[] = ['retry', 'Set', 'CC_TRY=$[${CC_TRY} + 1]'];
        $steps[] = [null, 'GotoIf', '$[${CC_TRY} < '.max(1, $menu->max_retries).']?{@start}'];
        array_push($steps, ...$this->destinationSteps($team, $menu->fallback_type, $menu->fallback_target, 'fallback'));

        foreach ($options as $option) {
            $choice = trim((string) preg_replace('/[^\pL\pN \-]/u', '', (string) ($option['label'] ?? $option['digit'])));
            $steps[] = ['opt'.$this->digitLabel($option['digit']), 'Set', 'CC_IVR_CHOICE='.($choice !== '' ? $choice : $option['digit'])];
            array_push($steps, ...$this->destinationSteps($team, $option['type'] ?? null, isset($option['target']) ? (string) $option['target'] : null));
        }

        return $this->compose($team->asteriskContext(), $menu->extension(), $steps);
    }

    public function syncIvr(IvrMenu $menu): void
    {
        $rows = $this->ivrRows($menu);

        $this->connection()->transaction(function () use ($menu, $rows): void {
            $this->connection()->table('extensions')
                ->where('context', $menu->team->asteriskContext())
                ->where('exten', $menu->extension())
                ->delete();

            $this->connection()->table('extensions')->insert($rows);
        });
    }

    public function removeIvr(IvrMenu $menu): void
    {
        $this->connection()->table('extensions')
            ->where('context', $menu->team->asteriskContext())
            ->where('exten', $menu->extension())
            ->delete();
    }

    /**
     * A DID in the shared inbound context: stamp the tenant and send the
     * call to its destination in the team context.
     *
     * @return list<array<string, mixed>>
     */
    public function inboundNumberRows(InboundNumber $number): array
    {
        $team = $number->team;

        return $this->compose($this->inboundContext(), $number->number, [
            [null, 'Set', 'CHANNEL(accountcode)='.$team->asterisk_key],
            [null, 'Set', '__CC_DID='.$number->number],
            ...$this->destinationSteps($team, $number->destination_type, $number->destination_target),
        ]);
    }

    public function syncInboundNumber(InboundNumber $number, ?string $previousNumber = null): void
    {
        $rows = $this->inboundNumberRows($number);

        $this->connection()->transaction(function () use ($number, $previousNumber, $rows): void {
            $this->connection()->table('extensions')
                ->where('context', $this->inboundContext())
                ->whereIn('exten', array_filter([$number->number, $previousNumber]))
                ->delete();
            $this->connection()->table('extensions')->insert($rows);
        });

        // The team's main number is the caller ID of its outside calls.
        $this->ensureOutboundDialing($number->team);
    }

    public function removeInboundNumber(string $number): void
    {
        $this->connection()->table('extensions')
            ->where('context', $this->inboundContext())
            ->where('exten', $number)
            ->delete();
    }

    /** Everything that can point at something else, rewritten after any routing change. */
    public function syncTeamRouting(Team $team): void
    {
        $this->ensureInternalDialing($team);
        // Resolved here: AsteriskQueues itself depends on this class.
        app(AsteriskQueues::class)->ensureNoRingInUse($team);

        foreach ($team->callQueues()->with(['hours', 'team'])->get() as $queue) {
            $this->syncQueue($queue);
        }

        foreach ($team->ivrMenus()->with('team')->get() as $menu) {
            $this->syncIvr($menu);
        }

        foreach ($team->inboundNumbers()->with('team')->get() as $number) {
            $this->syncInboundNumber($number);
        }
    }

    public function inboundConfigStanza(): string
    {
        $context = $this->inboundContext();
        $family = (string) config('asterisk.dialplan.realtime_family', 'extensions');

        return "[{$context}]\nswitch => Realtime/{$context}@{$family}\n";
    }

    private function inboundContext(): string
    {
        return (string) config('asterisk.inbound.context', 'cc-inbound');
    }

    /**
     * Extensions of the team context the app writes itself (internal
     * dialing, queue entries, voice menus). Hand edits there would be
     * overwritten or break routing, so the expert editor leaves them alone.
     *
     * @return list<string>
     */
    public function managedExtensions(Team $team): array
    {
        return [
            self::INTERNAL_PATTERN,
            self::OUTBOUND_PATTERN,
            self::VOICEMAIL_ACCESS,
            ...$team->callQueues()->pluck('asterisk_name')->all(),
            ...$team->ivrMenus()->get()->map(fn (IvrMenu $menu) => $menu->extension())->all(),
        ];
    }

    public function isManagedExtension(Team $team, string $exten): bool
    {
        return str_starts_with($exten, 'ivr-') || in_array($exten, $this->managedExtensions($team), true);
    }

    public static function isSoundName(string $value): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9_/.-]{1,120}$#', $value);
    }

    private function digitLabel(string $digit): string
    {
        return $digit === '*' ? 'star' : $digit;
    }

    /**
     * Number the steps and resolve {@label} references to priorities.
     *
     * @param  list<array{0: string|null, 1: string, 2: string}>  $steps
     * @return list<array{context: string, exten: string, priority: int, app: string, appdata: string}>
     */
    private function compose(string $context, string $exten, array $steps): array
    {
        $priorities = [];

        foreach ($steps as $index => $step) {
            if ($step[0] !== null) {
                $priorities[$step[0]] = $index + 1;
            }
        }

        $rows = [];

        foreach ($steps as $index => [, $app, $appdata]) {
            $appdata = (string) preg_replace_callback('/\{@(\w+)\}/', function (array $m) use ($priorities, $context, $exten): string {
                if (! isset($priorities[$m[1]])) {
                    throw new RuntimeException("Unknown dialplan label [{$m[1]}] in {$context}/{$exten}.");
                }

                return (string) $priorities[$m[1]];
            }, $appdata);

            $rows[] = $this->row($context, $exten, $index + 1, $app, $appdata);
        }

        return $rows;
    }

    /** @return array{context: string, exten: string, priority: int, app: string, appdata: string} */
    private function row(
        string $context,
        string $exten,
        int $priority,
        string $app,
        string $appdata,
    ): array {
        return compact('context', 'exten', 'priority', 'app', 'appdata');
    }
}
