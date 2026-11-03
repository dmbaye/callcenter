<?php

namespace App\Services;

/**
 * Decides which tenants' live state actually changed between two AMI
 * snapshots, so the daemon only pushes real changes.
 *
 * Volatile fields (call Duration, queue Wait, Holdtime...) are left out of the
 * fingerprint on purpose: they tick every second and the browser extends them
 * locally, so they must not cause a push.
 */
class MonitoringChangeDetector
{
    /** @var array<string, string>|null */
    private ?array $previous = null;

    /**
     * @param  array{channels?: list<array<string,string>>, queues?: array<string, mixed>}  $snapshot
     * @return list<string> tenant keys whose state changed (every key on the first call)
     */
    public function changedKeys(array $snapshot): array
    {
        $current = $this->fingerprints($snapshot);
        $previous = $this->previous ?? [];
        $this->previous = $current;

        $changed = [];

        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $key) {
            if (($current[$key] ?? null) !== ($previous[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string> tenant key => fingerprint
     */
    public function fingerprints(array $snapshot): array
    {
        $rows = [];

        foreach ($snapshot['channels'] ?? [] as $channel) {
            $key = self::tenantKey(($channel['Channel'] ?? '').' '.($channel['BridgedChannel'] ?? '').' '.($channel['AccountCode'] ?? ''));

            if ($key !== null) {
                $rows[$key][] = ['channel', $channel['Channel'] ?? '', $channel['ChannelStateDesc'] ?? '', $channel['CallerIDNum'] ?? '', $channel['ConnectedLineNum'] ?? '', $channel['BridgeId'] ?? ''];
            }
        }

        $queues = $snapshot['queues'] ?? [];

        foreach ($queues['entries'] ?? [] as $entry) {
            $key = self::tenantKey($entry['Queue'] ?? '');

            if ($key !== null) {
                $rows[$key][] = ['entry', $entry['Queue'] ?? '', $entry['Position'] ?? '', $entry['CallerIDNum'] ?? ''];
            }
        }

        foreach ($queues['queues'] ?? [] as $name => $params) {
            $key = self::tenantKey((string) $name);

            if ($key !== null) {
                $rows[$key][] = ['queue', $name, $params['Calls'] ?? '', $params['Completed'] ?? '', $params['Abandoned'] ?? ''];
            }
        }

        foreach ($queues['members'] ?? [] as $member) {
            $key = self::tenantKey(($member['Queue'] ?? '').' '.($member['StateInterface'] ?? $member['Name'] ?? ''));

            if ($key !== null) {
                $rows[$key][] = ['member', $member['Queue'] ?? '', $member['StateInterface'] ?? $member['Name'] ?? '', $member['Paused'] ?? '', $member['Status'] ?? '', $member['InCall'] ?? ''];
            }
        }

        $fingerprints = [];

        foreach ($rows as $key => $tenantRows) {
            $lines = array_map(fn (array $row) => json_encode($row), $tenantRows);
            sort($lines);
            $fingerprints[$key] = hash('crc32b', implode("\n", $lines));
        }

        return $fingerprints;
    }

    /** Extract the 10-char tenant key from "PJSIP/<key>_<ext>-0001" or "<key>_<queue>". */
    public static function tenantKey(string $value): ?string
    {
        return preg_match('/(?<![a-z0-9])([a-z0-9]{10})_/', $value, $matches) === 1 ? $matches[1] : null;
    }
}
