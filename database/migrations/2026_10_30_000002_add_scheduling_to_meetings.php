<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A meeting without scheduled_at is instant (started from "Réunion instantanée").
        Schema::table('meetings', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->timestamp('scheduled_at')->nullable()->after('max_participants');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('scheduled_at');
            $table->timestamp('reminded_at')->nullable()->after('duration_minutes');
            $table->timestamp('cancelled_at')->nullable()->after('ended_at');
            // iCalendar SEQUENCE: bumped on each change so calendars replace the event.
            $table->unsignedInteger('calendar_sequence')->default(0)->after('cancelled_at');

            $table->index(['team_id', 'scheduled_at']);
            $table->index(['scheduled_at', 'reminded_at']);
        });

        // In-app notifications (bell in the header).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'scheduled_at']);
            $table->dropIndex(['scheduled_at', 'reminded_at']);
            $table->dropColumn(['description', 'scheduled_at', 'duration_minutes', 'reminded_at', 'cancelled_at', 'calendar_sequence']);
        });
    }
};
