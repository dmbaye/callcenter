<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Call outcomes are recorded in a team: they are listed and edited only
 * within that team. Existing rows are attributed to the agent's current team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_outcomes', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('users')->whereNotNull('current_team_id')->orderBy('id')->get(['id', 'current_team_id'])
            ->each(fn ($user) => DB::table('call_outcomes')
                ->where('user_id', $user->id)
                ->whereNull('team_id')
                ->update(['team_id' => $user->current_team_id]));

        Schema::table('call_outcomes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'caller', 'started_at']);
            $table->unique(['team_id', 'user_id', 'caller', 'started_at']);
            $table->index(['team_id', 'started_at'], 'call_outcomes_team_started_index');
        });
    }

    public function down(): void
    {
        Schema::table('call_outcomes', function (Blueprint $table) {
            $table->dropIndex('call_outcomes_team_started_index');
            $table->dropUnique(['team_id', 'user_id', 'caller', 'started_at']);
            $table->unique(['user_id', 'caller', 'started_at']);
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
