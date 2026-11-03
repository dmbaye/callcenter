<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // The plan's longest meeting, fixed when the meeting starts (null = no limit).
            $table->unsignedSmallInteger('time_limit_minutes')->nullable()->after('duration_minutes');
            // host (Terminer pour tous), limit (plan time limit), empty (everyone left).
            $table->string('ended_reason', 20)->nullable()->after('ended_at');

            // Shared meeting notes, editable before, during and after the meeting.
            $table->longText('notes')->nullable()->after('description');
            $table->timestamp('notes_updated_at')->nullable()->after('notes');
            $table->foreignId('notes_updated_by')->nullable()->after('notes_updated_at')->constrained('users')->nullOnDelete();

            $table->index(['started_at', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['started_at', 'ended_at']);
            $table->dropConstrainedForeignId('notes_updated_by');
            $table->dropColumn(['time_limit_minutes', 'ended_reason', 'notes', 'notes_updated_at']);
        });
    }
};
