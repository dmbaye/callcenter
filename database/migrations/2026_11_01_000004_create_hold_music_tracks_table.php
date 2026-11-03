<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hold music uploaded by a team, played by Asterisk from a signed URL
        // (musiconhold class moh_{team key}, playlist mode).
        Schema::create('hold_music_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('disk', 40);
            $table->string('path');
            $table->unsignedInteger('size');
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_music_tracks');
    }
};
