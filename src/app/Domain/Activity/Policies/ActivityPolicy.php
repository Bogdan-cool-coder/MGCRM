<?php

declare(strict_types=1);

namespace App\Domain\Activity\Policies;

use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Models\Deal;
use Illuminate\Support\Facades\Gate;

/**
 * ActivityPolicy — visibility-scoped authorization (own / department / all),
 * mirroring DealPolicy. The effective scope is resolved from the user's role via
 * VisibilityResolver so policy access matches ActivityService query filtering
 * exactly. Under Department/Own scope a user always reaches activities where they
 * are responsible or the creator. No inline role checks (ARCHITECTURE.md §3).
 *
 * COHERENT OWNERSHIP (E16): view/update/delete/complete/reopen/changeStatus ALL
 * share ONE ownership test — ownershipAllows() — so the read+edit scope and the
 * complete/delete scope can never invert. Previously delete and the status gates
 * dropped the department branch, so a department manager could EDIT a
 * subordinate's task but not complete or delete it. Now any actor who can see and
 * edit a task (own/responsible/creator + department subtree + All) can also
 * complete and delete it.
 *
 * MUTATIONS additionally re-check that the activity's polymorphic target (deal/
 * company/contact) is STILL visible to the actor (B4): create() gates the target
 * up front, but complete/update/delete/reschedule/changeStatus could otherwise act
 * on a task whose parent deal/company was reassigned out of the actor's scope. The
 * re-check reuses the same Gate('view', $target) mechanism as
 * ActivityService::assertTargetVisible. Standalone (target-less) activities keep
 * the ownership-only rule (targetVisible() is vacuously true for them).
 */
class ActivityPolicy
{
    public function __construct(
        private readonly VisibilityResolver $resolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return true; // listing is visibility-filtered in the service
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->ownershipAllows($user, $activity);
    }

    public function create(User $user): bool
    {
        return true; // any authenticated user may create
    }

    public function update(User $user, Activity $activity): bool
    {
        // Ownership/scope AND current visibility of the polymorphic target (B4):
        // reschedule routes through this same gate (RescheduleActivityRequest
        // authorizes 'update').
        return $this->ownershipAllows($user, $activity)
            && $this->targetVisible($user, $activity);
    }

    /**
     * Delete shares the exact ownership model as view/update (E16): own /
     * responsible / creator + department subtree + All. A department manager who
     * can see and edit a subordinate's task can also delete it. The B4 target
     * re-check still applies — a task whose parent deal moved out of scope is no
     * longer actionable.
     */
    public function delete(User $user, Activity $activity): bool
    {
        return $this->ownershipAllows($user, $activity)
            && $this->targetVisible($user, $activity);
    }

    /**
     * complete / reopen / status share the SAME ownership model as view/update
     * (E16): own / responsible / creator + department subtree + All, AND the
     * activity's target must still be visible to the actor (B4). Aligning these
     * with update/view removes the old inversion where a department manager could
     * edit but not complete/reopen a subordinate's task.
     */
    public function complete(User $user, Activity $activity): bool
    {
        return $this->ownershipAllows($user, $activity)
            && $this->targetVisible($user, $activity);
    }

    public function reopen(User $user, Activity $activity): bool
    {
        return $this->complete($user, $activity);
    }

    public function changeStatus(User $user, Activity $activity): bool
    {
        return $this->complete($user, $activity);
    }

    // ---- Private ----

    /**
     * Re-check that the activity's polymorphic target (deal/company/contact) is
     * STILL visible to the actor (B4). Reuses the owning context's `view` policy
     * via the Gate — the exact mechanism ActivityService::assertTargetVisible uses
     * on create — so the mutation gate can never drift from the create-time gate.
     *
     * A standalone (target-less) activity has no target to gate → always visible
     * (the ownership rules in canAccess/complete/delete still apply). A target
     * whose row was deleted resolves to null → not visible.
     */
    private function targetVisible(User $user, Activity $activity): bool
    {
        $type = $activity->target_type !== null
            ? ActivityTargetType::tryFrom((string) $activity->target_type)
            : null;

        if ($type === null || $activity->target_id === null) {
            return true; // standalone personal task — no target to gate
        }

        $targetId = (int) $activity->target_id;

        $model = match ($type) {
            ActivityTargetType::Deal => Deal::find($targetId),
            ActivityTargetType::Company => Company::find($targetId),
            ActivityTargetType::Contact => Contact::find($targetId),
        };

        return $model !== null && Gate::forUser($user)->allows('view', $model);
    }

    /**
     * The single shared ownership test (E16) used by EVERY activity gate
     * (view/update/delete/complete/reopen/changeStatus). Resolving the actor's
     * visibility scope and branching here once means the gates can never drift
     * apart again:
     *   All        — any activity.
     *   Own        — the actor is responsible or the creator.
     *   Department — own/responsible/creator OR the activity's department_id falls
     *                in the actor's department subtree.
     * The B4 target re-check is layered ON TOP of this by the mutating gates.
     */
    private function ownershipAllows(User $user, Activity $activity): bool
    {
        return match ($this->resolver->resolve($user)) {
            VisibilityScope::All => true,
            VisibilityScope::Own => $this->isOwnerOrResponsible($user, $activity),
            VisibilityScope::Department => $this->inDepartmentSubtree($user, $activity),
        };
    }

    private function isOwnerOrResponsible(User $user, Activity $activity): bool
    {
        return (int) $activity->responsible_id === $user->id
            || (int) $activity->created_by_id === $user->id;
    }

    private function inDepartmentSubtree(User $user, Activity $activity): bool
    {
        // Activities the user is responsible for / created are always reachable.
        if ($this->isOwnerOrResponsible($user, $activity)) {
            return true;
        }

        if ($user->department_id === null || $activity->department_id === null) {
            return false;
        }

        return in_array((int) $activity->department_id, $this->resolver->departmentSubtreeIds($user), true);
    }
}
