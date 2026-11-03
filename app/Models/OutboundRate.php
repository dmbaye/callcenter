<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Price per minute of outside calls (XOF), from `effective_from` until the
 * next rate. Set by platform admins (Administration → Plateforme).
 *
 * @property int $id
 * @property int $price_per_minute
 * @property CarbonInterface $effective_from
 * @property int|null $created_by
 * @property-read User|null $author
 */
#[Fillable(['price_per_minute', 'effective_from', 'created_by'])]
class OutboundRate extends Model
{
    /** Used when no rate exists at all (fresh database). */
    public const DEFAULT_PRICE = 100;

    protected function casts(): array
    {
        return [
            'price_per_minute' => 'integer',
            'effective_from' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Every rate, oldest first (a handful of rows). */
    public static function schedule(): Collection
    {
        return static::query()->orderBy('effective_from')->orderBy('id')->get();
    }

    /** The price in force at a moment, in a schedule loaded once. */
    public static function priceAt(Collection $schedule, CarbonInterface $at): int
    {
        $rate = $schedule->filter(fn (self $rate) => $rate->effective_from->lte($at))->last();

        return $rate?->price_per_minute ?? self::DEFAULT_PRICE;
    }

    public static function current(): int
    {
        return static::priceAt(static::schedule(), now());
    }
}
