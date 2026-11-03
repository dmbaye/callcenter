<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Http\Controllers\Controller;
use App\Models\PhoneExtension;
use App\Services\TeamVoicemail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → Messagerie vocale: a box per extension (PIN, email) and the
 * messages left in them. Owners and admins of the team.
 */
class VoicemailController extends Controller
{
    use AuthorizesAsteriskSettings;

    public function __construct(private TeamVoicemail $voicemail) {}

    public function index(Request $request): Response
    {
        $team = $this->authorizedTeam($request);
        $available = $this->voicemail->available();
        $boxes = $available ? rescue(fn () => $this->voicemail->boxes($team), [], report: true) : [];

        return Inertia::render('settings/asterisk/Voicemail', [
            'available' => $available,
            'messagesAvailable' => $available && $this->voicemail->messagesAvailable(),
            'extensions' => $team->phoneExtensions()->with('user:id,name,email')->orderBy('extension')->get()
                ->map(fn (PhoneExtension $phone) => [
                    'extension' => $phone->extension,
                    'user' => $phone->user?->name,
                    'userEmail' => $phone->user?->email,
                    'box' => $boxes[$phone->extension] ?? null,
                ])->values()->all(),
            'messages' => $available ? rescue(fn () => $this->voicemail->messages($team), [], report: true) : [],
            'accessCode' => '*97',
        ]);
    }

    public function saveBox(Request $request, string $extension): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($team->phoneExtensions()->where('extension', $extension)->exists(), 404);

        $data = $request->validate([
            'pin' => ['nullable', 'string', 'regex:/^\d{4,10}$/'],
            'fullname' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        $this->voicemail->saveBox($team, $extension, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Voicemail box saved.')]);

        return back();
    }

    public function destroyBox(Request $request, string $extension): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($team->phoneExtensions()->where('extension', $extension)->exists(), 404);

        $this->voicemail->deleteBox($team, $extension);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Voicemail box removed.')]);

        return back();
    }

    public function play(Request $request): HttpResponse
    {
        $team = $this->authorizedTeam($request);
        $audio = $this->voicemail->recording($team, (string) $request->query('id'));

        abort_if($audio === null || $audio === '', 404);

        return response($audio, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Length' => (string) strlen($audio),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroyMessage(Request $request): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $this->voicemail->deleteMessage($team, (string) $request->input('id'));

        return back();
    }
}
