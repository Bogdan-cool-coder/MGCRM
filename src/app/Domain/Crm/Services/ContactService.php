<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Iam\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ContactService — all Contact business logic lives here.
 * Controller is thin: parse FormRequest → call one method → return Resource.
 */
class ContactService
{
    /**
     * Paginated list of contacts with eager-loaded relations.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = Contact::query()
            ->with(['owner', 'companyLinks.company'])
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('full_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when(isset($filters['status']), function (Builder $q) use ($filters): void {
                $q->where('status', $filters['status']);
            })
            ->when(isset($filters['source']), function (Builder $q) use ($filters): void {
                $q->where('source', $filters['source']);
            })
            ->when(isset($filters['owner_id']), function (Builder $q) use ($filters): void {
                $q->where('owner_id', $filters['owner_id']);
            })
            ->when(isset($filters['company_id']), function (Builder $q) use ($filters): void {
                $q->whereHas('companyLinks', function (Builder $inner) use ($filters): void {
                    $inner->where('company_id', $filters['company_id']);
                });
            })
            ->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Contact
    {
        $data['owner_id'] ??= $creator->id;

        return Contact::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data): Contact
    {
        $contact->update($data);
        $contact->refresh();

        return $contact;
    }

    public function delete(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $contact->delete();
        });
    }

    /**
     * Link a contact to a company (creates or updates the pivot link).
     * Ensures only one primary link per contact.
     *
     * @param  array<string, mixed>  $linkData
     */
    public function linkCompany(Contact $contact, int $companyId, array $linkData): ContactCompanyLink
    {
        return DB::transaction(function () use ($contact, $companyId, $linkData): ContactCompanyLink {
            if (! empty($linkData['is_primary'])) {
                // Clear primary on the contact axis: one primary company per contact.
                ContactCompanyLink::where('contact_id', $contact->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                // Clear primary on the company axis: one primary contact per company.
                ContactCompanyLink::where('company_id', $companyId)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return ContactCompanyLink::updateOrCreate(
                ['contact_id' => $contact->id, 'company_id' => $companyId],
                $linkData + ['contact_id' => $contact->id, 'company_id' => $companyId],
            );
        });
    }

    /**
     * Unlink a contact from a company.
     */
    public function unlinkCompany(Contact $contact, int $companyId): void
    {
        ContactCompanyLink::where('contact_id', $contact->id)
            ->where('company_id', $companyId)
            ->delete();
    }

    /**
     * Reassign primary company for a contact.
     * Un-primaries all other links, sets the target link as primary.
     */
    public function reassignPrimary(Contact $contact, int $companyId): ContactCompanyLink
    {
        return DB::transaction(function () use ($contact, $companyId): ContactCompanyLink {
            // Clear primary on contact axis (all companies for this contact)
            ContactCompanyLink::where('contact_id', $contact->id)
                ->update(['is_primary' => false]);

            // Clear primary on company axis (all contacts for this company)
            ContactCompanyLink::where('company_id', $companyId)
                ->update(['is_primary' => false]);

            $link = ContactCompanyLink::where('contact_id', $contact->id)
                ->where('company_id', $companyId)
                ->firstOrFail();

            $link->update(['is_primary' => true]);

            return $link->fresh();
        });
    }
}
