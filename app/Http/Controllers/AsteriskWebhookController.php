<?php

namespace App\Http\Controllers;

use App\Services\MonitoringNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Signed inbound webhook: "this tenant's live call state changed".
 *
 * Meant for anything that is not the AMI daemon but knows about a change
 * (a dialplan CURL() hook, an external PBX, a script). It only triggers a
 * refresh ping; it never carries or exposes call data.
 */
class AsteriskWebhookController extends Controller
{
    public function __invoke(Request $request, MonitoringNotifier $notifier): JsonResponse
    {
        $secret = (string) config('asterisk.webhook.secret');

        // Fail closed: no secret configured means the endpoint is off.
        if ($secret === '') {
            return response()->json(['message' => 'Webhook is not configured.'], 503);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Signature', ''))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $validator = Validator::make($request->json()->all(), [
            'team_key' => ['required', 'string', 'regex:/^[a-z0-9]{10}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid payload.', 'errors' => $validator->errors()], 422);
        }

        // Same answer whether or not the key exists, so tenants cannot be probed.
        $notifier->notifyKeys([$validator->validated()['team_key']]);

        return response()->json(['ok' => true], 202);
    }
}
