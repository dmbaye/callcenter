<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce planning: shift templates, the roster, shift swaps, timesheets
 * (clock in/out, breaks, adjustments, weekly validation) and leave.
 *
 * Times: `date` and `starts_at` / `ends_at` are wall-clock times in the
 * workforce timezone (config/workforce.php). A shift ending at 00:00 ends
 * on the next day.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Team rules (null = config/workforce.php defaults).
        Schema::create('workforce_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('max_consecutive_hours')->nullable();
            $table->unsignedSmallInteger('min_rest_hours')->nullable();
            $table->unsignedSmallInteger('min_rest_after_long_hours')->nullable();
            $table->unsignedSmallInteger('max_weekly_hours')->nullable();
            // block = refuse an assignment or swap breaking a rule, warn = allow and flag it.
            $table->string('enforcement', 10)->nullable();
            $table->boolean('auto_approve_swaps')->default(false);
            // Lateness tolerated before an arrival is flagged late.
            $table->unsignedSmallInteger('late_tolerance_minutes')->nullable();
            $table->timestamps();
        });

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name', 60);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            // Coverage alert below this number of agents.
            $table->unsignedSmallInteger('min_agents')->default(0);
            $table->string('color', 20)->default('blue');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'code']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('note', 200)->nullable();
            // Set when a rule was broken on purpose (enforcement = warn).
            $table->json('warnings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'team_id', 'starts_at']);
            $table->index(['team_id', 'date']);
            $table->index(['user_id', 'starts_at']);
        });

        Schema::create('shift_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requester_assignment_id')->constrained('shift_assignments')->cascadeOnDelete();
            $table->foreignId('counterparty_id')->constrained('users')->cascadeOnDelete();
            // Null: the colleague takes the shift without giving one back.
            $table->foreignId('counterparty_assignment_id')->nullable()->constrained('shift_assignments')->cascadeOnDelete();
            // pending_counterparty → declined | pending_supervisor → approved | rejected; cancelled, expired.
            $table->string('status', 24);
            $table->string('message', 500)->nullable();
            $table->timestamp('counterparty_answered_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->boolean('auto_approved')->default(false);
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        // One work session (clock in → clock out). Several per day are possible.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            // manual = created or changed by an approved adjustment.
            $table->string('source', 10)->default('clock');
            $table->timestamps();

            $table->index(['team_id', 'date']);
            $table->index(['user_id', 'date']);
        });

        Schema::create('time_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
        });

        // Agent's correction of a session (or a forgotten one), reviewed by a supervisor.
        Schema::create('time_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('reason', 500);
            // Values before the change, for the audit trail.
            $table->json('previous')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        // A supervisor validated an agent's week: it can go to payroll.
        Schema::create('timesheet_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'week_start']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 60);
            $table->boolean('paid')->default(true);
            // Days per calendar year; null = no balance (not counted).
            $table->decimal('annual_days', 5, 1)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            // Partial leave: one day, between these times (null = full days).
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            // Counted against the balance (partial = hours / 8).
            $table->decimal('days', 5, 2);
            $table->string('reason', 500)->nullable();
            // pending → approved | rejected; cancelled by the agent.
            $table->string('status', 12)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        foreach (['leave_requests', 'leave_types', 'timesheet_approvals', 'time_adjustments', 'time_breaks', 'time_entries', 'shift_swaps', 'shift_assignments', 'shift_templates', 'workforce_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
