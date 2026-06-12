<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * CompanyService — all Company business logic lives here.
 * Controller is thin: parse FormRequest → call one method → return Resource.
 */
class CompanyService
{
    /**
     * Paginated list of companies with eager-loaded relations.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = Company::query()
            ->with(['companyType', 'responsibleUser', 'ownerUser'])
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('legal_name', 'like', $term)
                        ->orWhere('tax_id', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when(isset($filters['company_type_id']), function (Builder $q) use ($filters): void {
                $q->where('company_type_id', $filters['company_type_id']);
            })
            ->when(isset($filters['source']), function (Builder $q) use ($filters): void {
                $q->where('source', $filters['source']);
            })
            ->when(isset($filters['country_code']), function (Builder $q) use ($filters): void {
                $q->where('country_code', $filters['country_code']);
            })
            ->when(isset($filters['responsible_user_id']), function (Builder $q) use ($filters): void {
                $q->where('responsible_user_id', $filters['responsible_user_id']);
            })
            ->when(isset($filters['category_code']), function (Builder $q) use ($filters): void {
                $q->where('category_code', $filters['category_code']);
            })
            ->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Company
    {
        // Auto-assign owner and department from creator if not provided
        $data['owner_user_id'] ??= $creator->id;
        $data['department_id'] ??= $creator->department_id;

        return Company::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->update($data);
        $company->refresh();

        return $company;
    }

    public function delete(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            // Soft-delete cascades to contact links are handled by DB/application layer
            $company->delete();
        });
    }

    /**
     * Express-company: create a minimal company and immediately link a contact as primary.
     *
     * @param  array<string, mixed>  $companyData
     */
    public function expressCreate(array $companyData, int $contactId, User $creator): Company
    {
        return DB::transaction(function () use ($companyData, $contactId, $creator): Company {
            $company = $this->create($companyData, $creator);

            // Remove any existing primary link for this contact, then add new
            ContactCompanyLink::where('contact_id', $contactId)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            ContactCompanyLink::create([
                'contact_id' => $contactId,
                'company_id' => $company->id,
                'employment_status' => 'works',
                'is_primary' => true,
            ]);

            return $company->load('companyType');
        });
    }

    /**
     * Add an employee (ContactCompanyLink) to a company.
     * If is_primary is true, unsets previous primary link for that contact.
     *
     * @param  array<string, mixed>  $linkData
     */
    public function addEmployee(Company $company, int $contactId, array $linkData): ContactCompanyLink
    {
        return DB::transaction(function () use ($company, $contactId, $linkData): ContactCompanyLink {
            if (! empty($linkData['is_primary'])) {
                ContactCompanyLink::where('contact_id', $contactId)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return ContactCompanyLink::updateOrCreate(
                ['contact_id' => $contactId, 'company_id' => $company->id],
                $linkData + ['contact_id' => $contactId, 'company_id' => $company->id],
            );
        });
    }

    /**
     * Remove an employee link from a company.
     */
    public function removeEmployee(Company $company, int $contactId): void
    {
        ContactCompanyLink::where('company_id', $company->id)
            ->where('contact_id', $contactId)
            ->delete();
    }

    /**
     * Lookup-dedup: find an existing Company by email (priority) or normalized phone.
     *
     * Called by InboundRoutingService (Domain/Inbox) to avoid creating duplicate
     * companies when routing inbound messages.
     *
     * Rules (mirror inbox.py company_dedup_key + find_existing_company_by_contact):
     *   1. Email takes priority — matched case-insensitively via LOWER(TRIM(email)).
     *   2. Phone fallback — both sides normalized to digits-only in PHP to avoid
     *      non-portable REGEXP chains across PostgreSQL and SQLite.
     *   3. Both null/empty → returns null (no dedup key available).
     *   4. On a tie (multiple matches) returns the earliest record (min id) —
     *      deterministic under race conditions.
     *
     * Normalization is done entirely in PHP — bound params only, no DB::raw string
     * literals — portable across PostgreSQL and SQLite (same pattern as DedupService).
     *
     * @param  string|null  $email  Raw email from inbound message / form submission.
     * @param  string|null  $phone  Raw phone from inbound message / form submission.
     */
    public function findForDedup(?string $email, ?string $phone): ?Company
    {
        // --- Email (priority) ---
        $emailNorm = $email !== null ? mb_strtolower(trim($email)) : '';
        if ($emailNorm !== '') {
            return Company::query()
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])
                ->orderBy('id')
                ->first();
        }

        // --- Phone fallback (digits-only normalization in PHP) ---
        $phoneNorm = $phone !== null ? (preg_replace('/[^0-9]/', '', $phone) ?? '') : '';
        if ($phoneNorm === '') {
            return null;
        }

        // Fetch candidates that have a non-null phone, then post-filter in PHP
        // to compare normalized values — avoids non-portable REGEXP_REPLACE chains
        // (PostgreSQL uses regexp_replace, SQLite lacks it).
        $candidates = Company::query()
            ->whereNull('deleted_at')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'phone']);

        $matchId = null;
        foreach ($candidates as $candidate) {
            $normalized = preg_replace('/[^0-9]/', '', (string) $candidate->phone) ?? '';
            if ($normalized === $phoneNorm) {
                $matchId = $candidate->id;
                break; // already ordered by id asc → first match is the earliest
            }
        }

        if ($matchId === null) {
            return null;
        }

        return Company::find($matchId);
    }
}
