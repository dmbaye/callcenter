<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Non prêt" reasons an agent picks from (Déjeuner, Formation…), per team.
        Schema::create('pause_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        // The state each agent chose (Prêt, Non prêt, Post-appel, Ne pas déranger, Déconnecté).
        Schema::create('agent_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state', 20)->default('ready');
            $table->foreignId('pause_reason_id')->nullable()->constrained()->nullOnDelete();
            // Reason name at the time it was chosen, kept if the reason is renamed or deleted.
            $table->string('reason', 60)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        // One row per period spent in a state, for time-in-state reporting.
        Schema::create('agent_state_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state', 20);
            $table->string('reason', 60)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['team_id', 'started_at']);
            $table->index(['team_id', 'user_id', 'ended_at']);
        });

        // Existing teams get the default reasons; new teams get them on creation.
        $now = now();
        $defaults = config('agents.default_pause_reasons', []);

        DB::table('teams')->orderBy('id')->pluck('id')->each(function (int $teamId) use ($defaults, $now) {
            DB::table('pause_reasons')->insert(collect($defaults)->values()->map(fn (string $name, int $index) => [
                'team_id' => $teamId,
                'name' => $name,
                'position' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_state_logs');
        Schema::dropIfExists('agent_states');
        Schema::dropIfExists('pause_reasons');
    }
};
