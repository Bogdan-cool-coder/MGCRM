<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;

/**
 * PipelinePolicy — everyone may read pipelines; only admin/director may mutate.
 * Pipeline/stage CRUD itself lands in S1.5; the write gates exist now so the
 * routes are protected from day one.
 */
class PipelinePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Pipeline $pipeline): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $this->isManager($user);
    }

    private function isManager(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Director], true);
    }
}
