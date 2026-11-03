<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing;
use App\Services\CinetPay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * CinetPay payment notification.
 *
 * The x-token proves the call comes from CinetPay, but the payment state is
 * still taken from the check API, never from the posted fields. Notifications
 * repeat, so the handler is idempotent (Billing::sync()).
 */
class CinetPayWebhookController extends Controller
{
    public function __invoke(Request $request, CinetPay $cinetPay, Billing $billing): JsonResponse
    {
        // Availability probe.
        if ($request->isMethod('GET')) {
            return response()->json(['ok' => true]);
        }

        if (! $cinetPay->isConfigured()) {
            return response()->json(['message' => 'Payments are not configured.'], 503);
        }

        // The raw body, not $request->post(): the global TrimStrings and
        // ConvertEmptyStringsToNull middleware would alter the signed values.
        parse_str($request->getContent(), $fields);
        $fields = $fields !== [] ? $fields : $request->post();

        if (! $cinetPay->verifyNotification($fields, $request->header('x-token'))) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        if ((string) ($fields['cpm_site_id'] ?? '') !== (string) config('billing.cinetpay.site_id')) {
            return response()->json(['message' => 'Unknown site.'], 422);
        }

        $payment = Payment::query()->where('transaction_id', (string) ($fields['cpm_trans_id'] ?? ''))->first();

        // Unknown transaction: acknowledge so CinetPay stops retrying, nothing to do.
        if ($payment === null) {
            return response()->json(['ok' => true]);
        }

        try {
            $payment = $billing->sync($payment);
        } catch (Throwable $exception) {
            report($exception);

            // Let CinetPay retry later; the hourly reconciliation also catches it.
            return response()->json(['message' => 'Check failed.'], 503);
        }

        return response()->json(['ok' => true, 'status' => $payment->status->value]);
    }
}
