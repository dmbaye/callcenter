<?php

use App\Models\OutboundRate;
use App\Models\Team;
use App\Models\User;
use App\Services\OutboundUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
    $this->team = Team::factory()->create();
    $this->key = $this->team->asterisk_key;
    $this->from = Carbon::parse('2026-11-01 00:00:00');
    $this->to = Carbon::parse('2026-11-30 23:59:59');
});

function cdrRow(array $row): void
{
    DB::table('cdr')->insert([
        'uniqueid' => uniqid('', true),
        'linkedid' => null,
        'clid' => '',
        'src' => '',
        'dst' => '',
        'dcontext' => '',
        'disposition' => 'ANSWERED',
        'duration' => $row['billsec'] ?? 0,
        ...$row,
    ]);
}

it('meters a manual outside call per started minute at 100 F', function () {
    cdrRow([
        'accountcode' => $this->key,
        'channel' => 'PJSIP/'.$this->key.'_7001-00000001',
        'dstchannel' => 'PJSIP/test_trunk-00000002',
        'dst' => '0022376123456',
        'dcontext' => 'cc-outbound',
        'start' => '2026-11-02 10:00:00',
        'answer' => '2026-11-02 10:00:05',
        'billsec' => 61,
    ]);

    $calls = app(OutboundUsage::class)->calls($this->team, $this->from, $this->to);

    expect($calls)->toHaveCount(1)
        ->and($calls[0])->toMatchArray([
            'number' => '+22376123456',
            'extension' => '7001',
            'seconds' => 61,
            'minutes' => 2,
            'rate' => 100,
            'cost' => 200,
            'campaign' => false,
        ]);
});

it('does not meter calls coming in from the carrier or between colleagues', function () {
    cdrRow([
        'accountcode' => $this->key,
        'channel' => 'PJSIP/test_trunk-00000003',
        'dstchannel' => 'PJSIP/'.$this->key.'_7001-00000004',
        'dcontext' => 't_'.$this->key,
        'start' => '2026-11-02 11:00:00',
        'answer' => '2026-11-02 11:00:02',
        'billsec' => 120,
    ]);
    cdrRow([
        'accountcode' => $this->key,
        'channel' => 'PJSIP/'.$this->key.'_7001-00000005',
        'dstchannel' => 'PJSIP/'.$this->key.'_7002-00000006',
        'dcontext' => 't_'.$this->key,
        'start' => '2026-11-02 12:00:00',
        'answer' => '2026-11-02 12:00:02',
        'billsec' => 120,
    ]);

    expect(app(OutboundUsage::class)->calls($this->team, $this->from, $this->to))->toBe([]);
});

it('counts a progressive campaign call once, from first answer to last hang-up', function () {
    foreach ([['10:00:00', 30, '7001'], ['10:00:20', 50, '7002']] as [$answer, $billsec, $ext]) {
        cdrRow([
            'accountcode' => $this->key,
            'channel' => 'PJSIP/test_trunk-00000009',
            'dstchannel' => 'PJSIP/'.$this->key.'_'.$ext.'-0000000a',
            'src' => '22376000001',
            'dcontext' => 'cc-campaign',
            'start' => '2026-11-03 09:59:50',
            'answer' => '2026-11-03 '.$answer,
            'billsec' => $billsec,
        ]);
    }

    $calls = app(OutboundUsage::class)->calls($this->team, $this->from, $this->to);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['seconds'])->toBe(70)
        ->and($calls[0]['minutes'])->toBe(2)
        ->and($calls[0]['campaign'])->toBeTrue()
        ->and($calls[0]['number'])->toBe('+22376000001');
});

it('prices each call at the rate in force when it was answered', function () {
    OutboundRate::query()->create(['price_per_minute' => 150, 'effective_from' => '2026-11-10 00:00:00']);

    foreach (['2026-11-05', '2026-11-15'] as $i => $day) {
        cdrRow([
            'accountcode' => $this->key,
            'channel' => 'PJSIP/'.$this->key.'_7001-0000001'.$i,
            'dstchannel' => 'PJSIP/test_trunk-0000002'.$i,
            'dst' => '0022376123456',
            'start' => $day.' 10:00:00',
            'answer' => $day.' 10:00:00',
            'billsec' => 60,
        ]);
    }

    $summary = app(OutboundUsage::class)->summary($this->team, $this->from, $this->to);

    expect($summary)->toMatchArray(['calls' => 2, 'minutes' => 2, 'billable_minutes' => 2, 'amount' => 250]);
});

it('lets a platform admin change the price without repricing the past', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)
        ->put(route('admin.platform.rate'), ['price_per_minute' => 125])
        ->assertSessionHasNoErrors();

    expect(OutboundRate::current())->toBe(125)
        ->and(OutboundRate::query()->count())->toBe(2);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.platform.rate'), ['price_per_minute' => 1])
        ->assertForbidden();
});
