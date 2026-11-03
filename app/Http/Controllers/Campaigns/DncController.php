<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\ContactStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\DncNumber;
use App\Models\Team;
use App\Services\PhoneNumbers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The team's Do-Not-Call list: never dialled by any campaign. */
class DncController extends Controller
{
    use AuthorizesCampaigns;

    public function index(Request $request, Team $currentTeam): Response
    {
        abort_unless($this->canOperate($request->user(), $currentTeam), 403);

        $search = $request->string('search')->toString();

        return Inertia::render('campaigns/Dnc', [
            'numbers' => $currentTeam->dncNumbers()
                ->with('addedBy:id,name')
                ->when($search !== '', fn ($query) => $query->where('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))
                ->latest()
                ->paginate(50)
                ->withQueryString()
                ->through(fn (DncNumber $number) => [
                    'id' => $number->id,
                    'phone' => $number->phone,
                    'reason' => $number->reason,
                    'addedBy' => $number->addedBy?->name,
                    'createdAt' => $number->created_at?->toIso8601String(),
                ]),
            'search' => $search,
            'canManage' => (bool) $request->user()->teamRole($currentTeam)?->isAtLeast(TeamRole::Admin),
        ]);
    }

    /** One number per line (or separated by commas / semicolons). */
    public function store(Request $request, Team $currentTeam): RedirectResponse
    {
        abort_unless($request->user()->teamRole($currentTeam)?->isAtLeast(TeamRole::Admin), 403);

        $data = $request->validate([
            'numbers' => ['required', 'string', 'max:200000'],
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        $phones = collect(preg_split('/[\r\n,;]+/', $data['numbers']) ?: [])
            ->map(fn ($raw) => PhoneNumbers::normalize($raw))
            ->filter()
            ->unique()
            ->values();

        $now = now();

        foreach ($phones->chunk(500) as $chunk) {
            $currentTeam->dncNumbers()->getQuery()->insertOrIgnore($chunk->map(fn ($phone) => [
                'team_id' => $currentTeam->id,
                'phone' => $phone,
                'reason' => $data['reason'] ?? null,
                'added_by' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            // Contacts not yet finished stop being called right away.
            CampaignContact::query()
                ->whereIn('campaign_id', Campaign::query()->where('team_id', $currentTeam->id)->select('id'))
                ->whereIn('phone', $chunk->all())
                ->whereIn('status', [ContactStatus::Pending->value, ContactStatus::Callback->value, ContactStatus::Reserved->value])
                ->update(['status' => ContactStatus::Dnc, 'next_attempt_at' => null, 'updated_at' => $now]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count number added to the Do-Not-Call list.|:count numbers added to the Do-Not-Call list.', $phones->count(), ['count' => $phones->count()])]);

        return back();
    }

    public function destroy(Request $request, Team $currentTeam, DncNumber $number): RedirectResponse
    {
        abort_unless($request->user()->teamRole($currentTeam)?->isAtLeast(TeamRole::Admin), 403);
        abort_unless($number->team_id === $currentTeam->id, 404);

        $number->delete();

        return back();
    }
}
