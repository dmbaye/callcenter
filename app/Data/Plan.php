<?php

namespace App\Data;

use App\Enums\BillingCycle;
use InvalidArgumentException;

/**
 * A subscription plan as defined in config/billing.php.
 */
readonly class Plan
{
    /**
     * @param  array<string, int|null>  $limits
     * @param  array<string, bool>  $features
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $tagline,
        public int $monthlyPrice,
        public bool $highlighted,
        public array $limits,
        public ?int $extraAgentPrice,
        public array $features,
        public string $support,
    ) {}

    /** @return array<string, self> */
    public static function all(): array
    {
        $plans = [];

        foreach ((array) config('billing.plans', []) as $code => $plan) {
            $plans[$code] = self::fromConfig((string) $code, $plan);
        }

        return $plans;
    }

    public static function find(?string $code): ?self
    {
        $plan = $code !== null ? config("billing.plans.{$code}") : null;

        return is_array($plan) ? self::fromConfig($code, $plan) : null;
    }

    public static function findOrFail(string $code): self
    {
        return self::find($code) ?? throw new InvalidArgumentException("Unknown plan [{$code}].");
    }

    /** @param  array<string, mixed>  $plan */
    private static function fromConfig(string $code, array $plan): self
    {
        return new self(
            code: $code,
            name: (string) $plan['name'],
            tagline: (string) ($plan['tagline'] ?? ''),
            monthlyPrice: (int) $plan['monthly_price'],
            highlighted: (bool) ($plan['highlighted'] ?? false),
            limits: (array) ($plan['limits'] ?? []),
            extraAgentPrice: isset($plan['extra_agent_price']) ? (int) $plan['extra_agent_price'] : null,
            features: (array) ($plan['features'] ?? []),
            support: (string) ($plan['support'] ?? ''),
        );
    }

    /** null = unlimited. */
    public function limit(string $key, int $extraAgents = 0): ?int
    {
        $limit = $this->limits[$key] ?? null;

        if ($limit !== null && $key === 'agents' && $this->allowsExtraAgents()) {
            $limit += max(0, $extraAgents);
        }

        return $limit === null ? null : (int) $limit;
    }

    public function allows(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }

    public function allowsExtraAgents(): bool
    {
        return $this->extraAgentPrice !== null && ! $this->isFree();
    }

    /** Price 0: taken without payment and never expires. */
    public function isFree(): bool
    {
        return $this->monthlyPrice === 0;
    }

    /**
     * "Croissance, Pro et Corporate": the paid plans that include a feature,
     * for "included in …" messages that follow config/billing.php.
     */
    public static function namesWith(string $feature): string
    {
        $names = array_values(array_map(
            fn (self $plan) => $plan->name,
            array_filter(self::all(), fn (self $plan) => $plan->allows($feature)),
        ));

        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).' '.__('and').' '.$last;
    }

    /** Monthly price including extra agents. */
    public function monthlyTotal(int $extraAgents = 0): int
    {
        $extra = $this->allowsExtraAgents() ? max(0, $extraAgents) * (int) $this->extraAgentPrice : 0;

        return $this->monthlyPrice + $extra;
    }

    /** Amount charged for one period, rounded to a multiple of 5 (mobile money providers). */
    public function price(BillingCycle $cycle, int $extraAgents = 0): int
    {
        return (int) (round($this->monthlyTotal($extraAgents) * $cycle->monthsCharged() / 5) * 5);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'monthlyPrice' => $this->monthlyPrice,
            'annualPrice' => $this->price(BillingCycle::Annual),
            'highlighted' => $this->highlighted,
            'isFree' => $this->isFree(),
            'limits' => $this->limits,
            'extraAgentPrice' => $this->extraAgentPrice,
            'features' => $this->features,
            'support' => $this->support,
        ];
    }
}
