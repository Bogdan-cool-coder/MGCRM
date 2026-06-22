<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ContactsKpiService — aggregated list-level KPI counters for the Contacts section.
 *
 * Used by GET /api/contacts/kpi?entity=company|contact to power the KPI-chip bar
 * in the redesigned ContactsPage (Contacts-spec.md §3).
 *
 * Counters are computed with a single DB::table() call each (no Eloquent overhead),
 * scoped to the same visibility rules as the list endpoints (owner/responsible filter
 * for Manager role; global for Admin/Director).
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
 * Visibility rule (mirrors list endpoints):
 *   Admin/Director — see all records (no owner filter)
 *   Manager/Others  — see only records where owner_user_id = user->id (companies)
 *                     or owner_id = user->id (contacts)
 */
class ContactsKpiService
{
    /**
     * KPI counters for the Companies tab.
     *
     * @return array{total: int, clients: int, cat_l: int, cat_m: int, cat_s: int, new_week: int}
     */
    public function forCompanies(User $user): array
    {
        $weekAgo = now()->subDays(7);

        $base = DB::table('crm_companies')->whereNull('deleted_at');
        $base = $this->applyCompanyScope($base, $user);

        return [
            'total' => (int) (clone $base)->count(),
            'clients' => (int) (clone $base)->where('client_status', ClientStatus::Active->value)->count(),
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

        $base = DB::table('crm_contacts')->whereNull('deleted_at');
        $base = $this->applyContactScope($base, $user);

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
     * Apply the same visibility scope as CompanyController::index().
     * Admin / Director — see all. Manager — own records only.
     */
    private function applyCompanyScope(Builder $query, User $user): Builder
    {
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return $query;
        }

        return $query->where(function ($q) use ($user): void {
            $q->where('owner_user_id', $user->id)
                ->orWhere('responsible_user_id', $user->id);
        });
    }

    /**
     * Apply the same visibility scope as ContactController::index().
     * Admin / Director — see all. Manager — own records only.
     */
    private function applyContactScope(Builder $query, User $user): Builder
    {
        if (in_array($user->role, [Role::Admin, Role::Director], true)) {
            return $query;
        }

        return $query->where('owner_id', $user->id);
    }
}
