<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supervisor in charge of a member: reviews their swaps, leave,
 * timesheet corrections and validates their weeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'supervisor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'supervisor_id']);
            $table->dropConstrainedForeignId('supervisor_id');
        });
    }
};
