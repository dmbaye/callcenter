<?php

use App\Enums\TeamRole;
use App\Models\CallQueue;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create minimal Asterisk realtime tables (cdr, queue_members) for tests.
 */
function createAsteriskTables(): void
{
    Schema::create('queue_members', function (Blueprint $table) {
        $table->id('uniqueid');
        $table->string('queue_name');
        $table->string('interface');
        $table->string('membername')->nullable();
        $table->integer('penalty')->default(0);
        $table->integer('paused')->default(0);
        $table->string('reason_paused')->nullable();
    });

    Schema::create('extensions', function (Blueprint $table) {
        $table->id();
        $table->string('context');
        $table->string('exten');
        $table->integer('priority');
        $table->string('app');
        $table->string('appdata')->nullable();
    });

    Schema::create('cdr', function (Blueprint $table) {
        $table->id();
        $table->string('uniqueid')->nullable();
        $table->string('linkedid')->nullable();
        $table->string('clid')->nullable();
        $table->string('src')->nullable();
        $table->string('dst')->nullable();
        $table->string('channel')->nullable();
        $table->string('dstchannel')->nullable();
        $table->string('dcontext')->nullable();
        $table->dateTime('start')->nullable();
        $table->integer('duration')->default(0);
        $table->integer('billsec')->default(0);
        $table->string('disposition')->default('');
        $table->dateTime('answer')->nullable();
        $table->string('accountcode', 20)->nullable();
    });
}

/**
 * Asterisk realtime tables used by provisioning (ps_endpoints/auths/aors, queues).
 */
function createAsteriskProvisioningTables(): void
{
    Schema::create('ps_endpoints', function (Blueprint $table) {
        $table->string('id', 40)->primary();
        $table->string('transport')->nullable();
        $table->string('aors')->nullable();
        $table->string('auth')->nullable();
        $table->string('context')->nullable();
        $table->string('disallow')->nullable();
        $table->string('allow')->nullable();
        $table->string('direct_media')->nullable();
        $table->string('use_avpf')->nullable();
        $table->string('media_encryption')->nullable();
        $table->string('webrtc')->nullable();
        $table->string('accountcode', 20)->nullable();
    });

    Schema::create('ps_auths', function (Blueprint $table) {
        $table->string('id', 40)->primary();
        $table->string('auth_type')->nullable();
        $table->string('username')->nullable();
        $table->string('password')->nullable();
    });

    Schema::create('ps_aors', function (Blueprint $table) {
        $table->string('id', 40)->primary();
        $table->integer('max_contacts')->nullable();
        $table->string('remove_existing')->nullable();
    });

    Schema::create('queues', function (Blueprint $table) {
        $table->string('name', 128)->primary();
        $table->string('context')->nullable();
        $table->string('strategy')->nullable();
        $table->integer('timeout')->nullable();
        $table->string('musiconhold')->nullable();
    });
}

/**
 * A team queue (app row only; add the Asterisk row with createAsteriskProvisioningTables()).
 */
function teamQueue(Team $team, string $name = 'Support'): CallQueue
{
    return $team->callQueues()->create([
        'name' => $name,
        'asterisk_name' => $team->asterisk_key.'_'.Str::slug($name),
    ]);
}

/**
 * A user who works in $team with an extension and the given queues; the
 * team becomes their current team.
 *
 * @param  list<CallQueue>  $queues
 */
function teamAgent(Team $team, string $extension = '7001', TeamRole $role = TeamRole::Member, array $queues = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $team->members()->attach($user, ['role' => $role->value]);
    $user->unsetRelation('teamMemberships');
    $user->switchTeam($team);

    $team->phoneExtensions()->create([
        'user_id' => $user->id,
        'extension' => $extension,
        'secret' => 'secret-'.$extension,
        'label' => $user->name,
        'is_primary' => true,
    ]);

    foreach ($queues as $queue) {
        $queue->members()->attach($user->id, ['penalty' => 0]);
    }

    return $user->fresh();
}

/**
 * A campaign ready to dial in $team (Pro plan, 7/7 calling hours), with its
 * queue and the given agents assigned.
 *
 * @param  list<User>  $agents
 * @param  array<string, mixed>  $attributes
 */
function teamCampaign(Team $team, User $creator, array $agents = [], array $attributes = []): App\Models\Campaign
{
    $campaign = app(App\Services\CampaignManager::class)->create($team, $creator, [
        'name' => 'Relance clients',
        'dial_mode' => App\Enums\DialMode::Progressive,
        'dial_ratio' => 2,
        'max_abandon_rate' => 3,
        'dial_prefix' => '00',
        'caller_id' => '22320000000',
        'ring_timeout' => 25,
        'queue_timeout' => 15,
        'wrapup_seconds' => 30,
        'max_attempts' => 3,
        'timezone' => 'Africa/Bamako',
        'schedule' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '00:00', 'end' => '23:59'],
        ...$attributes,
    ], array_map(fn (User $agent) => $agent->id, $agents), null);

    return $campaign->fresh(['team', 'queue', 'dispositions']);
}

/** @param  list<string>  $phones */
function campaignContacts(App\Models\Campaign $campaign, array $phones): void
{
    foreach ($phones as $phone) {
        $campaign->contacts()->create(['phone' => $phone, 'first_name' => 'Client', 'last_name' => $phone, 'status' => App\Enums\ContactStatus::Pending]);
    }
}
