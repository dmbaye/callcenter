<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboundRate;
use App\Services\OutboundTrunk;
use App\Services\OutboundUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform settings shared by every team: the carrier trunk all outgoing
 * calls (campaigns and manual calls) go through.
 */
class PlatformController extends Controller
{
    public function __construct(private OutboundTrunk $trunk) {}

    public function show(Request $request, OutboundUsage $usage): Response
    {
        [$from, $to, $month] = OutboundUsage::month($request->string('month')->toString());

        return Inertia::render('admin/Platform', [
            'rate' => OutboundRate::current(),
            'rates' => OutboundRate::query()->with('author:id,name')->latest('effective_from')->limit(10)->get()
                ->map(fn (OutboundRate $rate) => [
                    'id' => $rate->id,
                    'price' => $rate->price_per_minute,
                    'from' => $rate->effective_from->toIso8601String(),
                    'by' => $rate->author?->name,
                ])->all(),
            'currency' => config('billing.currency'),
            'month' => $month,
            'months' => OutboundUsage::recentMonths(),
            // Null when the Asterisk database cannot be reached.
            'usage' => rescue(fn () => $usage->perTeam($from, $to), null, report: true),
            'trunk' => $this->trunk->name() ?: null,
            'trunkStored' => $this->trunk->isStored(),
            'candidates' => $this->trunk->candidates(),
            'outboundContext' => config('asterisk.outbound.context'),
            'dialPrefix' => (string) config('asterisk.campaigns.dial_prefix', '00'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trunk' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);

        $this->trunk->set($data['trunk']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Outbound trunk saved: every outgoing call now uses :trunk.', ['trunk' => $data['trunk']]),
        ]);

        return back();
    }

    /** New price per minute of outside calls, from now on (past calls keep theirs). */
    public function updateRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'price_per_minute' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        if ((int) $data['price_per_minute'] !== OutboundRate::current()) {
            OutboundRate::query()->create([
                'price_per_minute' => (int) $data['price_per_minute'],
                'effective_from' => now(),
                'created_by' => $request->user()->id,
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Outside calls are now priced :price F per minute.', ['price' => $data['price_per_minute']]),
        ]);

        return back();
    }
}
