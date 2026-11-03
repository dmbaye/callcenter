<?php

use App\Models\PlatformSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskDialplan;
use App\Services\OutboundTrunk;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    DB::table('ps_endpoints')->insert([
        ['id' => 'test_trunk', 'aors' => 'test_trunk', 'context' => 'cc-inbound', 'webrtc' => 'no'],
        ['id' => 'orange', 'aors' => 'orange', 'context' => 'cc-inbound', 'webrtc' => 'no'],
        ['id' => 'abcdefghij_7001', 'aors' => 'abcdefghij_7001', 'context' => 't_abcdefghij', 'webrtc' => 'yes'],
    ]);
});

it('routes outside numbers of a team to the shared outbound context', function () {
    $team = Team::factory()->create();

    app(AsteriskDialplan::class)->ensureOutboundDialing($team);

    $rows = DB::table('extensions')
        ->where('context', $team->asteriskContext())
        ->where('exten', AsteriskDialplan::OUTBOUND_PATTERN)
        ->orderBy('priority')
        ->pluck('appdata', 'app')
        ->all();

    expect($rows)->toMatchArray([
        'Set' => 'CHANNEL(accountcode)='.$team->asterisk_key,
        'Goto' => 'cc-outbound,${EXTEN},1',
    ]);
});

it('lists only trunks, not agent softphones', function () {
    expect(array_column(app(OutboundTrunk::class)->candidates(), 'id'))->toBe(['orange', 'test_trunk']);
});

it('lets only platform admins open the platform page', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.platform'))->assertForbidden();

    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)->get(route('admin.platform'))->assertOk();
});

it('saves the trunk and rewrites the outbound context', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    Team::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.platform.trunk'), ['trunk' => 'test_trunk'])
        ->assertSessionHasNoErrors();

    expect(PlatformSetting::get(PlatformSetting::OUTBOUND_TRUNK))->toBe('test_trunk')
        ->and(app(OutboundTrunk::class)->name())->toBe('test_trunk')
        ->and(DB::table('extensions')->where('context', 'cc-outbound')->where('priority', 1)->value('appdata'))
        ->toBe('PJSIP/00${EXTEN:2}@test_trunk,60');
});

it('refuses an endpoint that is not a trunk', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)
        ->put(route('admin.platform.trunk'), ['trunk' => 'abcdefghij_7001'])
        ->assertSessionHasErrors('trunk');

    expect(PlatformSetting::get(PlatformSetting::OUTBOUND_TRUNK))->toBeNull();
});

it('falls back to ASTERISK_CAMPAIGN_TRUNK until a trunk is saved', function () {
    config(['asterisk.campaigns.trunk' => 'orange']);

    expect(app(OutboundTrunk::class)->name())->toBe('orange');
});
