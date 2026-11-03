<?php

namespace App\Console\Commands;

use App\Models\PoolNumber;
use App\Models\Team;
use App\Services\NumberPool;
use Illuminate\Console\Command;

/**
 * Load and inspect the DIDs received from the SIP trunk provider.
 *
 *   php artisan numbers:pool add 22320201000-22320201099
 *   php artisan numbers:pool add 22320201100 22320201101 --note="Orange lot 2"
 *   php artisan numbers:pool import storage/app/dids.csv
 *   php artisan numbers:pool list [--free]
 *   php artisan numbers:pool assign <team slug|id>
 *   php artisan numbers:pool remove 22320201099
 */
class NumbersPool extends Command
{
    protected $signature = 'numbers:pool
                            {action : add, import, list, assign or remove}
                            {values?* : Numbers or ranges (add), a file (import), a team (assign), numbers (remove)}
                            {--note= : Note stored with added numbers}
                            {--free : list: only the free numbers}';

    protected $description = 'Manage the pool of DIDs teams get their phone number from';

    private const MAX_RANGE = 10000;

    public function handle(NumberPool $pool): int
    {
        $values = (array) $this->argument('values');

        return match ($this->argument('action')) {
            'add' => $this->add($pool, $values),
            'import' => $this->import($pool, $values[0] ?? null),
            'list' => $this->listNumbers(),
            'assign' => $this->assign($pool, $values[0] ?? null),
            'remove' => $this->remove($values),
            default => $this->invalid(),
        };
    }

    /** @param  list<string>  $values */
    private function add(NumberPool $pool, array $values): int
    {
        $numbers = [];

        foreach ($values as $value) {
            $expanded = $this->expand($value);

            if ($expanded === null) {
                $this->error("Invalid number or range: {$value}");

                return self::FAILURE;
            }

            array_push($numbers, ...$expanded);
        }

        if ($numbers === []) {
            $this->error('Give at least one number, e.g. numbers:pool add 22320201000-22320201099');

            return self::FAILURE;
        }

        $added = $pool->add(array_unique($numbers), $this->option('note'));
        $this->info("{$added} number(s) added, ".(count(array_unique($numbers)) - $added).' already in the pool. Free now: '.$pool->available().'.');

        return self::SUCCESS;
    }

    /** One number per line (or first CSV column). */
    private function import(NumberPool $pool, ?string $file): int
    {
        if ($file === null || ! is_readable($file)) {
            $this->error('File not found or not readable.');

            return self::FAILURE;
        }

        $values = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $first = trim(explode(',', str_replace(';', ',', $line))[0]);

            if ($first !== '' && preg_match('/\d/', $first)) {
                $values[] = $first;
            }
        }

        return $this->add($pool, $values);
    }

    private function listNumbers(): int
    {
        $rows = PoolNumber::query()
            ->with('team:id,name,slug')
            ->when($this->option('free'), fn ($query) => $query->free())
            ->orderBy('number')
            ->get()
            ->map(fn (PoolNumber $n) => [
                $n->number,
                $n->team ? "{$n->team->name} ({$n->team->slug})" : '— libre —',
                $n->assigned_at?->format('Y-m-d H:i') ?? '',
                $n->note ?? '',
            ]);

        $this->table(['Number', 'Team', 'Assigned', 'Note'], $rows->all());
        $this->line(PoolNumber::query()->free()->count().' free / '.PoolNumber::query()->count().' total');

        return self::SUCCESS;
    }

    /** Give a number to a team now (teams normally get one with their first queue). */
    private function assign(NumberPool $pool, ?string $value): int
    {
        $team = $value === null ? null : Team::query()->where('slug', $value)->orWhere('id', is_numeric($value) ? (int) $value : 0)->first();

        if ($team === null) {
            $this->error('Team not found.');

            return self::FAILURE;
        }

        $number = $pool->ensureFor($team, force: true);

        if ($number === null) {
            $this->error('The pool is empty.');

            return self::FAILURE;
        }

        $this->info("{$team->name}: {$number->number}");

        return self::SUCCESS;
    }

    /** @param  list<string>  $values */
    private function remove(array $values): int
    {
        $taken = PoolNumber::query()->whereIn('number', $values)->whereNotNull('team_id')->pluck('number');

        if ($taken->isNotEmpty()) {
            $this->error('Still assigned to a team (remove it from the team first): '.$taken->implode(', '));

            return self::FAILURE;
        }

        $deleted = PoolNumber::query()->whereIn('number', $values)->delete();
        $this->info("{$deleted} number(s) removed from the pool.");

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Unknown action. Use add, import, list, assign or remove.');

        return self::FAILURE;
    }

    /**
     * "22320201000" or "22320201000-22320201099" (same length on both sides).
     *
     * @return list<string>|null
     */
    private function expand(string $value): ?array
    {
        $value = str_replace([' ', '.'], '', trim($value));

        if (preg_match('/^\+?\d{2,31}$/', $value)) {
            return [$value];
        }

        if (! preg_match('/^(\+?)(\d{2,31})-\+?(\d{2,31})$/', $value, $m) || strlen($m[2]) !== strlen($m[3])) {
            return null;
        }

        [$from, $to] = [(int) $m[2], (int) $m[3]];

        if ($to < $from || $to - $from >= self::MAX_RANGE) {
            return null;
        }

        return array_map(
            fn (int $n) => $m[1].str_pad((string) $n, strlen($m[2]), '0', STR_PAD_LEFT),
            range($from, $to),
        );
    }
}
