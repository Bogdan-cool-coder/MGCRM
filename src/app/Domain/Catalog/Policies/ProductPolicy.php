<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Product;
use App\Domain\Iam\Models\User;

/**
 * ProductPolicy — read: all auth; write: admin/director only.
 * ARCHITECTURE.md §3: no inline role checks in controllers.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('catalog.manage');
    }
}
