<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\VisibilityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * ContactsKpiService — aggregated list-level KPI counters for the Contacts section.
 *
 * Used by GET /api/contacts/kpi?entity=company|contact to power the KPI-chip bar
 * in the redesigned ContactsPage (Contacts-spec.md §3).
 *
 * Counters are computed with a single Eloquent query each, scoped to the same
 * visibility rules as the list endpoints via VisibilityResolver::applyScope()
 * (owner/responsible filter for Manager role; global for Admin/Director).
 *
 * Definitions:
 *
 * COMPANIES:
 *   total     — all non-deleted companies visible to the user
 *   clients   — client_status = 'active' (active client, i.e. has a signed deal)
 *   cat_l     — category_code = 'L'
 *   cat_m     — category_code = 'M'
 *   cat_s     — category_code IN ('S1', 'S2') — S-tier combined
 *   new_week  — created_at >= now - 7 days
 *
 * CONTACTS:
 *   total       — all non-deleted contacts visible to the user
 *   active      — last_activity_at >= now - 30 days (recently touched)
 *   no_touch_30 — last_activity_at < now - 30 days OR last_activity_at IS NULL
 *   new_week    — created_at >= now - 7 days
 *
 * Visibility rule (mirrors list endpoints via VisibilityResolver):
 *   Admin/Director/Lawyer — see all records (no owner filter)
 *   Manager/Accountant/Cfo — see only records where owner_user_id = user->id (companies)
 *                            or owner_id = user->id (contacts)
 */
class ContactsKpiService
{
    public function __construct(private readonly VisibilityResolver $visibility) {}

    /**
     * KPI counters for the Companies tab.
     *
     * @return array{total: int, clients: int, cat_l: int, cat_m: int, cat_s: int, new_week: int}
     */
    public function forCompanies(User $user): array
    {
        $weekAgo = now()->subDays(7);

        // Use Eloquent builder so VisibilityResolver::applyScope() can be called
        // directly (it types to Eloquent\Builder). onlyTrashed() guard via withoutTrashed()
        // is not needed — Company uses SoftDeletes so the global scope already excludes
        // deleted rows. If SoftDeletes is NOT on Company we guard via whereNull below.
        $base = $this->applyCompanyScope(Company::query(), $user);

        // client_status is an AMO N5 column (migration 2026_06_27_100001); it may not
        // exist on production yet. Guard gracefully: return 0 instead of 500-ing.
        $clientsCount = Schema::hasColumn('crm_companies', 'client_status')
            ? (int) (clone $base)->where('client_status', ClientStatus::Active->value)->count()
            : 0;

        return [
            'total' => (int) (clone $base)->count(),
            'clients' => $clientsCount,
            'cat_l' => (int) (clone $base)->where('category_code', CategoryCode::L->value)->count(),
            'cat_m' => (int) (clone $base)->where('category_code', CategoryCode::M->value)->count(),
            'cat_s' => (int) (clone $base)->whereIn('category_code', [CategoryCode::S1->value, CategoryCode::S2->value])->count(),
            'new_week' => (int) (clone $base)->where('created_at', '>=', $weekAgo)->count(),
        ];
    }

    /**
     * KPI counters for the Contacts (persons) tab.
     *
     * @return array{total: int, active: int, no_touch_30: int, new_week: int}
     */
    public function forContacts(User $user): array
    {
        $weekAgo = now()->subDays(7);
        $monthAgo = now()->subDays(30);

        $base = $this->applyContactScope(Contact::query(), $user);

        return [
            'total' => (int) (clone $base)->count(),
            'active' => (int) (clone $base)->where('last_activity_at', '>=', $monthAgo)->count(),
            'no_touch_30' => (int) (clone $base)->where(function ($q) use ($monthAgo): void {
                $q->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', $monthAgo);
            })->count(),
            'new_week' => (int) (clone $base)->where('created_at', '>=', $weekAgo)->count(),
        ];
    }

    // ---- Private ----

    /**
     * Apply the same visibility scope as CompanyService::list().
     *
     * Routes through VisibilityResolver::applyScope() so the KPI counters match
     * exactly what the list endpoint returns — including the Department branch
     * (currently unreachable in prod but wired so enabling it later is a one-line
     * role-map change). Department scope on Company uses department_id as the
     * anchor; contacts have no department anchor so applyScope() degrades to Own.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function applyCompanyScope(Builder $query, User $user): Builder
    {
        return $this->visibility->applyScope(
            $query,
            $user,
            ownerColumns: ['owner_user_id', 'responsible_user_id'],
            departmentColumn: 'department_id',
        );
    }

    /**
     * Apply the same visibility scope as ContactService::list().
     *
     * Contacts carry no department_id, so Department scope degrades to Own inside
     * VisibilityResolver::applyScope() — matching the list endpoint's behaviour.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function applyContactScope(Builder $query, User $user): Builder
    {
        return $this->visibility->applyScope(
            $query,
            $user,
            ownerColumns: ['owner_id'],
            departmentColumn: null,
        );
    }
}
