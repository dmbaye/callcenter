<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // call_center = queues, campaigns, supervision…; internal = extensions
        // calling each other plus video meetings, nothing call-center.
        Schema::table('teams', function (Blueprint $table) {
            $table->string('usage_mode', 20)->default('call_center')->after('is_personal');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
            $table->string('gender', 20)->nullable()->after('avatar_path');
            $table->string('job_title', 100)->nullable()->after('gender');
            $table->string('department', 100)->nullable()->after('job_title');
            $table->string('phone', 30)->nullable()->after('department');
            $table->string('locale', 5)->nullable()->after('phone');
            // {category: {in_app: bool, email: bool}}; missing keys = defaults.
            $table->json('notification_preferences')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'gender', 'job_title', 'department', 'phone', 'locale', 'notification_preferences']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('usage_mode');
        });
    }
};
