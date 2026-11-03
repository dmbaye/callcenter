<?php

namespace App\Models;

use App\Data\Plan;
use App\Enums\BillingCycle;
use App\Enums\SubscriptionState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $plan
 * @property BillingCycle $billing_cycle
 * @property int $extra_agents
 * @property bool $on_trial
 * @property Carbon $ends_at
 * @property Carbon|null $reminded_for
 * @property Carbon|null $expired_processed_for
 * @property-read Team $team
 */
#[Fillable(['plan', 'billing_cycle', 'extra_agents', 'on_trial', 'ends_at', 'reminded_for', 'expired_processed_for'])]
class Subscription extends Model
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function planDetails(): Plan
    {
        return Plan::find($this->plan) ?? Plan::findOrFail((string) config('billing.trial_plan', 'pro'));
    }

    public function graceEndsAt(): CarbonInterface
    {
        return $this->ends_at->copy()->addDays((int) config('billing.grace_days', 5));
    }

    public function state(): SubscriptionState
    {
        // A free plan does not run out.
        if (! $this->on_trial && Plan::find($this->plan)?->isFree()) {
            return SubscriptionState::Active;
        }

        $now = now();

        if ($now->lt($this->ends_at)) {
            return $this->on_trial ? SubscriptionState::Trialing : SubscriptionState::Active;
        }

        // A trial has no grace period: nothing was paid.
        if (! $this->on_trial && $now->lt($this->graceEndsAt())) {
            return SubscriptionState::Grace;
        }

        return SubscriptionState::Expired;
    }

    public function isFree(): bool
    {
        return ! $this->on_trial && (Plan::find($this->plan)?->isFree() ?? false);
    }

    public function isUsable(): bool
    {
        return $this->state()->isUsable();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'extra_agents' => 'integer',
            'on_trial' => 'boolean',
            'ends_at' => 'datetime',
            'reminded_for' => 'datetime',
            'expired_processed_for' => 'datetime',
        ];
    }
}
