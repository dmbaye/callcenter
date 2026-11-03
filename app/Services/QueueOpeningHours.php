<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\QueueHour;
use RuntimeException;

/**
 * Turns a queue's queue_hours rows into GotoIfTime() groups.
 */
class QueueOpeningHours
{
    /**
     * Days sharing the same time range are grouped, e.g.
     * [['times' => '08:00-18:00', 'days' => 'mon&tue&wed&thu&fri'], ...].
     *
     * @return list<array{times: string, days: string}>
     */
    public function timeGroups(CallQueue $queue): array
    {
        $groups = [];

        $rows = $queue->relationLoaded('hours')
            ? $queue->hours->sortBy('weekday')
            : $queue->hours()->orderBy('weekday')->get();

        foreach ($rows as $row) {
            $times = $this->range((string) $row->opens_at, (string) $row->closes_at);

            $groups[$times]['times'] ??= $times;
            $groups[$times]['days'][] = QueueHour::DAY_NAMES[$row->weekday];
        }

        return array_map(
            // « & » separates list items: « | » is a field separator for GotoIfTime,
            // so « mon|tue » never matched and the queue always played closed.
            fn (array $group): array => ['times' => $group['times'], 'days' => implode('&', $group['days'])],
            array_values($groups),
        );
    }

    private function range(string $opensAt, string $closesAt): string
    {
        $opens = substr($opensAt, 0, 5);
        $closes = substr($closesAt, 0, 5);

        if ($closes <= $opens) {
            throw new RuntimeException("Invalid opening hours: closes_at [{$closes}] must be after opens_at [{$opens}].");
        }

        return "{$opens}-{$closes}";
    }
}
