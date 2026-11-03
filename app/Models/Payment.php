<?php

namespace App\Models;

use App\Data\Plan;
use App\Enums\BillingCycle;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A hosted checkout (PayDunya, CinetPay) for one subscription period. The
 * row is written before the customer is sent to the provider; its status
 * only ever changes after a server-side check with that provider.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $transaction_id
 * @property string $gateway
 * @property string|null $gateway_reference
 * @property string $plan
 * @property BillingCycle $billing_cycle
 * @property int $extra_agents
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $payment_method
 * @property string|null $operator_id
 * @property string|null $payment_url
 * @property string|null $failure_reason
 * @property Carbon|null $paid_at
 * @property Carbon|null $period_ends_at
 * @property Carbon $created_at
 * @property-read Team $team
 * @property-read User|null $user
 */
#[Fillable([
    'user_id', 'transaction_id', 'gateway', 'gateway_reference', 'plan', 'billing_cycle', 'extra_agents', 'amount', 'currency',
    'status', 'payment_method', 'operator_id', 'payment_url', 'failure_reason', 'paid_at', 'period_ends_at',
])]
#[Hidden(['payment_url'])]
class Payment extends Model
{
    /** Our reference: letters and digits only (some providers reject other characters). */
    public static function newTransactionId(): string
    {
        return 'CC'.Str::upper((string) Str::ulid());
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planDetails(): ?Plan
    {
        return Plan::find($this->plan);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'extra_agents' => 'integer',
            'paid_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }
}
