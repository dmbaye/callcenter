<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->index(['team_id', 'role'], 'team_members_team_role_index');
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->index(
                ['email', 'accepted_at', 'expires_at'],
                'team_invitations_email_status_expiry_index'
            );
        });

        Schema::table('call_outcomes', function (Blueprint $table) {
            $table->index(
                ['user_id', 'started_at'],
                'call_outcomes_user_started_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('call_outcomes', function (Blueprint $table) {
            $table->dropIndex('call_outcomes_user_started_index');
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropIndex('team_invitations_email_status_expiry_index');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->dropIndex('team_members_team_role_index');
        });
    }
};
