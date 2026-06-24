<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Services\PipelineService;

/**
 * PipelinePolicy — reads honour pipeline visibility; only admin/director mutate.
 * Visibility (visible_role / visible_user_ids) is enforced through
 * PipelineService::canAccess so the list and the by-id read can never drift.
 */
class PipelinePolicy
{
    public function __construct(
        private readonly PipelineService $pipelines,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $this->pipelines->canAccess($pipeline, $user);
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
