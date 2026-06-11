<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Models\DismissedDuplicate;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * DedupService — duplicate detection and merge logic.
 *
 * Dedup criteria:
 *   - Contacts: normalized phone / email / full_name (lowercase trimmed)
 *   - Companies: normalized phone / email / tax_id / name (lowercase trimmed)
 *
 * Merge is always transactional. Duplicate IDs are soft-deleted after
 * all related links are transferred to the master record.
 */
class DedupService
{
    private const ALLOWED_SCOPES = ['contact', 'company'];

    /**
     * Scan for potential duplicates of a given entity.
     * Returns a collection of candidate models (the possible duplicates).
     * Dismissed pairs are excluded.
     *
     * @return Collection<int, Model>
     */
    public function scan(string $scope, int $entityId): Collection
    {
        $this->assertScope($scope);

        if ($scope === 'contact') {
            return $this->scanContact($entityId);
        }

        return $this->scanCompany($entityId);
    }

    /**
     * Merge one or more duplicate records into a master record.
     * Transfers ContactCompanyLink rows; other relations (deals, tasks, activities)
     * are stubs here — each domain service is responsible for its own FK migration
     * (hooked via observer or called explicitly in S1.3+).
     *
     * @param  int[]  $duplicateIds
     */
    public function merge(string $scope, int $masterId, array $duplicateIds, User $actor): void
    {
        $this->assertScope($scope);

        if (in_array($masterId, $duplicateIds, true)) {
            throw new InvalidArgumentException('Master ID must not appear in duplicate IDs.');
        }

        DB::transaction(function () use ($scope, $masterId, $duplicateIds): void {
            foreach ($duplicateIds as $dupId) {
                if ($scope === 'contact') {
                    $this->mergeContact($masterId, $dupId);
                } else {
                    $this->mergeCompany($masterId, $dupId);
                }

                // Clean up dismissed-pair records involving the merged duplicate
                DismissedDuplicate::where('entity_type', $scope)
                    ->where(function ($q) use ($dupId): void {
                        $q->where('entity_a_id', $dupId)
                            ->orWhere('entity_b_id', $dupId);
                    })->delete();
            }
        });
    }

    /**
     * Mark a pair as "not a duplicate" — scan will not surface them again.
     */
    public function dismiss(string $scope, int $entityAId, int $entityBId, User $actor): void
    {
        $this->assertScope($scope);

        // Normalize: a < b
        [$a, $b] = $entityAId < $entityBId
            ? [$entityAId, $entityBId]
            : [$entityBId, $entityAId];

        DismissedDuplicate::firstOrCreate([
            'entity_type' => $scope,
            'entity_a_id' => $a,
            'entity_b_id' => $b,
        ], [
            'dismissed_by_user_id' => $actor->id,
            'dismissed_at' => now(),
        ]);
    }

    // ---- Private helpers ----

    /** @return Collection<int, Contact> */
    private function scanContact(int $contactId): Collection
    {
        $contact = Contact::findOrFail($contactId);

        // Collect dismissed IDs for this contact
        $dismissed = $this->dismissedIds('contact', $contactId);

        $candidates = Contact::query()
            ->where('id', '!=', $contactId)
            ->whereNotIn('id', $dismissed)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($contact): void {
                // phone match (normalized)
                if ($contact->phone) {
                    $normalized = $this->normalizePhone($contact->phone);
                    $q->orWhere(DB::raw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "(", ""), ")", ""), "+", "")'), '=', ltrim($normalized, '+'));
                }
                // email match (case-insensitive)
                if ($contact->email) {
                    $q->orWhere(DB::raw('LOWER(email)'), '=', mb_strtolower($contact->email));
                }
                // full_name match (normalized lowercase)
                $normalizedName = $this->normalizeName($contact->full_name);
                $q->orWhere(DB::raw('LOWER(TRIM(full_name))'), '=', $normalizedName);
            })
            ->get();

        return $candidates;
    }

    /** @return Collection<int, Company> */
    private function scanCompany(int $companyId): Collection
    {
        $company = Company::findOrFail($companyId);

        $dismissed = $this->dismissedIds('company', $companyId);

        $candidates = Company::query()
            ->where('id', '!=', $companyId)
            ->whereNotIn('id', $dismissed)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($company): void {
                if ($company->phone) {
                    $normalized = ltrim($this->normalizePhone($company->phone), '+');
                    $q->orWhere(DB::raw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "(", ""), ")", ""), "+", "")'), '=', $normalized);
                }
                if ($company->email) {
                    $q->orWhere(DB::raw('LOWER(email)'), '=', mb_strtolower($company->email));
                }
                if ($company->tax_id) {
                    $q->orWhere(DB::raw('TRIM(tax_id)'), '=', trim($company->tax_id));
                }
                // name match
                $normalizedName = $this->normalizeName($company->name);
                $q->orWhere(DB::raw('LOWER(TRIM(name))'), '=', $normalizedName);
            })
            ->get();

        return $candidates;
    }

    /**
     * Transfer Contact's company links to master, then soft-delete duplicate.
     */
    private function mergeContact(int $masterId, int $dupId): void
    {
        // Transfer company links — skip if master already has a link to same company
        $masterLinks = ContactCompanyLink::where('contact_id', $masterId)
            ->pluck('company_id')
            ->all();

        ContactCompanyLink::where('contact_id', $dupId)
            ->whereNotIn('company_id', $masterLinks)
            ->update(['contact_id' => $masterId, 'is_primary' => false]);

        // Delete orphaned links (master already has them)
        ContactCompanyLink::where('contact_id', $dupId)->delete();

        // Soft-delete the duplicate
        Contact::where('id', $dupId)->delete();
    }

    /**
     * Transfer Company's contact links to master, then soft-delete duplicate.
     */
    private function mergeCompany(int $masterId, int $dupId): void
    {
        $masterLinks = ContactCompanyLink::where('company_id', $masterId)
            ->pluck('contact_id')
            ->all();

        ContactCompanyLink::where('company_id', $dupId)
            ->whereNotIn('contact_id', $masterLinks)
            ->update(['company_id' => $masterId, 'is_primary' => false]);

        ContactCompanyLink::where('company_id', $dupId)->delete();

        Company::where('id', $dupId)->delete();
    }

    /**
     * Returns IDs of entities that have been dismissed as "not duplicates" of the given entity.
     *
     * @return int[]
     */
    private function dismissedIds(string $scope, int $entityId): array
    {
        $pairs = DismissedDuplicate::where('entity_type', $scope)
            ->where(function ($q) use ($entityId): void {
                $q->where('entity_a_id', $entityId)
                    ->orWhere('entity_b_id', $entityId);
            })
            ->get(['entity_a_id', 'entity_b_id']);

        return $pairs->flatMap(function ($row) use ($entityId): array {
            return [$row->entity_a_id === $entityId ? $row->entity_b_id : $row->entity_a_id];
        })->all();
    }

    /** Normalize phone to digits-only string for comparison. */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /** Lowercase + trim for name comparison. */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function assertScope(string $scope): void
    {
        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException(
                "Invalid dedup scope '{$scope}'. Allowed: ".implode(', ', self::ALLOWED_SCOPES)
            );
        }
    }
}
