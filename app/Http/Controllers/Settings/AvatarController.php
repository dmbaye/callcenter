<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profile photos: cropped to a 256 px square (WebP or JPEG), kept on the
 * private disk and served only to people who share a team with the user.
 */
class AvatarController extends Controller
{
    private const SIZE = 256;

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=64,min_height=64'],
        ]);

        $user = $request->user();
        $binary = $this->square((string) file_get_contents($request->file('avatar')->getRealPath()));
        $path = 'avatars/'.$user->id.'/'.Str::random(16).'.'.$binary['extension'];

        Storage::disk('local')->put($path, $binary['data']);

        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null) {
            Storage::disk('local')->delete($previous);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo updated.')]);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return back();
    }

    public function show(Request $request, User $user): StreamedResponse
    {
        $viewer = $request->user();

        abort_unless($user->avatar_path !== null && Storage::disk('local')->exists($user->avatar_path), 404);
        abort_unless($viewer->is($user) || $this->shareATeam($viewer, $user), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            // The URL changes with the file (?v=…), so it can be cached.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    private function shareATeam(User $viewer, User $user): bool
    {
        return $viewer->teams()
            ->whereIn('teams.id', $user->teams()->select('teams.id'))
            ->exists();
    }

    /**
     * Centre-crop to a square and scale down; re-encoding also drops EXIF
     * data (GPS position of phone photos).
     *
     * @return array{data: string, extension: string}
     */
    private function square(string $contents): array
    {
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw ValidationException::withMessages(['avatar' => __('This image cannot be read.')]);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled(
            $target, $source, 0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        ob_start();
        $webp = function_exists('imagewebp') && imagewebp($target, null, 85);

        if (! $webp) {
            imagejpeg($target, null, 85);
        }

        $data = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return ['data' => $data, 'extension' => $webp ? 'webp' : 'jpg'];
    }
}
