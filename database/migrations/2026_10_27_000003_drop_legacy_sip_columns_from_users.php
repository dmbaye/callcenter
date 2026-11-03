<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * phone_extensions and queue_memberships replaced the per-user SIP columns
 * (backfilled by 2026_10_06_000002_add_asterisk_tenancy). Dropping them
 * removes the second, globally-unique source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter(
            ['sip_extension', 'sip_secret', 'queue'],
            fn (string $column) => Schema::hasColumn('users', $column),
        ));

        if ($columns === []) {
            return;
        }

        if (in_array('sip_extension', $columns, true)) {
            Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['sip_extension']));
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sip_extension', 20)->nullable()->unique();
            $table->text('sip_secret')->nullable();
            $table->string('queue', 60)->nullable();
        });
    }
};
