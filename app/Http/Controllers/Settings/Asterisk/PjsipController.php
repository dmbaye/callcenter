<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskPjsip;
use App\Services\AsteriskProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Team extensions and their PJSIP endpoints. Each row here is a
 * phone_extensions record kept in sync with Asterisk by the provisioner;
 * the endpoint context is always the team context.
 */
class PjsipController extends Controller
{
    use AuthorizesAsteriskSettings;

    public function index(Request $request, AsteriskPjsip $pjsip): Response
    {
        $team = $this->authorizedTeam($request);

        $extensions = $team->phoneExtensions()->with('user:id,name')->get()->keyBy(
            fn ($phone) => $pjsip->endpointId($team, $phone->extension),
        );

        $endpoints = collect($pjsip->endpointsForTeam($team))->map(fn (array $endpoint) => [
            ...$endpoint,
            'extension' => substr((string) $endpoint['id'], strlen($team->asterisk_key) + 1),
            'user_id' => $extensions->get($endpoint['id'])?->user_id,
            'user_name' => $extensions->get($endpoint['id'])?->user?->name,
            'linked' => $extensions->has($endpoint['id']),
        ]);

        // Extensions without an endpoint (e.g. created before provisioning).
        $missing = $extensions->keys()->diff($endpoints->pluck('id'))->map(fn (string $id) => [
            'id' => $id,
            'extension' => $extensions[$id]->extension,
            'user_id' => $extensions[$id]->user_id,
            'user_name' => $extensions[$id]->user?->name,
            'linked' => true,
            'missing' => true,
        ]);

        return Inertia::render('settings/asterisk/Pjsip', [
            'endpoints' => $endpoints->concat($missing)->values(),
            'members' => $team->members()->orderBy('users.name')->get(['users.id', 'users.name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'teamContext' => $team->asteriskContext(),
            'accountcodeSupported' => $pjsip->supportsAccountcode(),
        ]);
    }

    public function store(Request $request, AsteriskProvisioner $provisioner): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $data = $this->validated($request, $team, creating: true);

        if ($team->phoneExtensions()->where('extension', $data['extension'])->exists()) {
            throw ValidationException::withMessages([
                'extension' => __('An endpoint with this extension already exists in this team.'),
            ]);
        }

        $this->save($provisioner, $team, $data);

        return back();
    }

    public function update(Request $request, AsteriskProvisioner $provisioner): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $data = $this->validated($request, $team, creating: false);

        // Adopting an endpoint created outside the app: its secret is unknown, so it must be set.
        if (empty($data['password']) && ! $team->phoneExtensions()->where('extension', $data['extension'])->exists()) {
            throw ValidationException::withMessages([
                'password' => __('Set a SIP password to manage this endpoint from the application.'),
            ]);
        }

        $this->save($provisioner, $team, $data);

        return back();
    }

    public function destroy(Request $request, AsteriskPjsip $pjsip, AsteriskProvisioner $provisioner): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $data = $request->validate([
            'extension' => ['required', 'string', 'regex:/^\d{2,20}$/', 'not_regex:/^00/'],
        ]);

        $phone = $team->phoneExtensions()->where('extension', $data['extension'])->first();

        if ($phone) {
            $phone->setRelation('team', $team);
            $provisioner->deleteExtension($phone);
        } else {
            // An endpoint that only exists in Asterisk (legacy row).
            $pjsip->delete($pjsip->endpointId($team, $data['extension']));
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Team $team, bool $creating): array
    {
        return $request->validate([
            'extension' => ['required', 'string', 'regex:/^\d{2,20}$/', 'not_regex:/^00/'],
            'user_id' => ['nullable', 'integer', Rule::exists('team_members', 'user_id')->where('team_id', $team->id)],
            'transport' => [$creating ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^[\w.-]+$/'],
            'allow' => [$creating ? 'required' : 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9_,]+$/i'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'max:80'],
            'max_contacts' => ['nullable', 'integer', 'between:1,10'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function save(AsteriskProvisioner $provisioner, Team $team, array $data): void
    {
        try {
            $provisioner->saveExtension($team, $data['extension'], [
                'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
                'secret' => $data['password'] ?? null,
                'transport' => $data['transport'] ?? null,
                'allow' => $data['allow'] ?? null,
                'max_contacts' => isset($data['max_contacts']) ? (int) $data['max_contacts'] : null,
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['extension' => $exception->getMessage()]);
        }
    }
}
