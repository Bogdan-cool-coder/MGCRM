<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * BulkCompanyService — mass operations on a set of companies.
 * Pattern: 1-in-1 with BulkDealService (all-or-nothing, per-entity authorize).
 *
 * Operations: assign_responsible, set_tags, add_tag, remove_tag (+ delete handled separately).
 */
class BulkCompanyService
{
    public function __construct(
        private readonly CompanyService $companyService,
    ) {}

    /**
     * Authorise and load every requested company under the given ability.
     * 403 if any company is inaccessible (all-or-nothing).
     *
     * @param  list<int>  $companyIds
     * @return Collection<int, Company>
     */
    private function authorizeCompanies(array $companyIds, User $actor, string $ability): Collection
    {
        $companies = Company::query()->whereIn('id', array_values(array_unique($companyIds)))->get();

        if ($companies->count() !== count(array_unique($companyIds))) {
            throw new AccessDeniedHttpException('One or more companies are not accessible.');
        }

        foreach ($companies as $company) {
            if (! Gate::forUser($actor)->allows($ability, $company)) {
                throw new AccessDeniedHttpException('One or more companies are not accessible.');
            }
        }

        return $companies;
    }

    /**
     * Apply a bulk PATCH operation. Returns the count of processed companies.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $companyIds, string $operation, array $payload, User $actor): int
    {
        $companies = $this->authorizeCompanies($companyIds, $actor, 'update');

        DB::transaction(function () use ($companies, $operation, $payload, $actor): void {
            foreach ($companies as $company) {
                match ($operation) {
                    'assign_responsible' => $this->assignResponsible($company, (int) $payload['responsible_user_id'], $actor),
                    'set_tags' => $this->setTags($company, (array) $payload['tags'], $actor),
                    'add_tag' => $this->addTag($company, (string) $payload['tag'], $actor),
                    'remove_tag' => $this->removeTag($company, (string) $payload['tag'], $actor),
                };
            }
        });

        return $companies->count();
    }

    /**
     * Bulk soft-delete (all-or-nothing). Returns count deleted.
     *
     * @param  list<int>  $companyIds
     */
    public function delete(array $companyIds, User $actor): int
    {
        $companies = $this->authorizeCompanies($companyIds, $actor, 'delete');

        foreach ($companies as $company) {
            $this->companyService->delete($company);
        }

        return $companies->count();
    }

    // ---- Per-operation handlers ----

    private function assignResponsible(Company $company, int $userId, User $actor): void
    {
        $this->companyService->update($company, ['responsible_user_id' => $userId]);
    }

    private function setTags(Company $company, array $tags, User $actor): void
    {
        $this->companyService->update($company, ['tags' => array_values(array_unique($tags))]);
    }

    private function addTag(Company $company, string $tag, User $actor): void
    {
        $tags = $company->tags ?? [];
        $tags[] = $tag;
        $this->companyService->update($company, ['tags' => array_values(array_unique($tags))]);
    }

    private function removeTag(Company $company, string $tag, User $actor): void
    {
        $tags = array_values(array_filter($company->tags ?? [], static fn (string $t): bool => $t !== $tag));
        $this->companyService->update($company, ['tags' => $tags]);
    }
}
