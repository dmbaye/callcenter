<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The DIDs bought from the SIP trunk provider (Orange Mali…), handed
        // out to teams automatically. team_id null = free.
        Schema::create('pool_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('note', 120)->nullable();
            // A deleted team gives its number back to the pool.
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'id']);
        });

        // 'pool' = taken from pool_numbers, 'own' = the customer's own number
        // (ported or forwarded to the trunk).
        Schema::table('inbound_numbers', function (Blueprint $table) {
            $table->string('source', 10)->default('own')->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_numbers', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::dropIfExists('pool_numbers');
    }
};
