<?php

namespace App\Services;

use App\Models\PhoneExtension;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one place that keeps the application (phone_extensions, call_queues)
 * and the Asterisk realtime tables in sync. Every path that creates,
 * changes or removes an extension or a tenant goes through here: member
 * creation, the PJSIP settings page, agents:assign, member removal, team
 * deletion and asterisk:provision-team.
 */
class AsteriskProvisioner
{
    public function __construct(
        private AsteriskPjsip $pjsip,
        private AsteriskDialplan $dialplan,
        private AsteriskQueues $queues,
        private AsteriskQueueMembers $members,
        private AsteriskAmi $ami,
    ) {}

    /**
     * Create (or update) a team extension and its PJSIP endpoint.
     *
     * @param  array{user_id?: int|null, label?: string|null, secret?: string|null, transport?: string|null, allow?: string|null, max_contacts?: int|null}  $data
     */
    public function saveExtension(Team $team, string $extension, array $data = []): PhoneExtension
    {
        $endpointId = $this->pjsip->endpointId($team, $extension);

        $phone = $team->phoneExtensions()->firstOrNew(['extension' => $extension]);
        $isNew = ! $phone->exists;

        if (array_key_exists('user_id', $data)) {
            $userId = $data['user_id'];

            if ($userId !== null && $team->phoneExtensions()->where('user_id', $userId)->where('extension', '!=', $extension)->exists()) {
                throw new RuntimeException(__('This member already has an extension in this team.'));
            }

            if ($phone->user_id !== null && $phone->user_id !== $userId) {
                $this->members->leaveInterface('PJSIP/'.$endpointId);
            }

            $phone->user_id = $userId;
            $phone->is_primary = $userId !== null;
        }

        if (! empty($data['label'])) {
            $phone->label = $data['label'];
        }

        if (! empty($data['secret'])) {
            $phone->secret = $data['secret'];
        } elseif ($isNew) {
            $phone->secret = Str::password(24, symbols: false);
        }

        $phone->label ??= $phone->user_id ? User::query()->whereKey($phone->user_id)->value('name') : $extension;

        DB::transaction(function () use ($team, $phone, $endpointId, $data): void {
            $phone->save();
            $phone->setRelation('team', $team);

            $endpoint = [
                'id' => $endpointId,
                'context' => $team->asteriskContext(),
                'accountcode' => $team->asterisk_key,
                'password' => $phone->secret,
                'transport' => $data['transport'] ?? null,
                'allow' => $data['allow'] ?? null,
                'max_contacts' => $data['max_contacts'] ?? null,
            ];

            if ($this->pjsip->exists($endpointId)) {
                $this->pjsip->update($endpointId, $endpoint);

                return;
            }

            $this->pjsip->create([
                ...$endpoint,
                'transport' => $endpoint['transport'] ?: (string) config('asterisk.provisioning.transport', 'transport-wss'),
                'allow' => $endpoint['allow'] ?: (string) config('asterisk.provisioning.allow', 'opus,ulaw,alaw'),
                'max_contacts' => (int) ($endpoint['max_contacts'] ?: config('asterisk.provisioning.max_contacts', 1)),
            ]);
        });

        // Without it, colleagues cannot call this extension ("extension not found in context").
        $this->dialplan->ensureInternalDialing($team);

        // Holds from this softphone play the team's music, when it has some.
        rescue(fn () => app(TeamHoldMusic::class)->applyToEndpoint($team, $endpointId), report: true);

        return $phone;
    }

    /** Remove an extension: queue presence, PJSIP endpoint, then the row. */
    public function deleteExtension(PhoneExtension $phone): void
    {
        $team = $phone->team;
        $endpointId = $this->pjsip->endpointId($team, $phone->extension);

        $this->members->leaveInterface('PJSIP/'.$endpointId);
        $this->pjsip->delete($endpointId);
        rescue(fn () => app(TeamVoicemail::class)->deleteBox($team, $phone->extension), report: true);
        $phone->delete();
    }

    /** Everything a member leaving a team takes along: presence, queues, extension. */
    public function removeMember(Team $team, User $user): void
    {
        $this->members->leave($user, $team);

        if ($phone = $user->phoneExtensionFor($team)) {
            $this->deleteExtension($phone);
        }

        $user->callQueues()->detach($team->callQueues()->pluck('id')->all());
    }

    /**
     * Make the tenant work end-to-end: dialplan context, queue entries and
     * a PJSIP endpoint for every extension.
     *
     * @return array{dialplan: list<array<string, mixed>>, created: list<string>, existing: list<string>}
     */
    public function provisionTeam(Team $team, bool $replaceDialplan = false): array
    {
        $created = [];
        $existing = [];

        foreach ($team->phoneExtensions()->get() as $phone) {
            $id = $this->pjsip->endpointId($team, $phone->extension);
            $this->pjsip->exists($id) ? $existing[] = $id : $created[] = $id;
            $this->saveExtension($team, $phone->extension);
        }

        return [
            'dialplan' => $this->dialplan->provisionTeamContext($team, $replaceDialplan),
            'created' => $created,
            'existing' => $existing,
        ];
    }

    /** A deleted team must stop working in Asterisk immediately. */
    public function deprovisionTeam(Team $team): void
    {
        $this->queues->deleteTeam($team);
        $this->pjsip->deleteTeam($team);
        $this->dialplan->deleteTeamContext($team);
        rescue(fn () => app(TeamVoicemail::class)->deleteTeam($team), report: true);
        rescue(fn () => app(TeamHoldMusic::class)->deleteTeam($team), report: true);
        $this->reloadDialplan();
    }

    /**
     * Realtime dialplan rows are read live through the realtime switch; a
     * reload only flushes cached lookups. Never fail the request over it.
     */
    public function reloadDialplan(): void
    {
        try {
            $this->ami->reloadDialplan();
        } catch (RuntimeException $exception) {
            report($exception);
        }
    }
}
