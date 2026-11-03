<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\CallRecordings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Receives a finished call recording from Asterisk
 * (deploy/asterisk/cc-recording-upload, run by MixMonitor).
 *
 * Authenticated with a bearer token. A 2xx answer tells the script it may
 * delete its copy, so files the app does not keep (unknown team, plan
 * without recording) are still acknowledged.
 */
class AsteriskRecordingWebhookController extends Controller
{
    public function __invoke(Request $request, CallRecordings $recordings): JsonResponse
    {
        $token = (string) config('asterisk.recordings.token');

        // Fail closed: no token configured means the endpoint is off.
        if ($token === '') {
            return response()->json(['message' => 'Recording upload is not configured.'], 503);
        }

        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'account' => ['required', 'string', 'regex:/^[a-z0-9]{10}$/'],
            'uniqueid' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'linkedid' => ['nullable', 'string', 'max:64', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'file' => ['required', 'file', 'max:'.(int) config('asterisk.recordings.max_upload_kb')],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid payload.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $team = Team::query()->where('asterisk_key', $data['account'])->first();

        if ($team === null || ! $team->plan()->allows('call_recording')) {
            return response()->json(['stored' => false], 202);
        }

        $recording = $recordings->store($team, $data['uniqueid'], $data['linkedid'] ?? null, $request->file('file'));

        return response()->json(['stored' => true, 'id' => $recording->id], 201);
    }
}
