<?php

use App\Http\Controllers\Admin\AsteriskTablesController;
use App\Http\Controllers\Admin\PlatformController;
use App\Http\Controllers\AgentLogsController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\CallOutcomesController;
use App\Http\Controllers\CallRecordingsController;
use App\Http\Controllers\CallsController;
use App\Http\Controllers\CallTransferController;
use App\Http\Controllers\Campaigns\CampaignContactsController;
use App\Http\Controllers\Campaigns\CampaignsController;
use App\Http\Controllers\Campaigns\CampaignSurveyController;
use App\Http\Controllers\Campaigns\CampaignWorkspaceController;
use App\Http\Controllers\Campaigns\DncController;
use App\Http\Controllers\CurrentTeamDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\Marketing\ContactController;
use App\Http\Controllers\Marketing\LegalController;
use App\Http\Controllers\MeetingsController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\Onboarding\OnboardingController;
use App\Http\Controllers\Onboarding\TourController;
use App\Http\Controllers\PauseController;
use App\Http\Controllers\SupervisorMonitoringController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Workforce\LeaveController as WorkforceLeaveController;
use App\Http\Controllers\Workforce\RequestsController as WorkforceRequestsController;
use App\Http\Controllers\Workforce\RosterController;
use App\Http\Controllers\Workforce\ScheduleController;
use App\Http\Controllers\Workforce\SettingsController as WorkforceSettingsController;
use App\Http\Controllers\Workforce\TimesheetsController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureCallCenterMode;
use App\Http\Middleware\EnsurePlanAllows;
use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\EnsureTeamOnboarded;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('marketing/Landing'))->name('home');
Route::inertia('features', 'marketing/Features')->name('features');
Route::get('pricing', [BillingController::class, 'pricing'])->name('pricing');
Route::inertia('contact', 'marketing/Contact')->name('contact');
Route::inertia('about', 'marketing/About')->name('about');
Route::inertia('solutions', 'marketing/Solutions')->name('solutions');
Route::get('solutions/{industry}', fn (string $industry) => Inertia::render('marketing/Solution', ['industry' => $industry]))
    ->whereIn('industry', config('marketing.solutions'))
    ->name('solutions.show');
// Legal pages (texts in resources/js/pages/marketing/legal, identity in config/legal.php).
Route::get('confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('mentions-legales', [LegalController::class, 'notice'])->name('legal.notice');
Route::get('confiance', [LegalController::class, 'trust'])->name('legal.trust');
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

// The payment provider sends the customer back here, sometimes with a cross-site POST that
// carries no session: the payment is identified by its transaction id and
// re-checked server side, then the browser lands on the billing page.
Route::match(['get', 'post'], 'billing/return/{transaction}', [BillingController::class, 'return'])
    ->middleware('throttle:20,1')
    ->where('transaction', '[A-Z0-9]{10,40}')
    ->name('billing.return');

Route::get('dashboard', CurrentTeamDashboardController::class)->middleware(['auth', 'verified'])->name('dashboard.current');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, EnsureSubscriptionActive::class, EnsureTeamOnboarded::class])
    ->group(function () {
        // Welcome wizard of a new team (owner and admins).
        Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
        Route::put('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('calls', [CallsController::class, 'index'])->name('calls');
        Route::get('calls/recordings/{recording}', [CallRecordingsController::class, 'show'])->whereNumber('recording')->name('calls.recordings.show');
        Route::post('calls/transfer', [CallTransferController::class, 'store'])->name('calls.transfer');
        Route::get('queues', [CallTransferController::class, 'index'])->name('queues.options');
        Route::get('directory', [DirectoryController::class, 'index'])->name('directory');

        Route::post('call-outcomes', [CallOutcomesController::class, 'store'])->name('call-outcomes.store');
        Route::put('call-outcomes/{callOutcome}', [CallOutcomesController::class, 'update'])->name('call-outcomes.update');
        Route::delete('call-outcomes/{callOutcome}', [CallOutcomesController::class, 'destroy'])->name('call-outcomes.destroy');

        // Call-center screens: closed to organizations in internal-only mode.
        Route::middleware([EnsureCallCenterMode::class, EnsurePlanAllows::class.':monitoring'])->group(function () {
            Route::get('monitoring', [SupervisorMonitoringController::class, 'index'])->name('monitoring');
            Route::get('monitoring/status', [SupervisorMonitoringController::class, 'status'])->name('monitoring.status');
            Route::post('monitoring/listen', [SupervisorMonitoringController::class, 'listen'])->name('monitoring.listen');
            Route::post('monitoring/whisper', [SupervisorMonitoringController::class, 'whisper'])->name('monitoring.whisper');

            // Agent status log (pauses with reasons, post-appel, ne pas déranger…), supervisor and up.
            Route::get('agent-logs', [AgentLogsController::class, 'index'])->name('agent-logs.index');
        });

        Route::get('pauses/status', [PauseController::class, 'status'])->name('pauses.status');
        Route::post('pauses', [PauseController::class, 'store'])->name('pauses.store');
        Route::delete('pauses', [PauseController::class, 'destroy'])->name('pauses.destroy');
        Route::put('agent-state', [PauseController::class, 'update'])->middleware('throttle:60,1')->name('agent-state.update');

        Route::post('presence', [PauseController::class, 'join'])->middleware('throttle:30,1')->name('presence.join');
        Route::post('presence/leave', [PauseController::class, 'leave'])->name('presence.leave');

        Route::middleware(EnsureCallCenterMode::class)->group(function () {
            // Outbound campaigns
            Route::get('campaigns', [CampaignsController::class, 'index'])->name('campaigns.index');
            Route::get('campaigns/create', [CampaignsController::class, 'create'])->name('campaigns.create');
            Route::post('campaigns', [CampaignsController::class, 'store'])->name('campaigns.store');
            Route::get('campaigns/workspace', [CampaignWorkspaceController::class, 'show'])->name('campaigns.workspace');
            Route::get('campaigns/attempts/active', [CampaignWorkspaceController::class, 'active'])->name('campaigns.attempts.active');
            Route::post('campaigns/attempts/{attempt}/disposition', [CampaignWorkspaceController::class, 'dispose'])->whereNumber('attempt')->name('campaigns.attempts.dispose');
            Route::get('campaigns/dnc', [DncController::class, 'index'])->name('campaigns.dnc.index');
            Route::post('campaigns/dnc', [DncController::class, 'store'])->name('campaigns.dnc.store');
            Route::delete('campaigns/dnc/{number}', [DncController::class, 'destroy'])->whereNumber('number')->name('campaigns.dnc.destroy');

            Route::prefix('campaigns/{campaign}')->whereNumber('campaign')->group(function () {
                Route::get('/', [CampaignsController::class, 'show'])->name('campaigns.show');
                Route::get('edit', [CampaignsController::class, 'edit'])->name('campaigns.edit');
                Route::put('/', [CampaignsController::class, 'update'])->name('campaigns.update');
                Route::delete('/', [CampaignsController::class, 'destroy'])->name('campaigns.destroy');
                Route::post('status/{action}', [CampaignsController::class, 'transition'])->whereIn('action', ['start', 'pause', 'complete'])->name('campaigns.transition');
                Route::post('recycle', [CampaignsController::class, 'recycle'])->name('campaigns.recycle');
                Route::get('export', [CampaignsController::class, 'export'])->name('campaigns.export');
                Route::post('contacts/import', [CampaignContactsController::class, 'import'])->middleware('throttle:10,1')->name('campaigns.contacts.import');
                Route::post('contacts', [CampaignContactsController::class, 'store'])->name('campaigns.contacts.store');
                Route::delete('contacts/{contact}', [CampaignContactsController::class, 'destroy'])->whereNumber('contact')->name('campaigns.contacts.destroy');
                Route::post('next', [CampaignWorkspaceController::class, 'next'])->name('campaigns.next');
                Route::post('contacts/{contact}/call', [CampaignWorkspaceController::class, 'call'])->whereNumber('contact')->middleware('throttle:30,1')->name('campaigns.contacts.call');
                Route::post('contacts/{contact}/skip', [CampaignWorkspaceController::class, 'skip'])->whereNumber('contact')->name('campaigns.contacts.skip');

                // Questionnaire: builder and results (supervisors), answers (agents).
                Route::get('survey', [CampaignSurveyController::class, 'edit'])->name('campaigns.survey.edit');
                Route::put('survey', [CampaignSurveyController::class, 'update'])->name('campaigns.survey.update');
                Route::get('survey/results', [CampaignSurveyController::class, 'results'])->name('campaigns.survey.results');
                Route::get('survey/export', [CampaignSurveyController::class, 'export'])->name('campaigns.survey.export');
                Route::post('contacts/{contact}/survey', [CampaignSurveyController::class, 'answer'])->whereNumber('contact')->middleware('throttle:120,1')->name('campaigns.contacts.survey');
            });
        });

        // Workforce planning: roster, swaps, timesheets, leave.
        Route::prefix('planning')->name('workforce.')->group(function () {
            Route::get('/', [RosterController::class, 'home'])->name('home');
            Route::get('roster', [RosterController::class, 'index'])->name('roster');
            Route::post('assignments', [RosterController::class, 'store'])->name('assignments.store');
            Route::delete('assignments/{assignment}', [RosterController::class, 'destroy'])->whereNumber('assignment')->name('assignments.destroy');
            Route::post('roster/copy-week', [RosterController::class, 'copyWeek'])->name('roster.copy');
            Route::post('roster/notify-week', [RosterController::class, 'notifyWeek'])->middleware('throttle:6,1')->name('roster.notify');

            Route::get('me', [ScheduleController::class, 'show'])->name('me');
            Route::post('swaps', [ScheduleController::class, 'requestSwap'])->middleware('throttle:30,1')->name('swaps.store');
            Route::post('swaps/{swap}/respond', [ScheduleController::class, 'respond'])->whereNumber('swap')->name('swaps.respond');
            Route::post('swaps/{swap}/cancel', [ScheduleController::class, 'cancel'])->whereNumber('swap')->name('swaps.cancel');
            Route::post('clock/{action}', [ScheduleController::class, 'clock'])->whereIn('action', ['in', 'out', 'break-start', 'break-end'])->middleware('throttle:30,1')->name('clock');

            Route::get('requests', [WorkforceRequestsController::class, 'index'])->name('requests');
            Route::post('swaps/{swap}/review', [WorkforceRequestsController::class, 'reviewSwap'])->whereNumber('swap')->name('swaps.review');
            Route::post('leave/{leave}/review', [WorkforceRequestsController::class, 'reviewLeave'])->whereNumber('leave')->name('leave.review');
            Route::post('adjustments/{adjustment}/review', [WorkforceRequestsController::class, 'reviewAdjustment'])->whereNumber('adjustment')->name('adjustments.review');

            Route::get('timesheets', [TimesheetsController::class, 'index'])->name('timesheets');
            Route::post('timesheets/adjustments', [TimesheetsController::class, 'adjust'])->middleware('throttle:20,1')->name('timesheets.adjust');
            Route::post('timesheets/approve', [TimesheetsController::class, 'approve'])->name('timesheets.approve');
            Route::post('timesheets/reopen', [TimesheetsController::class, 'reopen'])->name('timesheets.reopen');
            Route::get('timesheets/export', [TimesheetsController::class, 'export'])->name('timesheets.export');

            Route::get('leave', [WorkforceLeaveController::class, 'index'])->name('leave');
            Route::post('leave', [WorkforceLeaveController::class, 'store'])->middleware('throttle:20,1')->name('leave.store');
            Route::post('leave/{leave}/cancel', [WorkforceLeaveController::class, 'cancel'])->whereNumber('leave')->name('leave.cancel');

            Route::get('settings', [WorkforceSettingsController::class, 'edit'])->name('settings');
            Route::put('settings/shifts', [WorkforceSettingsController::class, 'updateShifts'])->name('settings.shifts');
            Route::put('settings/rules', [WorkforceSettingsController::class, 'updateRules'])->name('settings.rules');
            Route::put('settings/leave-types', [WorkforceSettingsController::class, 'updateLeaveTypes'])->name('settings.leave-types');
        });

        Route::get('meetings', [MeetingsController::class, 'index'])->name('meetings.index');
        Route::post('meetings', [MeetingsController::class, 'store'])->name('meetings.store');
        Route::get('meetings/{meeting}', [MeetingsController::class, 'show'])->name('meetings.show');
        Route::post('meetings/{meeting}/token', [MeetingsController::class, 'token'])->middleware('throttle:30,1')->name('meetings.token');
        Route::post('meetings/{meeting}/joined', [MeetingsController::class, 'joined'])->middleware('throttle:30,1')->name('meetings.joined');
        Route::post('meetings/{meeting}/invite', [MeetingsController::class, 'invite'])->name('meetings.invite');
        Route::put('meetings/{meeting}', [MeetingsController::class, 'update'])->name('meetings.update');
        Route::post('meetings/{meeting}/cancel', [MeetingsController::class, 'cancel'])->name('meetings.cancel');
        Route::get('meetings/{meeting}/calendar.ics', [MeetingsController::class, 'calendar'])->name('meetings.calendar');
        Route::put('meetings/{meeting}/notes', [MeetingsController::class, 'notes'])->middleware('throttle:120,1')->name('meetings.notes');
        Route::post('meetings/{meeting}/end', [MeetingsController::class, 'end'])->name('meetings.end');
        Route::post('meetings/{meeting}/recording', [MeetingsController::class, 'startRecording'])->name('meetings.recording.start');
        Route::delete('meetings/{meeting}/recording', [MeetingsController::class, 'stopRecording'])->name('meetings.recording.stop');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    // Help center: how to use the app, by role.
    Route::get('help', [HelpController::class, 'index'])->name('help.index');
    Route::get('help/{article}', [HelpController::class, 'show'])->where('article', '[a-z0-9-]+')->name('help.show');

    // Bell in the header.
    Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationsController::class, 'read'])->whereUuid('notification')->name('notifications.read');

    // Guided tour of the app: shown once, can be replayed from the help center.
    Route::post('tour/complete', [TourController::class, 'complete'])->name('tour.complete');
});

// Platform administration (MbayeLab): settings shared by every team.
Route::middleware(['auth', 'verified', EnsurePlatformAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('platform', [PlatformController::class, 'show'])->name('platform');
    Route::put('platform/trunk', [PlatformController::class, 'update'])->name('platform.trunk');
    Route::put('platform/rate', [PlatformController::class, 'updateRate'])->name('platform.rate');

    // Generic editor over the Asterisk realtime tables and call logs.
    Route::get('asterisk-tables', [AsteriskTablesController::class, 'index'])->name('asterisk-tables.index');
    Route::prefix('asterisk-tables/{table}')->where(['table' => 'ps_endpoints|ps_aors|ps_auths|ps_endpoint_id_ips|ps_registrations|ps_transports|queue_members|queue_rules|musiconhold|musiconhold_entry|voicemail|voicemail_messages|cdr|cel|queue_log'])->name('asterisk-tables.')->group(function () {
        Route::post('/', [AsteriskTablesController::class, 'store'])->name('store');
        Route::put('/', [AsteriskTablesController::class, 'update'])->name('update');
        Route::delete('/', [AsteriskTablesController::class, 'destroy'])->name('destroy');
        Route::get('binary', [AsteriskTablesController::class, 'binary'])->name('binary');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
