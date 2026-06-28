<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\SavedView;
use App\Domain\Iam\Models\User;

/**
 * SavedViewPolicy — access rules for crm_saved_views.
 *
 * Visibility:
 *   - Personal views (is_shared=false): owner only.
 *   - Shared views (is_shared=true): all authenticated users can view.
 *
 * Mutation:
 *   - Owner can update/delete their own views (personal + shared they created).
 *   - Admin and Director can update/delete any view.
 */
class SavedViewPolicy
{
    /**
     * List views — always allowed; filtering by visibility is done in the service.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * View a single saved view — owner, or any user when shared.
     */
    public function view(User $user, SavedView $view): bool
    {
        if ($view->is_shared) {
            return true;
        }

        return $view->user_id === $user->id;
    }

    /**
     * Create a saved view — any authenticated user may create their own views.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Update — owner or admin/director.
     */
    public function update(User $user, SavedView $view): bool
    {
        return $this->canMutate($user, $view);
    }

    /**
     * Delete — owner or admin/director.
     */
    public function delete(User $user, SavedView $view): bool
    {
        return $this->canMutate($user, $view);
    }

    // ---- Private ----

    private function canMutate(User $user, SavedView $view): bool
    {
        if ($user->can('crm.saved-views.manage-all')) {
            return true;
        }

        return $view->user_id === $user->id;
    }
}
