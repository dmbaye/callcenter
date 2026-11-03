<?php

use App\Enums\TeamRole;
use App\Models\LeaveRequest;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwap;
use App\Models\ShiftTemplate;
use App\Models\Team;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\Workforce\WorkforceNotification;
use App\Services\Workforce\Leaves;
use App\Services\Workforce\Planner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    // Monday 2 November 2026, 06:00 in Bamako (UTC+0).
    Carbon::setTestNow('2026-11-02 06:00:00');
    config(['workforce.timezone' => 'Africa/Bamako']);

    $this->team = Team::factory()->create();
    $this->supervisor = User::factory()->create(['name' => 'Awa Diarra']);
    $this->alice = User::factory()->create(['name' => 'Aminata Coulibaly']);
    $this->bob = User::factory()->create(['name' => 'Moussa Keita']);

    foreach ([[$this->supervisor, TeamRole::Supervisor], [$this->alice, TeamRole::Member], [$this->bob, TeamRole::Member]] as [$user, $role]) {
        $this->team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($this->team);
    }

    $this->planner = app(Planner::class);
    $this->planner->ensureSetup($this->team);
    $this->a = $this->team->shiftTemplates()->where('code', 'A')->sole();
    $this->b = $this->team->shiftTemplates()->where('code', 'B')->sole();
});

function wf(string $name, array $parameters = []): string
{
    return route("workforce.{$name}", ['current_team' => test()->team->slug, ...$parameters]);
}

function shift(User $user, ShiftTemplate $template, string $date): ShiftAssignment
{
    return test()->planner->assign(test()->team, $user, $template, $date, test()->supervisor, notify: false);
}

it('creates the default A and B shifts and leave types', function () {
    expect($this->a->periodOn('2026-11-03'))->sequence(
        fn ($start) => $start->format('Y-m-d H:i')->toBe('2026-11-03 08:00'),
        fn ($end) => $end->format('Y-m-d H:i')->toBe('2026-11-03 16:00'),
    );

    // B crosses midnight.
    [, $end] = $this->b->periodOn('2026-11-03');
    expect($end->format('Y-m-d H:i'))->toBe('2026-11-04 00:00')
        ->and($this->team->leaveTypes()->pluck('code')->all())->toEqualCanonicalizing(['pto', 'sick', 'unpaid', 'emergency']);
});

it('lets a supervisor chain A and B for 16 h but blocks a second 16 h day after too little rest', function () {
    $this->actingAs($this->supervisor)
        ->post(wf('assignments.store'), ['user_id' => $this->alice->id, 'shift_template_id' => $this->a->id, 'dates' => ['2026-11-03']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->supervisor)
        ->post(wf('assignments.store'), ['user_id' => $this->alice->id, 'shift_template_id' => $this->b->id, 'dates' => ['2026-11-03']])
        ->assertSessionHasNoErrors();

    // 00:00 → 08:00 is only 8 h of rest after a 16 h block (12 h required).
    $this->actingAs($this->supervisor)
        ->post(wf('assignments.store'), ['user_id' => $this->alice->id, 'shift_template_id' => $this->a->id, 'dates' => ['2026-11-04']])
        ->assertSessionHasErrors('shift');

    expect(ShiftAssignment::query()->where('user_id', $this->alice->id)->count())->toBe(2);
    Notification::assertSentTo($this->alice, WorkforceNotification::class);
});

it('refuses overlapping shifts even when rules only warn', function () {
    $this->team->workforceSetting->update(['enforcement' => 'warn']);
    shift($this->alice, $this->a, '2026-11-03');

    $this->actingAs($this->supervisor)
        ->post(wf('assignments.store'), ['user_id' => $this->alice->id, 'shift_template_id' => $this->a->id, 'dates' => ['2026-11-03']])
        ->assertSessionHasErrors('shift');
});

it('stores a warning instead of blocking in warn mode', function () {
    $this->team->workforceSetting->update(['enforcement' => 'warn']);
    shift($this->alice, $this->a, '2026-11-03');
    shift($this->alice, $this->b, '2026-11-03');

    $next = shift($this->alice, $this->a, '2026-11-04');

    expect($next->warnings)->not->toBeEmpty();
});

it('keeps the roster and requests inbox for supervisors', function () {
    $this->actingAs($this->alice)->get(wf('roster'))->assertForbidden();
    $this->actingAs($this->alice)->get(wf('requests'))->assertForbidden();
    $this->actingAs($this->alice)->get(wf('settings'))->assertForbidden();
    $this->actingAs($this->alice)
        ->post(wf('assignments.store'), ['user_id' => $this->alice->id, 'shift_template_id' => $this->a->id, 'dates' => ['2026-11-03']])
        ->assertForbidden();

    $this->actingAs($this->alice)->get(wf('home'))->assertRedirect(wf('me'));
    $this->actingAs($this->alice)->get(wf('me'))
        ->assertInertia(fn (Assert $page) => $page->component('workforce/MySchedule')->where('workforce.isSupervisor', false));

    $this->actingAs($this->supervisor)->get(wf('home'))->assertRedirect(wf('roster'));
    $this->actingAs($this->supervisor)->get(wf('roster'))
        ->assertInertia(fn (Assert $page) => $page->component('workforce/Roster')->has('agents', 2));

    // Labour rules are for administrators.
    $this->actingAs($this->supervisor)->put(wf('settings.rules'), [
        'max_consecutive_hours' => 12, 'min_rest_hours' => 8, 'min_rest_after_long_hours' => 12,
        'max_weekly_hours' => 48, 'enforcement' => 'block', 'late_tolerance_minutes' => 5,
    ])->assertForbidden();
});

it('runs a swap through colleague acceptance and supervisor approval', function () {
    $give = shift($this->alice, $this->a, '2026-11-04');
    $take = shift($this->bob, $this->a, '2026-11-05');

    $this->actingAs($this->alice)->post(wf('swaps.store'), [
        'requester_assignment_id' => $give->id,
        'counterparty_id' => $this->bob->id,
        'counterparty_assignment_id' => $take->id,
        'message' => 'Rendez-vous médical mercredi',
    ])->assertSessionHasNoErrors();

    $swap = ShiftSwap::query()->sole();
    expect($swap->status)->toBe(ShiftSwap::PENDING_COUNTERPARTY);
    Notification::assertSentTo($this->bob, WorkforceNotification::class);

    // Only the colleague answers.
    $this->actingAs($this->alice)->post(wf('swaps.respond', ['swap' => $swap->id]), ['accept' => true])->assertForbidden();
    $this->actingAs($this->bob)->post(wf('swaps.respond', ['swap' => $swap->id]), ['accept' => true])->assertSessionHasNoErrors();
    expect($swap->fresh()->status)->toBe(ShiftSwap::PENDING_SUPERVISOR);

    // A refusal needs a reason.
    $this->actingAs($this->supervisor)->post(wf('swaps.review', ['swap' => $swap->id]), ['approve' => false])
        ->assertSessionHasErrors('review_note');

    $this->actingAs($this->supervisor)->post(wf('swaps.review', ['swap' => $swap->id]), ['approve' => true])
        ->assertSessionHasNoErrors();

    expect($swap->fresh()->status)->toBe(ShiftSwap::APPROVED)
        ->and($give->fresh()->user_id)->toBe($this->bob->id)
        ->and($take->fresh()->user_id)->toBe($this->alice->id);
});

it('approves a swap automatically when the team allows it and no rule is bent', function () {
    $this->team->workforceSetting->update(['auto_approve_swaps' => true]);
    $give = shift($this->alice, $this->b, '2026-11-06');

    $this->actingAs($this->alice)->post(wf('swaps.store'), [
        'requester_assignment_id' => $give->id,
        'counterparty_id' => $this->bob->id,
    ])->assertSessionHasNoErrors();

    $swap = ShiftSwap::query()->sole();
    $this->actingAs($this->bob)->post(wf('swaps.respond', ['swap' => $swap->id]), ['accept' => true]);

    expect($swap->fresh())->status->toBe(ShiftSwap::APPROVED)->auto_approved->toBeTrue()
        ->and($give->fresh()->user_id)->toBe($this->bob->id);
});

it('refuses a swap that would break the rest rule of the colleague', function () {
    // Bob works 16 h on Tuesday: Alice's Wednesday morning would leave him 8 h of rest.
    shift($this->bob, $this->a, '2026-11-03');
    shift($this->bob, $this->b, '2026-11-03');
    $give = shift($this->alice, $this->a, '2026-11-04');

    $this->actingAs($this->alice)->post(wf('swaps.store'), [
        'requester_assignment_id' => $give->id,
        'counterparty_id' => $this->bob->id,
    ])->assertSessionHasErrors();

    expect(ShiftSwap::query()->count())->toBe(0);
});

it('counts leave against the balance and frees the roster once approved', function () {
    $wednesday = shift($this->alice, $this->a, '2026-11-04');
    $thursday = shift($this->alice, $this->a, '2026-11-05');
    $pto = $this->team->leaveTypes()->where('code', 'pto')->sole();

    $this->actingAs($this->alice)->post(wf('leave.store'), [
        'leave_type_id' => $pto->id,
        'start_date' => '2026-11-04',
        'end_date' => '2026-11-05',
        'reason' => 'Mariage',
    ])->assertSessionHasNoErrors();

    $leave = LeaveRequest::query()->sole();
    $balance = collect(app(Leaves::class)->balances($this->team, $this->alice, 2026))->firstWhere('type.id', $pto->id);
    expect((float) $leave->days)->toBe(2.0)
        ->and($balance['pending'])->toEqual(2)
        ->and($balance['remaining'])->toEqual(28);

    $this->actingAs($this->supervisor)->post(wf('leave.review', ['leave' => $leave->id]), ['approve' => true])
        ->assertSessionHasNoErrors();

    expect($leave->fresh()->status)->toBe(LeaveRequest::APPROVED)
        ->and(ShiftAssignment::query()->whereKey([$wednesday->id, $thursday->id])->count())->toBe(0);

    // Nobody can be put back on a day of approved leave.
    $this->actingAs($this->supervisor)
        ->post(wf('assignments.store'), ['user_id' => $this->alice->id, 'shift_template_id' => $this->b->id, 'dates' => ['2026-11-04']])
        ->assertSessionHasErrors('shift');
});

it('refuses leave beyond the remaining balance', function () {
    $emergency = $this->team->leaveTypes()->where('code', 'emergency')->sole();

    $this->actingAs($this->alice)->post(wf('leave.store'), [
        'leave_type_id' => $emergency->id,
        'start_date' => '2026-11-09',
        'end_date' => '2026-11-14', // 6 working days, 5 allowed
    ])->assertSessionHasErrors();

    expect(LeaveRequest::query()->count())->toBe(0);
});

it('applies an approved timesheet correction and keeps the previous values', function () {
    $entry = TimeEntry::query()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->alice->id,
        'date' => '2026-10-30',
        'clock_in_at' => '2026-10-30 08:20:00',
        'clock_out_at' => '2026-10-30 12:00:00',
        'source' => 'clock',
    ]);

    $this->actingAs($this->alice)->post(wf('timesheets.adjust'), [
        'time_entry_id' => $entry->id,
        'date' => '2026-10-30',
        'clock_in' => '08:00',
        'clock_out' => '16:00',
        'break_minutes' => 60,
        'reason' => 'Oubli de pointage au départ',
    ])->assertSessionHasNoErrors();

    $adjustment = TimeAdjustment::query()->sole();

    $this->actingAs($this->supervisor)->post(wf('adjustments.review', ['adjustment' => $adjustment->id]), ['approve' => true])
        ->assertSessionHasNoErrors();

    $entry->refresh();
    expect($adjustment->fresh()->status)->toBe(TimeAdjustment::APPROVED)
        ->and($entry->clock_out_at->format('H:i'))->toBe('16:00')
        ->and($entry->breakMinutes())->toBe(60)
        ->and($adjustment->fresh()->previous)->not->toBeNull();
});

it('exports the week as csv, xlsx and a print page', function () {
    shift($this->alice, $this->a, '2026-11-03');

    $csv = $this->actingAs($this->supervisor)->get(wf('timesheets.export', ['week' => '2026-11-02', 'format' => 'csv']));
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Aminata Coulibaly');

    $xlsx = $this->actingAs($this->supervisor)->get(wf('timesheets.export', ['week' => '2026-11-02', 'format' => 'xlsx']));
    $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(substr($xlsx->getContent(), 0, 2))->toBe('PK');

    $this->actingAs($this->supervisor)->get(wf('timesheets.export', ['week' => '2026-11-02', 'format' => 'print']))
        ->assertOk()->assertSee('Aminata Coulibaly');

    // An agent only exports their own sheet.
    $own = $this->actingAs($this->bob)->get(wf('timesheets.export', ['week' => '2026-11-02', 'format' => 'csv']));
    expect($own->streamedContent())->not->toContain('Aminata Coulibaly');
});

it('lets an administrator put an agent under a supervisor', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->patch(route('teams.members.supervisor', ['team' => $this->team->slug, 'user' => $this->alice->id]), ['supervisor_id' => $this->supervisor->id])
        ->assertSessionHasNoErrors();

    expect($this->team->memberships()->where('user_id', $this->alice->id)->value('supervisor_id'))->toBe($this->supervisor->id);

    // Only supervisors and above can be in charge.
    $this->actingAs($admin)
        ->patch(route('teams.members.supervisor', ['team' => $this->team->slug, 'user' => $this->alice->id]), ['supervisor_id' => $this->bob->id])
        ->assertSessionHasErrors('supervisor_id');

    // Agents cannot assign.
    $this->actingAs($this->bob)
        ->patch(route('teams.members.supervisor', ['team' => $this->team->slug, 'user' => $this->bob->id]), ['supervisor_id' => $this->supervisor->id])
        ->assertForbidden();

    // Demoting the supervisor leaves the agent unassigned.
    $this->actingAs($admin)
        ->patch(route('teams.members.update', ['team' => $this->team->slug, 'user' => $this->supervisor->id]), ['role' => TeamRole::Member->value]);

    expect($this->team->memberships()->where('user_id', $this->alice->id)->value('supervisor_id'))->toBeNull();
});

it('routes an agent’s requests to their own supervisor only', function () {
    $other = User::factory()->create(['name' => 'Oumar Traoré']);
    $this->team->members()->attach($other, ['role' => TeamRole::Supervisor->value]);
    $this->team->memberships()->where('user_id', $this->alice->id)->update(['supervisor_id' => $this->supervisor->id]);

    $pto = $this->team->leaveTypes()->where('code', 'pto')->sole();
    $this->actingAs($this->alice)->post(wf('leave.store'), [
        'leave_type_id' => $pto->id,
        'start_date' => '2026-11-09',
        'end_date' => '2026-11-09',
    ])->assertSessionHasNoErrors();

    $leave = LeaveRequest::query()->sole();

    Notification::assertSentTo($this->supervisor, WorkforceNotification::class);
    Notification::assertNotSentTo($other, WorkforceNotification::class);

    // The other supervisor neither sees nor decides it.
    $this->actingAs($other)->get(wf('requests'))
        ->assertInertia(fn (Assert $page) => $page->has('leave', 0)->where('workforce.pending.leave', 0));
    $this->actingAs($other)->post(wf('leave.review', ['leave' => $leave->id]), ['approve' => true])->assertForbidden();
    $this->actingAs($other)->get(wf('timesheets', ['user' => $this->alice->id]))->assertForbidden();

    $this->actingAs($this->supervisor)->get(wf('requests'))
        ->assertInertia(fn (Assert $page) => $page->has('leave', 1));
    $this->actingAs($this->supervisor)->post(wf('leave.review', ['leave' => $leave->id]), ['approve' => true])
        ->assertSessionHasNoErrors();

    // Unassigned agents stay reviewable by every supervisor.
    $this->actingAs($other)->get(wf('timesheets', ['user' => $this->bob->id]))->assertOk();
});

it('does not let a supervisor approve their own requests', function () {
    $pto = $this->team->leaveTypes()->where('code', 'pto')->sole();
    $this->actingAs($this->supervisor)->post(wf('leave.store'), [
        'leave_type_id' => $pto->id,
        'start_date' => '2026-11-09',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->supervisor)
        ->post(wf('leave.review', ['leave' => LeaveRequest::query()->sole()->id]), ['approve' => true])
        ->assertForbidden();
});

it('gives planners the whole workforce module and nothing else', function () {
    $planner = User::factory()->create(['name' => 'Fatoumata Sangaré']);
    $this->team->members()->attach($planner, ['role' => TeamRole::Planner->value]);
    $planner->switchTeam($this->team);

    // Even an agent assigned to a supervisor.
    $this->team->memberships()->where('user_id', $this->alice->id)->update(['supervisor_id' => $this->supervisor->id]);

    $this->actingAs($planner)->get(wf('home'))->assertRedirect(wf('roster'));
    $this->actingAs($planner)->get(wf('roster'))->assertOk();
    $this->actingAs($planner)->get(wf('timesheets', ['user' => $this->alice->id]))->assertOk();
    $this->actingAs($planner)->get(wf('settings'))
        ->assertInertia(fn (Assert $page) => $page->where('workforce.isAdmin', true));

    $this->actingAs($planner)->put(wf('settings.rules'), [
        'max_consecutive_hours' => 12, 'min_rest_hours' => 10, 'min_rest_after_long_hours' => 12,
        'max_weekly_hours' => 44, 'enforcement' => 'warn', 'late_tolerance_minutes' => 10,
    ])->assertSessionHasNoErrors();
    expect($this->team->workforceSetting->fresh()->max_weekly_hours)->toBe(44);

    $pto = $this->team->leaveTypes()->where('code', 'pto')->sole();
    $this->actingAs($this->alice)->post(wf('leave.store'), ['leave_type_id' => $pto->id, 'start_date' => '2026-11-09']);
    $this->actingAs($planner)->post(wf('leave.review', ['leave' => LeaveRequest::query()->sole()->id]), ['approve' => true])
        ->assertSessionHasNoErrors();

    // Not a call-center supervisor.
    expect(TeamRole::Planner->isAtLeast(TeamRole::Supervisor))->toBeFalse()
        ->and(TeamRole::Planner->hasPermission(\App\Enums\TeamPermission::MonitorAgents))->toBeFalse();
});
