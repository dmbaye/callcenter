<?php

use App\Http\Controllers\AsteriskHoldMusicController;
use App\Http\Controllers\AsteriskRecordingWebhookController;
use App\Http\Controllers\AsteriskWebhookController;
use App\Http\Controllers\Billing\CinetPayWebhookController;
use App\Http\Controllers\Billing\PayDunyaWebhookController;
use App\Http\Controllers\LiveKitWebhookController;
use Illuminate\Support\Facades\Route;

// Mounted under /webhooks without the web middleware group (no session, no CSRF):
// requests are authenticated by their HMAC signature instead. See bootstrap/app.php.
Route::post('asterisk/monitoring', AsteriskWebhookController::class)->name('webhooks.asterisk.monitoring');

// Finished call recordings uploaded by Asterisk (bearer token, see config/asterisk.php).
Route::post('asterisk/recordings', AsteriskRecordingWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.asterisk.recordings');

// Team hold music played by Asterisk (signed URL written in musiconhold_entry).
Route::get('asterisk/moh/{track}/{signature}.wav', AsteriskHoldMusicController::class)
    ->whereNumber('track')
    ->where('signature', '[a-f0-9]{40}')
    ->middleware('throttle:600,1')
    ->name('webhooks.asterisk.moh');

// PayDunya IPN (data[hash] = SHA-512 of the master key; status always re-checked with PayDunya).
Route::post('paydunya', PayDunyaWebhookController::class)->middleware('throttle:120,1')->name('webhooks.paydunya');

// CinetPay payment notifications (x-token HMAC; status always re-checked with CinetPay).
// CinetPay also probes the URL with GET to check that it is reachable.
Route::match(['get', 'post'], 'cinetpay', CinetPayWebhookController::class)->name('webhooks.cinetpay');

// LiveKit room/participant/egress events (Authorization JWT signed with the API secret).
Route::post('livekit', LiveKitWebhookController::class)->name('webhooks.livekit');
