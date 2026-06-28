<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Iam\Models\User;

class ProductGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductGroup $group): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, ProductGroup $group): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, ProductGroup $group): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('catalog.manage');
    }
}
