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
 * Counters are computed with a SINGLE conditional-aggregate query per tab, scoped
 * to the same visibility rules as the list endpoints via
 * VisibilityResolver::applyScope() (owner/responsible filter for Manager role;
 * global for Admin/Director).
 *
 * Data-Layer-Audit-2026-07 §3.5: the six/four per-chip ->count() round-trips are
 * folded into one COUNT(*) FILTER-style aggregate (portable COUNT(CASE WHEN …))
 * over a single scan, and the Schema::hasColumn('crm_companies','client_status')
 * catalog probe is memoised for the request lifetime instead of firing on every
 * call. Every counter stays byte-identical to the previous per-chip COUNT.
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
    /**
     * Request-lifetime memo for the client_status column probe (an AMO N5 column,
     * migration 2026_06_27_100001). Schema::hasColumn() is an uncached catalog
     * round-trip on every call; the schema never changes within a request.
     */
    private ?bool $companyHasClientStatus = null;

    public function __construct(private readonly VisibilityResolver $visibility) {}

    /**
     * KPI counters for the Companies tab.
     *
     * @return array{total: int, clients: int, cat_l: int, cat_m: int, cat_s: int, new_week: int}
     */
    public function forCompanies(User $user): array
    {
        $weekAgo = now()->subDays(7);

        $base = $this->applyCompanyScope(Company::query(), $user);

        // client_status is an AMO N5 column; it may not exist on production yet.
        // Guard gracefully (return 0) and memoise the probe for the request.
        $hasClientStatus = $this->companyHasClientStatus();

        $clientsCase = $hasClientStatus
            ? 'count(case when client_status = ? then 1 end)'
            : '0';
        $clientsBindings = $hasClientStatus ? [ClientStatus::Active->value] : [];

        $selects = implode(', ', [
            'count(*) as total',
            $clientsCase.' as clients',
            'count(case when category_code = ? then 1 end) as cat_l',
            'count(case when category_code = ? then 1 end) as cat_m',
            'count(case when category_code in (?, ?) then 1 end) as cat_s',
            'count(case when created_at >= ? then 1 end) as new_week',
        ]);

        $bindings = [
            ...$clientsBindings,
            CategoryCode::L->value,
            CategoryCode::M->value,
            CategoryCode::S1->value,
            CategoryCode::S2->value,
            $weekAgo,
        ];

        /** @var object|null $row */
        $row = (clone $base)->selectRaw($selects, $bindings)->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'clients' => (int) ($row?->clients ?? 0),
            'cat_l' => (int) ($row?->cat_l ?? 0),
            'cat_m' => (int) ($row?->cat_m ?? 0),
            'cat_s' => (int) ($row?->cat_s ?? 0),
            'new_week' => (int) ($row?->new_week ?? 0),
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

        $selects = implode(', ', [
            'count(*) as total',
            'count(case when last_activity_at >= ? then 1 end) as active',
            'count(case when last_activity_at is null or last_activity_at < ? then 1 end) as no_touch_30',
            'count(case when created_at >= ? then 1 end) as new_week',
        ]);

        /** @var object|null $row */
        $row = (clone $base)->selectRaw($selects, [$monthAgo, $monthAgo, $weekAgo])->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'active' => (int) ($row?->active ?? 0),
            'no_touch_30' => (int) ($row?->no_touch_30 ?? 0),
            'new_week' => (int) ($row?->new_week ?? 0),
        ];
    }

    // ---- Private ----

    /** Memoised Schema::hasColumn probe for crm_companies.client_status. */
    private function companyHasClientStatus(): bool
    {
        return $this->companyHasClientStatus ??= Schema::hasColumn('crm_companies', 'client_status');
    }

    /**
     * Apply the EXACT same visibility scope as CompanyService::list() /
     * CompanyExportService — no departmentColumn. Company's list/export/policy
     * never pass one, so they degrade Department scope to Own (M9 team-read
     * widening is intentionally scoped to Deals/Activities only, not Company).
     *
     * Passing departmentColumn: 'department_id' here (as before) activated the
     * live Department branch for the KPI counters ONLY, since Manager resolves
     * to VisibilityScope::Department since M9 — so a manager's KPI chips counted
     * department colleagues' companies that the list/export never show them
     * (KPI-vs-list drift). Keeping this null realigns the three call sites.
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
            departmentColumn: null,
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
