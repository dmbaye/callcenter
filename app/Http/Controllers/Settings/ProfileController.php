<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Not in auth.user (hidden / shared on every page): the extra profile fields.
            'details' => $user->only(['gender', 'job_title', 'department', 'phone', 'locale']),
            'genders' => collect(User::GENDERS)->map(fn (string $value) => [
                'value' => $value,
                'label' => match ($value) {
                    'female' => __('Female'),
                    'male' => __('Male'),
                    'other' => __('Other'),
                    default => __('Prefer not to say'),
                },
            ])->all(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // The toast below is already in the new language.
        app()->setLocale($request->user()->locale ?? config('app.locale'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        $response = to_route('profile.edit');

        return $request->user()->locale
            ? $response->withCookie(cookie()->forever('locale', $request->user()->locale))
            : $response;
    }

    /** Switch the interface language (fr | en) from anywhere. */
    public function locale(Request $request): RedirectResponse
    {
        $locale = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED)],
        ])['locale'];

        $request->user()->forceFill(['locale' => $locale])->save();

        return back()->withCookie(cookie()->forever('locale', $locale));
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk('local')->delete($user->avatar_path);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
