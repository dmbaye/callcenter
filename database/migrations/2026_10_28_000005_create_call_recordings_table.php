<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-queue recording switch, and the "this call may be recorded" notice.
        Schema::table('call_queues', function (Blueprint $table) {
            $table->boolean('record_calls')->default(false);
            $table->boolean('recording_notice')->default(true);
        });

        // Files uploaded by Asterisk after each recorded call.
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('uniqueid', 64);
            $table->string('linkedid', 64)->nullable()->index();
            $table->string('disk', 40);
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('duration')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'uniqueid']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');

        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropColumn(['record_calls', 'recording_notice']);
        });
    }
};
