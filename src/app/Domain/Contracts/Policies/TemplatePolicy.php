<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Policies;

use App\Domain\Contracts\Models\Template;
use App\Domain\Iam\Models\User;

/**
 * TemplatePolicy — update restricted to admin and lawyer; director read-only.
 * create/delete via UI is not supported in S2.1 (seeder-only).
 * S2.3: uploadVersion / check / override — lawyer and admin.
 * ARCHITECTURE.md §3: no inline role checks in controllers.
 */
class TemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Template $template): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.admin');
    }

    public function update(User $user, Template $template): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user, Template $template): bool
    {
        return $user->can('contracts.admin');
    }

    /** POST /api/templates/{template}/upload — upload a new docx version. */
    public function uploadVersion(User $user, Template $template): bool
    {
        return $this->canWrite($user);
    }

    /** GET /api/templates/{template}/versions — list versions. */
    public function viewVersions(User $user, Template $template): bool
    {
        return true;
    }

    /** POST /api/templates/{template}/versions/{version}/check — re-dispatch AI check. */
    public function checkVersion(User $user, Template $template): bool
    {
        return $this->canWrite($user);
    }

    /** POST /api/templates/{template}/versions/{version}/override — override AI remarks. */
    public function overrideVersion(User $user, Template $template): bool
    {
        return $this->canWrite($user);
    }

    private function canWrite(User $user): bool
    {
        return $user->can('contracts.approve');
    }
}
