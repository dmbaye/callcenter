<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskProvisioner;
use App\Services\AsteriskQueueMembers;
use App\Services\CallStats;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();
});

function tenantTeam(string $name): Team
{
    return Team::factory()->create(['name' => $name]);
}

function tenantAgent(Team $team, string $extension, string $queueName = 'Support'): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $team->phoneExtensions()->create([
        'user_id' => $user->id,
        'extension' => $extension,
        'secret' => 'secret-'.$extension,
        'label' => $user->name,
        'is_primary' => true,
    ]);

    $queue = $team->callQueues()->create([
        'name' => $queueName,
        'asterisk_name' => $team->asterisk_key.'_'.strtolower($queueName),
    ]);
    $queue->members()->attach($user, ['penalty' => 0]);

    return $user->fresh();
}

test('teams receive immutable short Asterisk keys and endpoint ids stay within realtime limits', function () {
    $team = tenantTeam('Alpha');
    $user = tenantAgent($team, '7001');
    $extension = $user->phoneExtensionFor($team);

    expect($team->asterisk_key)->toMatch('/^[a-z0-9]{10}$/')
        ->and($extension->asteriskName())->toBe($team->asterisk_key.'_7001')
        ->and(strlen($extension->asteriskName()))->toBeLessThanOrEqual(40);
});

test('the same human extension can exist independently in two teams', function () {
    $a = tenantTeam('Alpha');
    $b = tenantTeam('Beta');

    $one = tenantAgent($a, '7001');
    $two = tenantAgent($b, '7001');

    expect($one->phoneExtensionFor($a)->extension)->toBe('7001')
        ->and($two->phoneExtensionFor($b)->extension)->toBe('7001')
        ->and($one->phoneExtensionFor($a)->asteriskName())
        ->not->toBe($two->phoneExtensionFor($b)->asteriskName());
});

test('queue login writes direct PJSIP members for every queue membership in the tenant', function () {
    $team = tenantTeam('Alpha');
    $user = tenantAgent($team, '7001', 'Support');
    $sales = $team->callQueues()->create([
        'name' => 'Sales',
        'asterisk_name' => $team->asterisk_key.'_sales',
    ]);
    $sales->members()->attach($user, ['penalty' => 5]);

    app(AsteriskQueueMembers::class)->join($user);

    expect(DB::table('queue_members')->pluck('interface')->unique()->all())
        ->toBe(['PJSIP/'.$team->asterisk_key.'_7001']);

    expect(DB::table('queue_members')->pluck('queue_name')->sort()->values()->all())
        ->toBe([$team->asterisk_key.'_sales', $team->asterisk_key.'_support']);
});

test('call statistics are isolated by tenant accountcode', function () {
    $team = tenantTeam('Alpha');
    tenantAgent($team, '7001');
    $other = tenantTeam('Beta');
    tenantAgent($other, '7001');

    DB::table('cdr')->insert([
        'uniqueid' => 'alpha-1', 'linkedid' => 'alpha-call', 'clid' => 'Alpha <1>', 'src' => '1',
        'channel' => 'PJSIP/trunk-1', 'dstchannel' => 'PJSIP/'.$team->asterisk_key.'_7001-1',
        'start' => now(), 'billsec' => 10, 'disposition' => 'ANSWERED', 'accountcode' => $team->asterisk_key,
    ]);
    DB::table('cdr')->insert([
        'uniqueid' => 'beta-1', 'linkedid' => 'beta-call', 'clid' => 'Beta <2>', 'src' => '2',
        'channel' => 'PJSIP/trunk-2', 'dstchannel' => 'PJSIP/'.$other->asterisk_key.'_7001-2',
        'start' => now(), 'billsec' => 20, 'disposition' => 'ANSWERED', 'accountcode' => $other->asterisk_key,
    ]);

    $legs = app(CallStats::class)->legs($team, Carbon::now()->startOfDay(), Carbon::now()->endOfDay());

    expect($legs)->toHaveCount(1)
        ->and($legs->first()['extension'])->toBe('7001');
});

test('PJSIP provisioning uses the full endpoint id as auth username and team accountcode', function () {
    $team = tenantTeam('Alpha');
    $user = tenantAgent($team, '7001');

    app(AsteriskProvisioner::class)->saveExtension($team, '7001', ['max_contacts' => 2]);

    $id = $user->phoneExtensionFor($team)->asteriskName();

    expect(DB::table('ps_endpoints')->where('id', $id)->value('accountcode'))->toBe($team->asterisk_key)
        ->and(DB::table('ps_endpoints')->where('id', $id)->value('context'))->toBe($team->asteriskContext())
        ->and(DB::table('ps_auths')->where('id', $id)->value('username'))->toBe($id)
        ->and(DB::table('ps_auths')->where('id', $id)->value('password'))->toBe('secret-7001')
        ->and((int) DB::table('ps_aors')->where('id', $id)->value('max_contacts'))->toBe(2);
});

test('deleting a team removes its tenant from Asterisk only', function () {
    $team = tenantTeam('Alpha');
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    tenantAgent($team, '7001');
    $other = tenantTeam('Beta');
    tenantAgent($other, '7001');

    $provisioner = app(AsteriskProvisioner::class);
    $provisioner->provisionTeam($team);
    $provisioner->provisionTeam($other);
    app(AsteriskQueueMembers::class)->join($team->members()->where('users.id', '!=', $owner->id)->first(), $team);

    $this->actingAs($owner)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertRedirect();

    expect(DB::table('ps_endpoints')->pluck('id')->all())->toBe([$other->asterisk_key.'_7001'])
        ->and(DB::table('queue_members')->count())->toBe(0)
        ->and(DB::table('extensions')->where('context', $team->asteriskContext())->count())->toBe(0)
        ->and(DB::table('extensions')->where('context', $other->asteriskContext())->count())->toBeGreaterThan(0);
});
