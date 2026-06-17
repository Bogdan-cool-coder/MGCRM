<?php

declare(strict_types=1);

namespace App\Domain\Automation\Policies;

use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;

/**
 * PipelineAutomationPolicy (M7 P4) — ARCHITECTURE.md §3 policy-based access.
 *
 * Configuring automations is a privileged, system-shaping operation: a single
 * rule can re-assign owners, move deals, fire webhooks and generate documents
 * across a whole pipeline. The spec gates the whole builder behind the
 * `automation.manage` ability, which the RolePermissionSeeder grants to admin
 * and director only.
 *
 * The gate is expressed as the role-enum check those two roles carry, matching
 * every other policy in the codebase (PipelinePolicy / ApprovalRoutePolicy) and
 * keeping the test harness (factory `role` column) honest without seeding spatie
 * assignments on every request. The `automation.manage` permission is the
 * canonical name; this policy is its enforcement point.
 *
 * There are no per-record nuances yet (an automation has no owner scope) so view
 * and mutate share the same gate. The runs journal is read-only and gated the
 * same way (viewAny on AutomationRun delegates here via the controller).
 */
class PipelineAutomationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, PipelineAutomation $automation): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, PipelineAutomation $automation): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, PipelineAutomation $automation): bool
    {
        return $this->manages($user);
    }

    /**
     * Dry-run preview — same privilege as editing the automation it previews.
     */
    public function test(User $user, PipelineAutomation $automation): bool
    {
        return $this->manages($user);
    }

    /**
     * The `automation.manage` ability — admin / director only.
     */
    private function manages(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Director], strict: true);
    }
}
