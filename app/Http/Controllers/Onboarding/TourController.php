<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The guided tour runs in the browser; the server only remembers that the
 * user saw it, so it does not start again on the next page or device.
 */
class TourController extends Controller
{
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->tour_completed_at === null) {
            $user->forceFill(['tour_completed_at' => now()])->save();
        }

        return response()->json(['completedAt' => $user->tour_completed_at->toIso8601String()]);
    }
}
