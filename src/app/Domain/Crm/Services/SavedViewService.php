<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\SavedViewEntity;
use App\Domain\Crm\Models\SavedView;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SavedViewService — CRUD for crm_saved_views with visibility scoping.
 *
 * Visibility rules:
 *   - All authenticated users see shared views (is_shared=true).
 *   - Each user additionally sees their own personal views (user_id=me).
 *   - Mutation (update/delete/set-default) is handled by SavedViewPolicy.
 *
 * is_default:
 *   - At most one default per (user_id, entity_type).
 *   - setDefault() clears the previous default in the same transaction.
 */
class SavedViewService
{
    /**
     * List visible saved views for a user and entity type.
     * Returns: own personal views + all shared views, ordered by is_shared ASC
     * (personal first), then name.
     *
     * @return Collection<int, SavedView>
     */
    public function list(User $user, SavedViewEntity $entity): Collection
    {
        return SavedView::query()
            ->where('entity_type', $entity->value)
            ->where(static function ($q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhere('is_shared', true);
            })
            ->orderBy('is_shared')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new saved view owned by the user.
     * If is_default=true, clears any existing default for the user+entity.
     */
    public function create(User $user, SavedViewEntity $entity, string $name, bool $isShared, bool $isDefault, array $payload): SavedView
    {
        return DB::transaction(function () use ($user, $entity, $name, $isShared, $isDefault, $payload): SavedView {
            if ($isDefault) {
                $this->clearUserDefault($user->id, $entity);
            }

            return SavedView::create([
                'user_id' => $user->id,
                'name' => $name,
                'entity_type' => $entity->value,
                'is_shared' => $isShared,
                'is_default' => $isDefault,
                'payload' => $payload,
            ]);
        });
    }

    /**
     * Update an existing saved view. Only the owner or admin/director may call this
     * (enforced by policy before this method).
     * If is_default flips to true, clears previous default.
     */
    public function update(SavedView $view, string $name, bool $isShared, bool $isDefault, array $payload): SavedView
    {
        return DB::transaction(function () use ($view, $name, $isShared, $isDefault, $payload): SavedView {
            if ($isDefault && ! $view->is_default) {
                $this->clearUserDefault($view->user_id, SavedViewEntity::from($view->entity_type->value));
            }

            $view->update([
                'name' => $name,
                'is_shared' => $isShared,
                'is_default' => $isDefault,
                'payload' => $payload,
            ]);

            return $view->fresh();
        });
    }

    /**
     * Delete a saved view.
     */
    public function delete(SavedView $view): void
    {
        $view->delete();
    }

    /**
     * Mark a view as the user's default for its entity_type, clearing any
     * previous default for the same (user_id, entity_type) pair.
     */
    public function setDefault(User $user, SavedView $view): SavedView
    {
        return DB::transaction(function () use ($user, $view): SavedView {
            $this->clearUserDefault($user->id, SavedViewEntity::from($view->entity_type->value));

            $view->update(['is_default' => true]);

            return $view->fresh();
        });
    }

    // ---- Private ----

    private function clearUserDefault(int $userId, SavedViewEntity $entity): void
    {
        SavedView::query()
            ->where('user_id', $userId)
            ->where('entity_type', $entity->value)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
