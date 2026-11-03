<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();

    $this->team = Team::factory()->create();
    $this->agent = teamAgent($this->team, '7001');
});

function cdr(Team $team, string $linkedid, string $ext, string $disposition, ?string $when = null, bool $outbound = false): void
{
    $agentChannel = 'PJSIP/'.$team->asterisk_key."_{$ext}-0001";

    DB::table('cdr')->insert([
        'uniqueid' => uniqid(),
        'linkedid' => $linkedid,
        'clid' => '"Alice" <5551234>',
        'src' => '5551234',
        'dst' => 's',
        'channel' => $outbound ? $agentChannel : 'PJSIP/trunk-0001',
        'dstchannel' => $outbound ? 'PJSIP/trunk-0001' : $agentChannel,
        'start' => $when ?? now()->toDateTimeString(),
        'billsec' => $disposition === 'ANSWERED' ? 60 : 0,
        'duration' => 70,
        'disposition' => $disposition,
        'accountcode' => $team->asterisk_key,
    ]);
}

test('the agent dashboard counts distinct calls of the agent extension in both directions', function () {
    cdr($this->team, 'L1', '7001', 'ANSWERED');
    cdr($this->team, 'L1', '7001', 'ANSWERED'); // second leg of same call
    cdr($this->team, 'L2', '7001', 'NO ANSWER');
    cdr($this->team, 'L3', '70010', 'ANSWERED'); // different extension (prefix lookalike)
    cdr($this->team, 'L4', '7002', 'ANSWERED');
    cdr($this->team, 'L5', '7001', 'ANSWERED', now()->subDays(2)->toDateTimeString()); // not today
    cdr($this->team, 'L6', '7001', 'ANSWERED', outbound: true);

    $this->actingAs($this->agent)
        ->get(route('dashboard', $this->team).'?view=agent')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Agent')
            ->where('totalCalls', 3)
            ->where('answeredCalls', 2)
            ->where('missedCalls', 1)
            ->where('avgTalkSeconds', 60)
            // Lists show incoming calls only, one row per call.
            ->has('recentCalls', 2)
            ->where('recentCalls.0.name', 'Alice')
            ->has('missedList', 1),
        );
});

test('the agent figures match the admin roster for the same agent', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => 'owner']);

    cdr($this->team, 'L1', '7001', 'ANSWERED');
    cdr($this->team, 'L1', '7001', 'ANSWERED');
    cdr($this->team, 'L2', '7001', 'NO ANSWER');

    $this->actingAs($owner)
        ->get(route('dashboard', $this->team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Admin')
            ->where('roster.0.answered', 1)
            ->where('roster.0.missed', 1),
        );
});

test('calls page shows only the agent calls and supports search', function () {
    cdr($this->team, 'L1', '7001', 'ANSWERED');
    cdr($this->team, 'L2', '7002', 'ANSWERED');

    $this->actingAs($this->agent)
        ->get(route('calls', $this->team).'?search=5551')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('calls/Index')
            ->where('calls.total', 1),
        );
});

test('calls page rejects an invalid date filter instead of crashing', function () {
    $this->actingAs($this->agent)
        ->get(route('calls', $this->team).'?from=not-a-date')
        ->assertSessionHasErrors('from');
});
