<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\VisibilityScope;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use App\Domain\Sales\Models\Deal;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization (realtime — Phase 7a)
|--------------------------------------------------------------------------
|
| Every private channel below reuses the EXISTING authorization surface — the
| per-record Policy `view` gate and the department-subtree walk in
| VisibilityResolver — so a subscription can never leak a record the REST layer
| would 403/404. There is deliberately no new authorization logic here: the
| channel callback is a thin adapter onto Gate + VisibilityResolver.
|
| The subscriber is resolved by the /broadcasting/auth endpoint through the
| sanctum guard (bootstrap/app.php withBroadcasting attempts). A callback that
| returns false → 403 → the client is denied the subscription.
|
| Entity channels (deal/company/contact) authorize with the record's own
| `view` Policy. Personal channels (user) authorize by identity. Department
| list channels (dept.{id}.deals|tasks|contacts) authorize by "is this user's
| visibility scope All, OR does the department fall inside the user's own
| department subtree" — the same rule the list services apply, so a manager can
| subscribe to exactly the department lists they may read.
|
*/

/**
 * Personal channel: a user's own tasks + notifications. Identity only.
 */
Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

/**
 * Deal entity channel: drives the live deal-card feed + per-deal patches.
 * Authorized by DealPolicy::view (visibility-scoped). Returns false (403) when
 * the deal is missing or out of scope.
 */
Broadcast::channel('deal.{dealId}', function (User $user, int $dealId): bool {
    $deal = Deal::find($dealId);

    return $deal !== null && Gate::forUser($user)->allows('view', $deal);
});

/**
 * Company entity channel: live company-card feed + patches.
 * Authorized by CompanyPolicy::view.
 */
Broadcast::channel('company.{companyId}', function (User $user, int $companyId): bool {
    $company = Company::find($companyId);

    return $company !== null && Gate::forUser($user)->allows('view', $company);
});

/**
 * Contact entity channel: live contact-card feed + patches.
 * Authorized by ContactPolicy::view.
 */
Broadcast::channel('contact.{contactId}', function (User $user, int $contactId): bool {
    $contact = Contact::find($contactId);

    return $contact !== null && Gate::forUser($user)->allows('view', $contact);
});

/**
 * Department LIST channels — power live board/task/contact lists.
 *
 * A user may subscribe to a department's list stream when:
 *   - their visibility scope is All (admin/director/lawyer see every department), OR
 *   - the department id falls inside their OWN department subtree
 *     (VisibilityResolver::departmentSubtreeIds — the exact set the list
 *     services scope to under Department scope).
 *
 * Own-scope roles (accountant/cfo) with no All widening only match their own
 * subtree, so they never subscribe to a foreign department's list. This mirrors
 * the row-level filtering in the list endpoints — the channel grant and the REST
 * grant share ONE department-subtree source and cannot drift.
 *
 * The three list flavours (deals/tasks/contacts) share the identical grant rule;
 * they differ only in which events publish to them.
 */
$departmentListAuth = static function (User $user, int $departmentId): bool {
    $resolver = app(VisibilityResolver::class);

    // All-scope roles may observe any department's list stream.
    if ($resolver->resolve($user) === VisibilityScope::All) {
        return true;
    }

    return in_array($departmentId, $resolver->departmentSubtreeIds($user), true);
};

Broadcast::channel('dept.{departmentId}.deals', $departmentListAuth);
Broadcast::channel('dept.{departmentId}.tasks', $departmentListAuth);
Broadcast::channel('dept.{departmentId}.contacts', $departmentListAuth);
