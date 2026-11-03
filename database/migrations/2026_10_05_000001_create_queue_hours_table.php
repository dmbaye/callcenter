<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_hours', function (Blueprint $table) {
            $table->id();
            $table->string('queue_name');
            $table->unsignedTinyInteger('weekday'); // 0 = Monday ... 6 = Sunday
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->unique(['queue_name', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_hours');
    }
};
