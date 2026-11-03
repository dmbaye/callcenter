<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing;
use App\Services\Payments\PayDunya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * PayDunya IPN: a form-encoded POST whose fields are under `data`.
 *
 * data[hash] (SHA-512 of the master key) proves the call comes from
 * PayDunya, but the payment state is still taken from the confirm API,
 * never from the posted fields. IPNs repeat, so the handler is idempotent
 * (Billing::sync()).
 */
class PayDunyaWebhookController extends Controller
{
    public function __invoke(Request $request, PayDunya $payDunya, Billing $billing): JsonResponse
    {
        if (! $payDunya->isConfigured()) {
            return response()->json(['message' => 'Payments are not configured.'], 503);
        }

        // The raw body, not $request->post(): the global TrimStrings and
        // ConvertEmptyStringsToNull middleware would alter the posted values.
        parse_str($request->getContent(), $fields);
        $data = (array) ($fields['data'] ?? $request->input('data', []));

        if (! $payDunya->verifyNotificationHash(isset($data['hash']) ? (string) $data['hash'] : null)) {
            return response()->json(['message' => 'Invalid hash.'], 401);
        }

        $token = (string) data_get($data, 'invoice.token', '');
        $transaction = (string) data_get($data, 'custom_data.transaction_id', '');

        $payment = Payment::query()
            ->where('gateway', 'paydunya')
            ->where(fn ($query) => $query
                ->where('gateway_reference', $token !== '' ? $token : '-')
                ->orWhere('transaction_id', $transaction !== '' ? $transaction : '-'))
            ->first();

        // Unknown invoice: acknowledge so PayDunya stops retrying, nothing to do.
        if ($payment === null) {
            return response()->json(['ok' => true]);
        }

        // An invoice created before the token was saved: keep it now (still confirmed below).
        if (blank($payment->gateway_reference) && $token !== '') {
            $payment->update(['gateway_reference' => $token]);
        }

        try {
            $payment = $billing->sync($payment);
        } catch (Throwable $exception) {
            report($exception);

            // Let PayDunya retry later; the hourly reconciliation also catches it.
            return response()->json(['message' => 'Check failed.'], 503);
        }

        return response()->json(['ok' => true, 'status' => $payment->status->value]);
    }
}
