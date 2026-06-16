<?php

declare(strict_types=1);

namespace App\Domain\Iam\Services;

use App\Domain\Iam\Models\User;

/**
 * Self-service profile mutations (Iam context).
 *
 * Holds the logic for a user editing their own account. Keeps the controller
 * thin (ARCHITECTURE.md §1): the controller hands over the validated payload and
 * gets back the refreshed user.
 */
class ProfileService
{
    /**
     * Apply a validated profile update and return the saved user.
     *
     * Only keys present in $data are touched, so a request that omits
     * nav_quick_actions leaves the existing value untouched. An explicit null
     * clears the list back to the default.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        if (array_key_exists('nav_quick_actions', $data)) {
            // Re-index so the stored JSON is always a clean ordered list.
            $actions = $data['nav_quick_actions'];
            $user->nav_quick_actions = is_array($actions) ? array_values($actions) : null;
        }

        $user->save();

        return $user;
    }
}
