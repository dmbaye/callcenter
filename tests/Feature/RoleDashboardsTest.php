<?php

use App\Enums\TeamRole;
use App\Models\AgentStateLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => createAsteriskTables());

/**
 * A team with an owner, a supervisor and two agents (7001, 7002).
 *
 * @return array{team: Team, owner: User, supervisor: User, agent: User, other: User}
 */
function callCenter(): array
{
    $team = Team::factory()->create();
    $support = teamQueue($team, 'support');

    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $owner->switchTeam($team);

    return [
        'team' => $team,
        'owner' => $owner->fresh(),
        'supervisor' => teamAgent($team, '7000', TeamRole::Supervisor, [$support]),
        'agent' => teamAgent($team, '7001', TeamRole::Member, [$support]),
        'other' => teamAgent($team, '7002', TeamRole::Member, [$support]),
    ];
}

function legs(Team $team, string $linkedid, string $extension, string $disposition, ?string $when = null): void
{
    DB::table('cdr')->insert([
        'uniqueid' => uniqid(),
        'linkedid' => $linkedid,
        'clid' => '"Awa Traoré" <22570000001>',
        'src' => '22570000001',
        'dst' => 's',
        'channel' => 'PJSIP/trunk-0001',
        'dstchannel' => 'PJSIP/'.$team->asterisk_key."_{$extension}-0001",
        'start' => $when ?? now()->toDateTimeString(),
        'duration' => 70,
        'billsec' => $disposition === 'ANSWERED' ? 60 : 0,
        'disposition' => $disposition,
        'accountcode' => $team->asterisk_key,
    ]);
}

test('each role gets its own dashboard', function () {
    ['team' => $team, 'owner' => $owner, 'supervisor' => $supervisor, 'agent' => $agent] = callCenter();

    $this->actingAs($owner)->get("/{$team->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page->component('dashboard/Admin'));

    $this->actingAs($supervisor)->get("/{$team->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page->component('dashboard/Supervisor'));

    $this->actingAs($agent)->get("/{$team->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Agent')
            ->where('teamViewAvailable', false));
});

test('admins and supervisors can open their personal agent view', function () {
    ['team' => $team, 'supervisor' => $supervisor] = callCenter();

    $this->actingAs($supervisor)->get("/{$team->slug}/dashboard?view=agent")
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Agent')
            ->where('teamViewAvailable', true));
});

test('the supervisor dashboard shows team-wide numbers for today', function () {
    ['team' => $team, 'supervisor' => $supervisor] = callCenter();

    legs($team, 'C1', '7001', 'ANSWERED');
    legs($team, 'C2', '7001', 'NO ANSWER');
    legs($team, 'C2', '7002', 'ANSWERED'); // rang agent 1, answered by agent 2 => answered call
    legs($team, 'C3', '7002', 'NO ANSWER'); // nobody answered => missed
    legs($team, 'C4', '7001', 'ANSWERED', now()->subDay()->toDateTimeString()); // yesterday
    legs(Team::factory()->create(), 'C5', '7001', 'ANSWERED'); // same extension number in another team

    $this->actingAs($supervisor)->get("/{$team->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Supervisor')
            ->where('today.total', 3)
            ->where('today.answered', 2)
            ->where('today.missed', 1)
            ->where('today.answer_rate', 67)
            ->where('today.avg_talk_seconds', 60)
            ->has('roster', 3)
            ->has('missedList', 1)
            ->where('missedList.0.name', 'Awa Traoré')
            ->where('missedList.0.number', '22570000001'));
});

test('the admin dashboard adds a 7 day trend and team set-up health', function () {
    ['team' => $team, 'owner' => $owner] = callCenter();

    $nobody = User::factory()->create();
    $team->members()->attach($nobody, ['role' => TeamRole::Member->value]);

    legs($team, 'C1', '7001', 'ANSWERED');
    legs($team, 'C2', '7002', 'NO ANSWER', now()->subDays(3)->toDateTimeString());

    $this->actingAs($owner)->get("/{$team->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Admin')
            ->where('today.total', 1)
            ->where('week.total', 2)
            ->has('daily', 7)
            ->where('daily.6.answered', 1)
            ->where('daily.3.missed', 1)
            ->where('roles.owner', 1)
            ->where('roles.supervisor', 1)
            ->where('roles.member', 3)
            ->where('setup.without_extension', [$nobody->name])
            ->where('queues', ['support']));
});

test('an agent only sees their own calls', function () {
    ['team' => $team, 'agent' => $agent] = callCenter();

    legs($team, 'C1', '7001', 'ANSWERED');
    legs($team, 'C2', '7002', 'ANSWERED');

    $this->actingAs($agent)->get("/{$team->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Agent')
            ->where('totalCalls', 1));
});

test('supervisor sits between member and admin in the role hierarchy', function () {
    expect(TeamRole::Supervisor->isAtLeast(TeamRole::Member))->toBeTrue()
        ->and(TeamRole::Supervisor->isAtLeast(TeamRole::Admin))->toBeFalse()
        ->and(TeamRole::Admin->isAtLeast(TeamRole::Supervisor))->toBeTrue()
        ->and(array_column(TeamRole::assignable(), 'value'))->toContain('supervisor');
});

test('a supervisor can open monitoring but a plain agent cannot', function () {
    ['team' => $team, 'supervisor' => $supervisor, 'agent' => $agent] = callCenter();

    $this->actingAs($agent)->get("/{$team->slug}/monitoring")->assertForbidden();

    // AMI is not configured in tests: the page still renders from queue presence.
    $this->actingAs($supervisor)->get("/{$team->slug}/monitoring")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring/Index')
            ->where('amiAvailable', false)
            ->has('waiting')
            ->has('summary'));
});

test('the agent dashboard sums today\'s time per status and lists today\'s meetings', function () {
    ['team' => $team, 'agent' => $agent, 'owner' => $owner] = callCenter();
    $this->travelTo(now()->setTime(12, 0));

    AgentStateLog::query()->insert([
        // Started yesterday: only the part after midnight counts.
        ['team_id' => $team->id, 'user_id' => $agent->id, 'state' => 'logged_out', 'reason' => null, 'started_at' => now()->subDay(), 'ended_at' => now()->setTime(8, 0)],
        ['team_id' => $team->id, 'user_id' => $agent->id, 'state' => 'ready', 'reason' => null, 'started_at' => now()->setTime(8, 0), 'ended_at' => now()->setTime(10, 0)],
        ['team_id' => $team->id, 'user_id' => $agent->id, 'state' => 'not_ready', 'reason' => 'Déjeuner', 'started_at' => now()->setTime(10, 0), 'ended_at' => now()->setTime(10, 30)],
        ['team_id' => $team->id, 'user_id' => $agent->id, 'state' => 'ready', 'reason' => null, 'started_at' => now()->setTime(10, 30), 'ended_at' => null],
    ]);

    $planned = $team->meetings()->create(['title' => 'Briefing', 'room_name' => 'r1', 'max_participants' => 10, 'created_by' => $owner->id, 'scheduled_at' => now()->setTime(15, 0), 'duration_minutes' => 30]);
    $planned->invitees()->attach($agent);
    $notMine = $team->meetings()->create(['title' => 'Comité', 'room_name' => 'r2', 'max_participants' => 10, 'created_by' => $owner->id, 'scheduled_at' => now()->setTime(16, 0)]);
    $tomorrow = $team->meetings()->create(['title' => 'Demain', 'room_name' => 'r3', 'max_participants' => 10, 'created_by' => $owner->id, 'scheduled_at' => now()->addDay()]);
    $tomorrow->invitees()->attach($agent);

    $this->actingAs($agent)->get("/{$team->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Agent')
            ->where('myDay.0.state', 'logged_out')
            ->where('myDay.0.seconds', 8 * 3600)
            ->where('myDay.1.state', 'ready')
            ->where('myDay.1.seconds', 3 * 3600 + 30 * 60)
            ->where('myDay.2.seconds', 30 * 60)
            ->has('meetings', 1)
            ->where('meetings.0.title', 'Briefing')
            ->where('meetings.0.participants', 2));
});
