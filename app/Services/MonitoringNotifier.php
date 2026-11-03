<?php

namespace App\Services;

use App\Events\MonitoringChanged;
use App\Models\Team;

/**
 * Turns "tenant X changed" (an Asterisk tenant key, or a team) into a
 * MonitoringChanged broadcast. Used by the AMI daemon, the signed webhook and
 * the app's own presence changes (pause/resume, login).
 */
class MonitoringNotifier
{
    /** @var array<string, int>|null asterisk_key => team id */
    private ?array $teamIds = null;

    private int $loadedAt = 0;

    /**
     * @param  iterable<string>  $keys  Asterisk tenant keys
     * @return int number of teams notified
     */
    public function notifyKeys(iterable $keys): int
    {
        $map = $this->map();
        $sent = 0;

        foreach ($keys as $key) {
            $teamId = $map[$key] ?? null;

            // A key we have not seen may belong to a team created since the
            // map was loaded; reload once before giving up.
            if ($teamId === null && time() - $this->loadedAt > 5) {
                $teamId = $this->map(refresh: true)[$key] ?? null;
            }

            if ($teamId !== null) {
                $this->notifyTeam($teamId);
                $sent++;
            }
        }

        return $sent;
    }

    public function notifyTeam(Team|int|null $team): void
    {
        if ($team === null) {
            return;
        }

        try {
            event(new MonitoringChanged($team instanceof Team ? $team->id : $team));
        } catch (\Throwable $exception) {
            // Presence changes must never fail because the push channel is down.
            report($exception);
        }
    }

    /** @return array<string, int> */
    private function map(bool $refresh = false): array
    {
        if ($refresh || $this->teamIds === null || time() - $this->loadedAt > 60) {
            $this->teamIds = Team::query()
                ->whereNotNull('asterisk_key')
                ->pluck('id', 'asterisk_key')
                ->map(fn ($id) => (int) $id)
                ->all();
            $this->loadedAt = time();
        }

        return $this->teamIds;
    }
}
