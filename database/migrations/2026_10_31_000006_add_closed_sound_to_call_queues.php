<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The message played outside opening hours and on closure days, per queue. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->string('closed_sound', 120)->nullable()->after('emergency_sound');
        });
    }

    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropColumn('closed_sound');
        });
    }
};
