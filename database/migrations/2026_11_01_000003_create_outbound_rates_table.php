<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price per minute of outside calls, with its history: a call is priced
        // at the rate in force when it was answered, so a new price never
        // changes calls already made.
        Schema::create('outbound_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('price_per_minute');
            $table->timestamp('effective_from')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('outbound_rates')->insert([
            'price_per_minute' => 100,
            'effective_from' => '2000-01-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_rates');
    }
};
