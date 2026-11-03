<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opening hours belong to a team queue (call_queues), not to a free-form
 * Asterisk queue name: they cascade with the queue and cannot leak across
 * tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_hours', function (Blueprint $table) {
            $table->foreignId('call_queue_id')->nullable()->after('id')->constrained('call_queues')->cascadeOnDelete();
        });

        DB::table('queue_hours')->orderBy('id')->get(['id', 'queue_name'])->each(function ($row) {
            $queueId = DB::table('call_queues')->where('asterisk_name', $row->queue_name)->value('id');

            $queueId === null
                ? DB::table('queue_hours')->where('id', $row->id)->delete()
                : DB::table('queue_hours')->where('id', $row->id)->update(['call_queue_id' => $queueId]);
        });

        Schema::table('queue_hours', function (Blueprint $table) {
            $table->dropUnique(['queue_name', 'weekday']);
        });

        Schema::table('queue_hours', function (Blueprint $table) {
            $table->dropColumn('queue_name');
            $table->unique(['call_queue_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::table('queue_hours', function (Blueprint $table) {
            $table->string('queue_name')->nullable()->after('id');
        });

        DB::table('queue_hours')->orderBy('id')->get(['id', 'call_queue_id'])->each(function ($row) {
            DB::table('queue_hours')->where('id', $row->id)->update([
                'queue_name' => DB::table('call_queues')->where('id', $row->call_queue_id)->value('asterisk_name'),
            ]);
        });

        Schema::table('queue_hours', function (Blueprint $table) {
            $table->dropUnique(['call_queue_id', 'weekday']);
            $table->dropConstrainedForeignId('call_queue_id');
            $table->unique(['queue_name', 'weekday']);
        });
    }
};
