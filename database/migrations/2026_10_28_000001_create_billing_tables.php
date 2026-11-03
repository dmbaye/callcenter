<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per team: the plan it is on and until when it is paid.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan', 30);
            $table->string('billing_cycle', 10)->default('monthly');
            $table->unsignedInteger('extra_agents')->default(0);
            $table->boolean('on_trial')->default(false);
            $table->timestamp('ends_at');
            // Bookkeeping for the daily billing:process run (one mail per period).
            $table->timestamp('reminded_for')->nullable();
            $table->timestamp('expired_processed_for')->nullable();
            $table->timestamps();

            $table->index('ends_at');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_id', 40)->unique();
            $table->string('plan', 30);
            $table->string('billing_cycle', 10);
            $table->unsignedInteger('extra_agents')->default(0);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 40)->nullable();
            $table->string('operator_id', 80)->nullable();
            $table->text('payment_url')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Existing teams start on the trial so nothing stops working on deploy.
        $plan = config('billing.trial_plan', 'pro');
        $endsAt = now()->addDays((int) config('billing.trial_days', 14));

        DB::table('teams')->whereNull('deleted_at')->orderBy('id')->select('id')
            ->chunkById(500, function ($teams) use ($plan, $endsAt): void {
                DB::table('subscriptions')->insert($teams->map(fn ($team) => [
                    'team_id' => $team->id,
                    'plan' => $plan,
                    'billing_cycle' => 'monthly',
                    'extra_agents' => 0,
                    'on_trial' => true,
                    'ends_at' => $endsAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
    }
};
