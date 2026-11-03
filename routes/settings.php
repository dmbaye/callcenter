<?php

use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Settings\Asterisk\DialplanController;
use App\Http\Controllers\Settings\Asterisk\HoldMusicController;
use App\Http\Controllers\Settings\Asterisk\IvrController;
use App\Http\Controllers\Settings\Asterisk\PjsipController;
use App\Http\Controllers\Settings\Asterisk\QueueScheduleController;
use App\Http\Controllers\Settings\Asterisk\QueuesController;
use App\Http\Controllers\Settings\Asterisk\VoicemailController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\PauseReasonsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureCallCenterMode;
use App\Http\Middleware\EnsureCurrentTeamMatches;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/avatar', [AvatarController::class, 'store'])->middleware('throttle:10,1')->name('profile.avatar.store');
    Route::delete('settings/profile/avatar', [AvatarController::class, 'destroy'])->name('profile.avatar.destroy');
    Route::get('avatars/{user}', [AvatarController::class, 'show'])->whereNumber('user')->name('avatars.show');

    // Interface language, also from the user menu.
    Route::put('settings/locale', [ProfileController::class, 'locale'])->name('profile.locale');

    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])->name('notification-preferences.edit');
    Route::put('settings/notifications', [NotificationPreferencesController::class, 'update'])->name('notification-preferences.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // The theme switch moved to the top bar; old links land on the profile.
    Route::redirect('settings/appearance', '/settings/profile')->name('appearance.edit');

    // The organization is the team: this opens the current team's page.
    Route::get('settings/organization', [TeamController::class, 'current'])->name('organization.edit');

    Route::prefix('settings/asterisk')->name('asterisk.')->middleware(EnsureCurrentTeamMatches::class)->group(function () {
        Route::get('dialplan', [DialplanController::class, 'index'])->name('dialplan.index');
        Route::post('dialplan', [DialplanController::class, 'store'])->name('dialplan.store');
        Route::put('dialplan', [DialplanController::class, 'update'])->name('dialplan.update');
        Route::delete('dialplan', [DialplanController::class, 'destroy'])->name('dialplan.destroy');

        Route::middleware(EnsureCallCenterMode::class)->group(function () {
            // Queues: settings, agents, routing, hours and closures on one page.
            Route::get('queues', [QueuesController::class, 'index'])->name('queues.index');
            Route::post('queues', [QueuesController::class, 'store'])->name('queues.store');
            Route::put('queues', [QueuesController::class, 'update'])->name('queues.update');
            Route::put('queues/service-level', [QueuesController::class, 'serviceLevel'])->name('queues.service-level');
            Route::delete('queues', [QueuesController::class, 'destroy'])->name('queues.destroy');
            Route::put('queues/{queue}/agents', [QueuesController::class, 'agents'])->whereNumber('queue')->name('queues.agents');
            Route::put('queues/{queue}/hours', [QueueScheduleController::class, 'hours'])->whereNumber('queue')->name('queues.hours');
            Route::post('queues/{queue}/closures', [QueueScheduleController::class, 'storeClosure'])->whereNumber('queue')->name('queues.closures.store');
            Route::delete('queues/closures/{closure}', [QueueScheduleController::class, 'destroyClosure'])->whereNumber('closure')->name('queues.closures.destroy');
        });

        // The team's own hold music (queues and holds).
        Route::get('hold-music', [HoldMusicController::class, 'index'])->name('hold-music.index');
        Route::post('hold-music', [HoldMusicController::class, 'store'])->middleware('throttle:20,1')->name('hold-music.store');
        Route::delete('hold-music/{track}', [HoldMusicController::class, 'destroy'])->whereNumber('track')->name('hold-music.destroy');
        Route::get('hold-music/{track}/play', [HoldMusicController::class, 'play'])->whereNumber('track')->name('hold-music.play');

        // Voicemail boxes of the extensions and their messages.
        Route::get('voicemail', [VoicemailController::class, 'index'])->name('voicemail.index');
        Route::put('voicemail/boxes/{extension}', [VoicemailController::class, 'saveBox'])->where('extension', '\d{2,20}')->name('voicemail.boxes.save');
        Route::delete('voicemail/boxes/{extension}', [VoicemailController::class, 'destroyBox'])->where('extension', '\d{2,20}')->name('voicemail.boxes.destroy');
        Route::get('voicemail/messages/play', [VoicemailController::class, 'play'])->name('voicemail.messages.play');
        Route::delete('voicemail/messages', [VoicemailController::class, 'destroyMessage'])->name('voicemail.messages.destroy');

        Route::get('pjsip', [PjsipController::class, 'index'])->name('pjsip.index');
        Route::post('pjsip', [PjsipController::class, 'store'])->name('pjsip.store');
        Route::put('pjsip', [PjsipController::class, 'update'])->name('pjsip.update');
        Route::delete('pjsip', [PjsipController::class, 'destroy'])->name('pjsip.destroy');

        Route::middleware(EnsureCallCenterMode::class)->group(function () {
            // Former pages, now tabs of the queues page.
            Route::redirect('hours', '/settings/asterisk/queues')->name('hours.index');
            Route::redirect('routing', '/settings/asterisk/queues')->name('routing.index');

            Route::get('ivr', [IvrController::class, 'index'])->name('ivr.index');
            Route::post('ivr/menus', [IvrController::class, 'storeMenu'])->name('ivr.menus.store');
            Route::put('ivr/menus/{menu}', [IvrController::class, 'updateMenu'])->whereNumber('menu')->name('ivr.menus.update');
            Route::delete('ivr/menus/{menu}', [IvrController::class, 'destroyMenu'])->whereNumber('menu')->name('ivr.menus.destroy');
            Route::post('ivr/numbers', [IvrController::class, 'storeNumber'])->name('ivr.numbers.store');
            Route::put('ivr/numbers/{number}', [IvrController::class, 'updateNumber'])->whereNumber('number')->name('ivr.numbers.update');
            Route::delete('ivr/numbers/{number}', [IvrController::class, 'destroyNumber'])->whereNumber('number')->name('ivr.numbers.destroy');
            Route::post('ivr/numbers/claim', [IvrController::class, 'claimNumber'])->name('ivr.numbers.claim');
            Route::post('ivr/numbers/{number}/replace', [IvrController::class, 'replaceNumber'])->whereNumber('number')->name('ivr.numbers.replace');
        });
    });

    Route::prefix('settings/pause-reasons')->name('pause-reasons.')->middleware([EnsureCurrentTeamMatches::class, EnsureCallCenterMode::class])->group(function () {
        Route::get('/', [PauseReasonsController::class, 'index'])->name('index');
        Route::post('/', [PauseReasonsController::class, 'store'])->name('store');
        Route::put('order', [PauseReasonsController::class, 'reorder'])->name('reorder');
        Route::put('{reason}', [PauseReasonsController::class, 'update'])->whereNumber('reason')->name('update');
        Route::delete('{reason}', [PauseReasonsController::class, 'destroy'])->whereNumber('reason')->name('destroy');
    });

    Route::prefix('settings/billing')->name('billing.')->middleware(EnsureCurrentTeamMatches::class)->group(function () {
        Route::get('/', [BillingController::class, 'index'])->name('index');
        Route::post('checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('checkout');
    });

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::patch('settings/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.profile.update');
        Route::patch('settings/teams/{team}/usage', [TeamController::class, 'updateUsage'])->name('teams.usage.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::post('settings/teams/{team}/members', [TeamMemberController::class, 'store'])->name('teams.members.store');
        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::patch('settings/teams/{team}/members/{user}/supervisor', [TeamMemberController::class, 'assignSupervisor'])->name('teams.members.supervisor');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
