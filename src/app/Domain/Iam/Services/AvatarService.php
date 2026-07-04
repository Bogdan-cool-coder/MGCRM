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
 * (sidebar / account menu / Orbita), so they live on the public disk. The
 * `avatar_path` column stores a ROOT-RELATIVE URL (`/storage/avatars/...`),
 * never an absolute one: the SPA and the API share an origin in prod, while in
 * dev the SPA runs on the Vite origin (:5173) and reaches storage through the
 * dev proxy. An absolute `APP_URL`-based URL would be cross-origin for the Vite
 * dev server and get blocked by the browser (ERR_BLOCKED_BY_ORB); a relative
 * URL resolves against whatever origin serves the page. The previous file (if
 * any) is deleted on replace so storage does not accumulate orphans.
 */
class AvatarService
{
    private const DISK = 'public';

    private const DIR = 'avatars';

    /**
     * Store a new avatar for the user, replacing any existing one, and persist
     * a root-relative public URL on `avatar_path`. Returns the refreshed user.
     */
    public function store(User $user, UploadedFile $file): User
    {
        $this->deleteExisting($user);

        $extension = $file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $filename = sprintf('%d_%s.%s', $user->id, Str::random(16), $extension);

        $path = $file->storeAs(self::DIR, $filename, ['disk' => self::DISK]);

        $user->avatar_path = $this->relativeUrl($path);
        $user->save();

        return $user;
    }

    /**
     * Build a root-relative public URL for a stored path, e.g.
     * `avatars/x.jpg` → `/storage/avatars/x.jpg`. Derives the `/storage`
     * prefix from the disk's configured `url` (stripping scheme + host) so it
     * stays correct if the public path root ever changes.
     */
    private function relativeUrl(string $path): string
    {
        $absolute = Storage::disk(self::DISK)->url($path);
        $relative = parse_url($absolute, PHP_URL_PATH);

        // Fallback for a disk configured without scheme/host (already relative).
        return $relative !== null && $relative !== false ? $relative : '/'.ltrim($absolute, '/');
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
     *
     * Handles both the current root-relative form (`/storage/avatars/x.jpg`)
     * and legacy absolute values (`https://host/storage/avatars/x.jpg`) still
     * present in existing rows, by reducing the stored value to a disk-relative
     * path (`avatars/x.jpg`) via the disk's public URL path prefix.
     */
    private function deleteExisting(User $user): void
    {
        $current = $user->avatar_path;
        if ($current === null || $current === '') {
            return;
        }

        // Path portion of both the stored value and the disk base URL, so this
        // works whether the stored value is absolute or already relative.
        $currentPath = parse_url($current, PHP_URL_PATH);
        $basePath = parse_url(Storage::disk(self::DISK)->url(''), PHP_URL_PATH);
        if (! is_string($currentPath) || ! is_string($basePath)) {
            return;
        }

        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && ! str_starts_with($currentPath, $basePath)) {
            return;
        }

        $relative = ltrim(Str::after($currentPath, $basePath), '/');
        if ($relative !== '' && Storage::disk(self::DISK)->exists($relative)) {
            Storage::disk(self::DISK)->delete($relative);
        }
    }
}
