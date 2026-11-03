<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\AsteriskAmi;
use App\Services\AsteriskQueues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class CallTransferController extends Controller
{
    /**
     * Queues the current agent may transfer calls to.
     */
    public function index(Team $currentTeam): JsonResponse
    {
        return response()->json([
            'queues' => $currentTeam->callQueues()->orderBy('name')->get(['name', 'asterisk_name']),
        ]);
    }

    /**
     * Blind-transfer the agent's active call into another team queue. The
     * caller lands on the queue entry extension of the team context, so
     * opening hours and the accountcode apply as for a direct inbound call.
     */
    public function store(Request $request, Team $currentTeam, AsteriskQueues $queues, AsteriskAmi $ami): JsonResponse
    {
        $data = $request->validate([
            'queue' => ['required', 'string', 'max:80', Rule::in($queues->names($currentTeam))],
        ]);

        $extension = $request->user()->phoneExtensionFor($currentTeam);

        if (! $extension) {
            return response()->json(['message' => __('No SIP extension assigned to your account.')], 422);
        }

        try {
            $active = $ami->activeChannelForExtension($extension->extension, $currentTeam->asterisk_key);

            if ($active === null) {
                return response()->json(['message' => __('You have no active call to transfer.')], 422);
            }

            $ami->blindTransfer($active['channel'], $data['queue'], $currentTeam->asteriskContext());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        $label = $currentTeam->callQueues()->where('asterisk_name', $data['queue'])->value('name');

        return response()->json([
            'ok' => true,
            'message' => __('Call transferred to queue :queue.', ['queue' => $label]),
        ]);
    }
}
