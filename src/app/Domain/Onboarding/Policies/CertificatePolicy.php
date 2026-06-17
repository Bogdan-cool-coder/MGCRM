<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Policies;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Certificate;

/**
 * CertificatePolicy — controls who can view and regenerate certificates.
 *
 * admin/director → all certificates
 * student       → own certificate only (assignment.user_id === auth user)
 */
class CertificatePolicy
{
    /**
     * Admin/director can list all certificates; student list is filtered in
     * the controller (not via viewAny gate) — this gate guards the admin list.
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdminOrDirector($user);
    }

    /**
     * Admin/director can view any certificate.
     * Student can view their own certificate only.
     */
    public function view(User $user, Certificate $certificate): bool
    {
        if ($this->isAdminOrDirector($user)) {
            return true;
        }

        return $user->id === $certificate->assignment->user_id;
    }

    /**
     * Only admin/director can regenerate a certificate.
     */
    public function regenerate(User $user, Certificate $certificate): bool
    {
        return $this->isAdminOrDirector($user);
    }

    private function isAdminOrDirector(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Director], strict: true);
    }
}
