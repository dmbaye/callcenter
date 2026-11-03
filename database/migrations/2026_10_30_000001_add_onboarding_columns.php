<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when the owner finishes (or skips) the welcome wizard of a new team.
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable()->after('profile_completed_at');
        });

        // Set when the user finishes (or closes) the guided tour of the app.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tour_completed_at')->nullable()->after('current_team_id');
        });

        // Existing teams and users already know the app: no wizard, no tour
        // (the tour can still be started from the help center).
        DB::table('teams')->update(['onboarded_at' => now()]);
        DB::table('users')->update(['tour_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tour_completed_at');
        });
    }
};
