<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Answered calls of progressive/power campaigns wait in this queue for an agent.
            $table->foreignId('call_queue_id')->nullable()->constrained('call_queues')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('dial_mode', 20)->default('preview');
            $table->decimal('dial_ratio', 3, 1)->default(1.5);
            $table->decimal('max_abandon_rate', 4, 1)->default(3.0);
            $table->unsignedInteger('max_lines')->nullable();
            $table->string('caller_id', 40)->nullable();
            $table->string('trunk', 80)->nullable();
            $table->string('dial_prefix', 10)->nullable();
            $table->unsignedSmallInteger('ring_timeout')->default(25);
            $table->unsignedSmallInteger('queue_timeout')->default(15);
            $table->unsignedSmallInteger('wrapup_seconds')->default(30);
            $table->boolean('amd_enabled')->default(false);
            $table->string('voicemail_sound', 120)->nullable();
            $table->string('abandon_sound', 120)->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(3);
            // Minutes before a new attempt, per result.
            $table->json('retry_minutes')->nullable();
            $table->string('timezone', 40)->default('Africa/Bamako');
            // {"days":[1,2,3,4,5],"start":"08:00","end":"18:00"}
            $table->json('schedule')->nullable();
            $table->text('script')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        // Wrap-up codes and what they do to the contact.
        Schema::create('campaign_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('outcome', 20);
            $table->unsignedInteger('retry_minutes')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('campaign_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('company', 120)->nullable();
            $table->string('email', 160)->nullable();
            $table->json('data')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_result', 20)->nullable();
            $table->foreignId('disposition_id')->nullable()->constrained('campaign_dispositions')->nullOnDelete();
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_until')->nullable();
            // Personal callback: only this agent gets the contact back.
            $table->foreignId('callback_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'phone']);
            $table->index(['campaign_id', 'status', 'next_attempt_at']);
        });

        Schema::create('campaign_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 20);
            $table->string('phone', 20);
            $table->string('status', 20)->default('dialing');
            $table->string('result', 20)->nullable();
            $table->string('uniqueid', 64)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('disposition_id')->nullable()->constrained('campaign_dispositions')->nullOnDelete();
            $table->timestamp('dispositioned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['campaign_id', 'created_at']);
            $table->index('uniqueid');
            $table->index(['user_id', 'dispositioned_at']);
        });

        // Team-wide Do-Not-Call list, checked at import and before every dial.
        Schema::create('dnc_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('reason', 160)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dnc_numbers');
        Schema::dropIfExists('campaign_attempts');
        Schema::dropIfExists('campaign_contacts');
        Schema::dropIfExists('campaign_dispositions');
        Schema::dropIfExists('campaigns');
    }
};
