<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Avatar storage for the Iam context.
 *
 * Avatars are user-facing images rendered in <img> tags across the shell
 * (sidebar / account menu / Orbita), so they live on the public disk and
 * `avatar_path` stores a directly renderable URL. The previous file (if any) is
 * deleted on replace so storage does not accumulate orphans.
 */
class AvatarService
{
    private const DISK = 'public';

    private const DIR = 'avatars';

    /**
     * Store a new avatar for the user, replacing any existing one, and persist
     * the public URL on `avatar_path`. Returns the refreshed user.
     */
    public function store(User $user, UploadedFile $file): User
    {
        $this->deleteExisting($user);

        $extension = $file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $filename = sprintf('%d_%s.%s', $user->id, Str::random(16), $extension);

        $path = $file->storeAs(self::DIR, $filename, ['disk' => self::DISK]);

        $user->avatar_path = Storage::disk(self::DISK)->url($path);
        $user->save();

        return $user;
    }

    /**
     * Remove the user's avatar (file + column).
     */
    public function remove(User $user): User
    {
        $this->deleteExisting($user);

        $user->avatar_path = null;
        $user->save();

        return $user;
    }

    /**
     * Delete the on-disk file backing the user's current avatar_path, if it is
     * one this service stored on the public disk.
     */
    private function deleteExisting(User $user): void
    {
        $current = $user->avatar_path;
        if ($current === null || $current === '') {
            return;
        }

        $base = rtrim(Storage::disk(self::DISK)->url(''), '/');
        if (! str_starts_with($current, $base)) {
            return;
        }

        $relative = ltrim(Str::after($current, $base), '/');
        if ($relative !== '' && Storage::disk(self::DISK)->exists($relative)) {
            Storage::disk(self::DISK)->delete($relative);
        }
    }
}
