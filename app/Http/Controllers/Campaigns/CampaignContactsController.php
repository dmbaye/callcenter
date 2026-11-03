<?php

namespace App\Http\Controllers\Campaigns;

use App\Enums\ContactStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Team;
use App\Services\CampaignContactsImporter;
use App\Services\PhoneNumbers;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CampaignContactsController extends Controller
{
    use AuthorizesCampaigns;

    public function import(Request $request, Team $currentTeam, Campaign $campaign, CampaignContactsImporter $importer, PlanLimits $limits): RedirectResponse
    {
        $this->authorizeManage($request, $currentTeam, $campaign);
        $limits->ensureFeature($currentTeam, 'campaigns', 'file');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        $stats = $importer->import($campaign, $request->file('file')->getRealPath());

        Inertia::flash('toast', [
            'type' => $stats['imported'] > 0 ? 'success' : 'warning',
            'message' => __(':imported imported, :duplicates duplicates, :invalid invalid numbers, :dnc on the Do-Not-Call list.', $stats),
        ]);

        return back();
    }

    public function store(Request $request, Team $currentTeam, Campaign $campaign): RedirectResponse
    {
        $this->authorizeManage($request, $currentTeam, $campaign);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'company' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        $phone = PhoneNumbers::normalize($data['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('This phone number is not valid.')]);
        }

        if ($currentTeam->dncNumbers()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => __('This number is on the Do-Not-Call list.')]);
        }

        if ($campaign->contacts()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => __('This number is already in the campaign.')]);
        }

        $campaign->contacts()->create([...$data, 'phone' => $phone, 'status' => ContactStatus::Pending]);

        return back();
    }

    public function destroy(Request $request, Team $currentTeam, Campaign $campaign, CampaignContact $contact): RedirectResponse
    {
        $this->authorizeManage($request, $currentTeam, $campaign);
        abort_unless($contact->campaign_id === $campaign->id, 404);
        abort_if(in_array($contact->status, [ContactStatus::Dialing, ContactStatus::Wrapup], true), 422, __('This contact is in a call.'));

        $contact->delete();

        return back();
    }

    private function authorizeManage(Request $request, Team $team, Campaign $campaign): void
    {
        $this->ensureTeamCampaign($team, $campaign);
        abort_unless($request->user()->teamRole($team)?->isAtLeast(TeamRole::Admin), 403);
    }
}
