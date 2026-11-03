<?php

namespace App\Http\Controllers\Marketing;

use App\Data\Plan;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Privacy policy, legal notice and trust center. The texts live in the Vue
 * pages; the company identity and sub-processors come from config/legal.php,
 * retention periods from the plans the app enforces.
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('marketing/legal/Privacy', [
            'legal' => $this->legal(),
            'plans' => $this->plans(),
        ]);
    }

    public function notice(): Response
    {
        return Inertia::render('marketing/legal/Notice', ['legal' => $this->legal()]);
    }

    public function trust(): Response
    {
        return Inertia::render('marketing/legal/Trust', [
            'legal' => $this->legal(),
            'plans' => $this->plans(),
        ]);
    }

    /** @return array<string, mixed> */
    private function legal(): array
    {
        $legal = (array) config('legal');
        // A sub-processor without a name is not chosen yet: shown as « à compléter ».
        $legal['subprocessors'] = array_values((array) ($legal['subprocessors'] ?? []));

        return [...$legal, 'appName' => config('app.name'), 'appUrl' => config('app.url')];
    }

    /**
     * Retention and meeting video quality per plan, as enforced.
     *
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return array_values(array_map(fn (Plan $plan) => [
            'name' => $plan->name,
            'historyMonths' => $plan->limit('history_months'),
            'recordingDays' => $plan->allows('call_recording') ? $plan->limit('recording_days') : null,
        ], Plan::all()));
    }
}
