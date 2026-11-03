<?php

namespace App\Services;

use App\Enums\RouteDestination;
use App\Models\CallQueue;
use App\Models\InboundNumber;
use App\Models\PoolNumber;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The DIDs of the platform's SIP trunk, lent to teams so nobody has to type
 * a number in: a team gets one automatically when it creates its first
 * queue (or from the Numbers page), and can swap it for its own number
 * later. Released numbers go back to the pool.
 */
class NumberPool
{
    public function __construct(private AsteriskDialplan $dialplan) {}

    /** Free numbers left in the pool. */
    public function available(): int
    {
        return PoolNumber::query()->free()->count();
    }

    /**
     * The team's number from the pool, taking one if it has none yet.
     * Null when the pool is empty (or auto-assignment is off and $force is false).
     */
    public function ensureFor(Team $team, bool $force = false): ?InboundNumber
    {
        if (! $force && ! config('asterisk.numbers.auto_assign', true)) {
            return null;
        }

        $existing = $team->inboundNumbers()->where('source', InboundNumber::SOURCE_POOL)->orderBy('id')->first();

        return $existing ?? $this->assign($team);
    }

    /** Take the next free pool number for the team. */
    public function assign(Team $team): ?InboundNumber
    {
        // Several teams can race for the same row: the conditional update decides.
        foreach (PoolNumber::query()->free()->orderBy('id')->limit(5)->pluck('id') as $id) {
            $number = DB::transaction(function () use ($team, $id): ?InboundNumber {
                $taken = PoolNumber::query()
                    ->whereKey($id)
                    ->whereNull('team_id')
                    ->update(['team_id' => $team->id, 'assigned_at' => now(), 'updated_at' => now()]);

                if ($taken !== 1) {
                    return null;
                }

                $pool = PoolNumber::query()->findOrFail($id);
                [$type, $target] = $this->defaultDestination($team);

                return $team->inboundNumbers()->create([
                    'number' => $pool->number,
                    'source' => InboundNumber::SOURCE_POOL,
                    'label' => __('Main number'),
                    'destination_type' => $type,
                    'destination_target' => $target,
                ]);
            });

            if ($number !== null) {
                $number->setRelation('team', $team);
                $this->sync($number);

                return $number;
            }
        }

        return null;
    }

    /**
     * Pool numbers not routed yet (the team had no queue when it got them)
     * now ring the new queue.
     */
    public function routeUnrouted(Team $team, CallQueue $queue): void
    {
        $team->inboundNumbers()
            ->where('source', InboundNumber::SOURCE_POOL)
            ->where('destination_type', RouteDestination::Hangup->value)
            ->get()
            ->each(function (InboundNumber $number) use ($team, $queue): void {
                $number->update([
                    'destination_type' => RouteDestination::Queue->value,
                    'destination_target' => (string) $queue->id,
                ]);
                $number->setRelation('team', $team);
                $this->sync($number);
            });
    }

    /**
     * Swap a pool number for the customer's own number: same label and
     * routing, the pool number goes back to the pool.
     */
    public function replaceWithOwn(InboundNumber $number, string $own): InboundNumber
    {
        if (! $number->fromPool()) {
            throw ValidationException::withMessages(['number' => __('Only a number lent by the platform can be replaced.')]);
        }

        $team = $number->team;

        $replacement = DB::transaction(function () use ($number, $own, $team): InboundNumber {
            $replacement = $team->inboundNumbers()->create([
                'number' => $own,
                'source' => InboundNumber::SOURCE_OWN,
                'label' => $number->label,
                'destination_type' => $number->destination_type,
                'destination_target' => $number->destination_target,
            ]);

            $this->releaseRow($number);

            return $replacement;
        });

        $this->unsync($number->number);
        $replacement->setRelation('team', $team);
        $this->sync($replacement);

        return $replacement;
    }

    /** Remove a team number; a pool number goes back to the pool. */
    public function remove(InboundNumber $number): void
    {
        DB::transaction(fn () => $this->releaseRow($number));
        $this->unsync($number->number);

        // The removed number may have been the caller ID of outside calls.
        rescue(fn () => $this->dialplan->ensureOutboundDialing($number->team), report: true);
    }

    /** Is this number one of the platform's DIDs (free or lent)? */
    public function isPoolNumber(string $number): bool
    {
        return PoolNumber::query()->where('number', $number)->exists();
    }

    /**
     * Add DIDs to the pool. Returns how many were new.
     *
     * @param  iterable<string>  $numbers
     */
    public function add(iterable $numbers, ?string $note = null): int
    {
        $added = 0;

        foreach ($numbers as $number) {
            $pool = PoolNumber::query()->firstOrCreate(['number' => $number], ['note' => $note]);
            $added += $pool->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    /** First queue of the team, or "not routed yet" (hangup) until it has one. */
    private function defaultDestination(Team $team): array
    {
        $queue = $team->callQueues()->orderBy('id')->first();

        return $queue
            ? [RouteDestination::Queue->value, (string) $queue->id]
            : [RouteDestination::Hangup->value, null];
    }

    private function releaseRow(InboundNumber $number): void
    {
        if ($number->fromPool()) {
            PoolNumber::query()
                ->where('number', $number->number)
                ->where('team_id', $number->team_id)
                ->update(['team_id' => null, 'assigned_at' => null, 'updated_at' => now()]);
        }

        $number->delete();
    }

    /** The app is the source of truth; Asterisk is caught up by the next routing sync if it is down. */
    private function sync(InboundNumber $number): void
    {
        try {
            $this->dialplan->syncInboundNumber($number);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function unsync(string $number): void
    {
        try {
            $this->dialplan->removeInboundNumber($number);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
