<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which provider took the payment, and its own id for it (PayDunya invoice token).
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway', 20)->default('cinetpay')->after('transaction_id');
            $table->string('gateway_reference', 100)->nullable()->after('gateway');

            $table->index(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway', 'gateway_reference']);
            $table->dropColumn(['gateway', 'gateway_reference']);
        });
    }
};
