<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique();
            $table->string('asterisk_key', 12)->nullable()->unique();
        });

        Schema::create('phone_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('extension', 20);
            $table->text('secret');
            $table->string('label', 60)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['team_id', 'extension']);
            $table->index(['team_id', 'user_id']);
        });

        Schema::create('call_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('asterisk_name', 80)->unique();
            $table->timestamps();
            $table->unique(['team_id', 'name']);
        });

        Schema::create('queue_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('penalty')->default(0);
            $table->timestamps();
            $table->unique(['call_queue_id', 'user_id']);
        });

        // Existing installations may contain teams created before this migration.
        // Generate their immutable tenant identity once; never derive it from the
        // editable team name/slug.
        foreach (Team::query()->withTrashed()->get() as $team) {
            $team->forceFill([
                'uuid' => $team->uuid ?: (string) Str::uuid(),
                'asterisk_key' => $team->asterisk_key ?: $this->uniqueAsteriskKey(),
            ])->saveQuietly();
        }

        // Backfill the current team assignment of the legacy per-user SIP data.
        // A user may belong to multiple teams, but the old columns could only
        // represent one assignment; current_team_id is therefore the only
        // deterministic source for this compatibility migration.
        foreach (User::query()
            ->whereNotNull('sip_extension')
            ->whereNotNull('current_team_id')
            ->get(['id', 'name', 'current_team_id', 'sip_extension', 'sip_secret']) as $user) {
            $exists = Schema::hasTable('phone_extensions')
                && DB::table('phone_extensions')
                    ->where('team_id', $user->current_team_id)
                    ->where('extension', $user->sip_extension)
                    ->exists();

            if (! $exists) {
                DB::table('phone_extensions')->insert([
                    'team_id' => $user->current_team_id,
                    'user_id' => $user->id,
                    'extension' => $user->sip_extension,
                    'secret' => Crypt::encryptString($this->decryptLegacySecret($user->sip_secret)),
                    'label' => $user->name,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Backfill queues from the legacy single queue assignment. The actual
        // Asterisk rows are migrated separately by the maintenance command because
        // the Asterisk database is external to the Laravel application database.
        foreach (Team::query()->withTrashed()->get() as $team) {
            $queueNames = DB::table('users')
                ->where('current_team_id', $team->id)
                ->whereNotNull('queue')
                ->where('queue', '!=', '')
                ->distinct()
                ->pluck('queue');

            foreach ($queueNames as $name) {
                $slug = Str::slug($name);
                $asteriskName = $this->queueAsteriskName($team->asterisk_key, $slug);
                $asteriskName = $this->uniqueQueueName($asteriskName);

                $queueId = DB::table('call_queues')
                    ->where('team_id', $team->id)
                    ->where('name', $name)
                    ->value('id');

                if (! $queueId) {
                    $queueId = DB::table('call_queues')->insertGetId([
                        'team_id' => $team->id,
                        'name' => $name,
                        'asterisk_name' => $asteriskName,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('users')
                    ->where('current_team_id', $team->id)
                    ->where('queue', $name)
                    ->whereNotNull('id')
                    ->get(['id'])
                    ->each(function ($member) use ($queueId) {
                        DB::table('queue_memberships')->insertOrIgnore([
                            'call_queue_id' => $queueId,
                            'user_id' => $member->id,
                            'penalty' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_memberships');
        Schema::dropIfExists('call_queues');
        Schema::dropIfExists('phone_extensions');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['asterisk_key']);
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'asterisk_key']);
        });
    }

    private function decryptLegacySecret(?string $secret): string
    {
        if (! $secret) {
            return '';
        }

        try {
            return Crypt::decryptString($secret);
        } catch (Throwable) {
            return $secret;
        }
    }

    private function uniqueAsteriskKey(): string
    {
        do {
            $key = Str::lower(Str::random(10));
        } while (Schema::hasTable('teams') && DB::table('teams')->where('asterisk_key', $key)->exists());

        return $key;
    }

    private function queueAsteriskName(string $key, string $slug): string
    {
        $slug = trim($slug, '-_');
        $slug = $slug !== '' ? $slug : 'queue';

        return substr($key.'_'.$slug, 0, 80);
    }

    private function uniqueQueueName(string $base): string
    {
        $candidate = $base;
        $i = 2;

        while (DB::table('call_queues')->where('asterisk_name', $candidate)->exists()) {
            $suffix = '_'.$i++;
            $candidate = substr($base, 0, 80 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }
};
