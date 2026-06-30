<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Models\User;
use Illuminate\Support\Str;

/**
 * Password mutations for the Iam context.
 *
 * Two flows live here so controllers stay thin (ARCHITECTURE.md §1) and the
 * hashing/generation rules sit in one place:
 *  - change()        — a user sets their own new password (ownership already
 *    proven by the FormRequest's current_password check);
 *  - resetByAdmin()  — an admin (re)provisions a target user's password and gets
 *    back the plaintext ONCE so they can hand it over.
 *
 * Passwords are stored ONLY as a hash: the User model's `password` cast is
 * `hashed`, so assigning a plaintext string hashes it on save. The plaintext is
 * never persisted in any form. Existing passwords are irreversible by design —
 * there is intentionally no read-back of a stored credential.
 */
class PasswordService
{
    /**
     * The generated password is composed of upper/lower letters, digits and at
     * least one symbol so it always satisfies a "strong password" policy.
     */
    private const RESET_LENGTH = 16;

    /**
     * Apply a new password to a user (self-service change).
     *
     * The caller's identity / current-password proof is enforced upstream in
     * ChangePasswordRequest; this just assigns the new plaintext, which the
     * `hashed` cast turns into a hash on save.
     */
    public function change(User $user, string $newPassword): User
    {
        $user->password = $newPassword;
        $user->save();

        return $user;
    }

    /**
     * (Re)set a target user's password and return the NEW plaintext once.
     *
     * When $explicit is null a strong random password is generated; otherwise the
     * admin-supplied value is used (validated by the FormRequest). The plaintext
     * is hashed on save (the `hashed` cast) and only the in-memory return value
     * carries it — nothing stores the plaintext. The caller must surface it to
     * the admin a single time and then discard it.
     */
    public function resetByAdmin(User $user, ?string $explicit = null): string
    {
        $plain = $explicit !== null && $explicit !== ''
            ? $explicit
            : $this->generateStrongPassword();

        $user->password = $plain;
        $user->save();

        return $plain;
    }

    /**
     * Generate a strong random password (letters + digits + a symbol).
     *
     * Str::password() already mixes letters, numbers and symbols and is built on
     * a CSPRNG; at length 16 it comfortably exceeds the min-8 policy.
     */
    private function generateStrongPassword(): string
    {
        return Str::password(self::RESET_LENGTH);
    }
}
