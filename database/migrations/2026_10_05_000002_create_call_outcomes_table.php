<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('caller', 40);
            $table->string('direction', 10);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('outcome', 30);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'caller', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_outcomes');
    }
};
