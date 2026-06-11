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
}
