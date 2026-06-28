<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Iam\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ExchangeRate $rate): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function update(User $user, ExchangeRate $rate): bool
    {
        return $this->isAdminOrDirector($user);
    }

    public function delete(User $user, ExchangeRate $rate): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return $user->can('catalog.manage');
    }
}
