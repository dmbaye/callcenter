<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional service level tracking: share of answered calls picked up within
 * each queue's threshold (Asterisk « servicelevel »), against a target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('service_level_enabled')->default(false);
            $table->unsignedTinyInteger('service_level_target')->default(80);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['service_level_enabled', 'service_level_target']);
        });
    }
};
