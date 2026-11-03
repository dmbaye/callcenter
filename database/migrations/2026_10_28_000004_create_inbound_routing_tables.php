<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What happens around Queue(): wait limit, overflow, callback, survey, emergency.
        Schema::table('call_queues', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_wait')->nullable();
            $table->string('overflow_type', 20)->nullable();
            $table->string('overflow_target', 120)->nullable();
            $table->boolean('callback_offer')->default(false);
            $table->boolean('survey_enabled')->default(false);
            $table->boolean('emergency_closed')->default(false);
            $table->string('emergency_sound', 120)->nullable();
        });

        // Holidays and exceptional closures (whole days).
        Schema::create('queue_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('label', 120)->nullable();
            $table->timestamps();

            $table->unique(['call_queue_id', 'date']);
        });

        Schema::create('ivr_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('greeting_sound', 120);
            $table->string('invalid_sound', 120)->nullable();
            $table->unsignedTinyInteger('timeout')->default(5);
            $table->unsignedTinyInteger('max_retries')->default(3);
            // [{"digit":"1","label":"Ventes","type":"queue","target":"12"}, …]
            $table->json('options');
            $table->string('fallback_type', 20)->default('hangup');
            $table->string('fallback_target', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('inbound_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // As the SIP provider sends it in the request URI (the trunk's context is cc-inbound).
            $table->string('number', 32)->unique();
            $table->string('label', 80)->nullable();
            $table->string('destination_type', 20);
            $table->string('destination_target', 120)->nullable();
            $table->timestamps();
        });

        // Post-call satisfaction (1-5) keyed in by the caller.
        Schema::create('call_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_queue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('uniqueid', 64)->nullable()->unique();
            $table->string('caller', 32)->nullable();
            $table->unsignedTinyInteger('score');
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });

        // "outbound" campaigns, or the callback list of an inbound queue.
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('kind', 20)->default('outbound')->after('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::dropIfExists('call_surveys');
        Schema::dropIfExists('inbound_numbers');
        Schema::dropIfExists('ivr_menus');
        Schema::dropIfExists('queue_closures');
        Schema::table('call_queues', fn (Blueprint $table) => $table->dropColumn([
            'max_wait', 'overflow_type', 'overflow_target', 'callback_offer', 'survey_enabled', 'emergency_closed', 'emergency_sound',
        ]));
    }
};
