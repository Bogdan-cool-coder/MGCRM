<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CompanyRequisiteService — all requisites business logic.
 *
 * Invariant: exactly one CompanyRequisite per company has is_current=true.
 *
 * Denorm strategy: when setCurrent() is called, the new current requisite's
 * fields are mirrored back onto crm_companies so that existing list/search/
 * dedup queries (which read Company.tax_id, Company.legal_name, etc.) keep
 * working without modification.
 *
 * The mirror covers the columns that are both in company_requisites AND in
 * crm_companies (the requisite-like subset). Company.name is intentionally
 * NOT overwritten — that is the trade/display name, not a legal requisite.
 */
class CompanyRequisiteService
{
    /**
     * Fields mirrored from CompanyRequisite → crm_companies on setCurrent().
     * Key = requisite column, value = companies column (usually the same).
     *
     * @var array<string, string>
     */
    private const MIRROR_MAP = [
        'legal_name' => 'legal_name',
        'full_legal_form' => 'full_legal_form',
        'legal_form' => 'legal_form',
        'gender_ending_oe' => 'gender_ending_oe',
        'director_position' => 'director_position',
        'director_genitive' => 'director_genitive',
        'director_short' => 'director_short',
        'acts_basis' => 'acts_basis',
        'tax_id_label' => 'tax_id_label',
        'tax_id' => 'tax_id',
        'country_code' => 'country_code',
        'address' => 'address',
    ];

    // ---- Queries ----

    /**
     * All requisite sets for a company, newest first.
     *
     * @return Collection<int, CompanyRequisite>
     */
    public function list(Company $company): Collection
    {
        return $company->requisites()->orderByDesc('created_at')->get();
    }

    /**
     * The current requisite set or null (should not be null if invariant holds).
     */
    public function current(Company $company): ?CompanyRequisite
    {
        return $company->requisites()->where('is_current', true)->first();
    }

    // ---- Mutations ----

    /**
     * Create a new requisite set.
     *
     * If this is the first (and only) requisite for the company it is
     * automatically promoted to current and the denorm mirror is applied.
     * Otherwise `is_current` starts as false; the caller must call
     * setCurrent() separately to switch.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): CompanyRequisite
    {
        $data['company_id'] = $company->id;

        $isFirst = ! CompanyRequisite::query()
            ->where('company_id', $company->id)
            ->exists();

        $data['is_current'] = $isFirst;

        $requisite = CompanyRequisite::create($data);

        if ($isFirst) {
            $this->mirrorToCompany($requisite);
        }

        return $requisite;
    }

    /**
     * Update fields on an existing requisite.
     * If the requisite is current, mirror changes back to the Company row.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CompanyRequisite $requisite, array $data): CompanyRequisite
    {
        // is_current cannot be changed via update — use setCurrent() / unsetCurrent()
        unset($data['is_current'], $data['company_id']);

        $requisite->fill($data)->save();

        if ($requisite->is_current) {
            $this->mirrorToCompany($requisite);
        }

        return $requisite->fresh();
    }

    /**
     * Make $requisite the current set for its company (atomic).
     *
     * Steps (in a transaction):
     *  1. Unset is_current on ALL other requisites for this company.
     *  2. Set is_current=true on the target.
     *  3. Mirror target fields → crm_companies.
     *
     * The transaction guarantees the invariant even on SQLite (no partial index).
     */
    public function setCurrent(CompanyRequisite $requisite): CompanyRequisite
    {
        DB::transaction(function () use ($requisite): void {
            // Step 1: clear is_current on all siblings
            CompanyRequisite::query()
                ->where('company_id', $requisite->company_id)
                ->where('id', '!=', $requisite->id)
                ->update(['is_current' => false]);

            // Step 2: mark target
            $requisite->is_current = true;
            $requisite->valid_to = null; // re-opened if it was superseded
            $requisite->save();

            // Step 3: mirror to Company
            $this->mirrorToCompany($requisite);
        });

        return $requisite->fresh();
    }

    /**
     * Delete a requisite set.
     *
     * Guards:
     *  - Cannot delete the current set if it is the ONLY set for the company.
     *  - Cannot delete if any documents are pinned to this requisite.
     *
     * @throws ValidationException
     */
    public function delete(CompanyRequisite $requisite): void
    {
        if ($requisite->is_current) {
            $count = CompanyRequisite::query()
                ->where('company_id', $requisite->company_id)
                ->count();

            if ($count <= 1) {
                throw ValidationException::withMessages([
                    'requisite' => 'Нельзя удалить единственный набор реквизитов компании.',
                ]);
            }
        }

        $hasDocs = DB::table('documents')
            ->where('company_requisite_id', $requisite->id)
            ->exists();

        if ($hasDocs) {
            throw ValidationException::withMessages([
                'requisite' => 'Нельзя удалить реквизиты: к ним привязаны документы.',
            ]);
        }

        $requisite->delete();
    }

    // ---- Resolver for document / deal creation ----

    /**
     * Resolve the requisite set to use for a new document or deal.
     *
     * Returns:
     *   ['requisite' => CompanyRequisite, 'needs_selection' => false]
     *     — when only one set exists (auto-selected)
     *   ['requisites' => Collection, 'needs_selection' => true]
     *     — when multiple sets exist; caller must let the user choose
     *
     * @return array{requisite?: CompanyRequisite, requisites?: Collection<int, CompanyRequisite>, needs_selection: bool}
     */
    public function resolveForNewDocument(Company $company): array
    {
        $all = $company->requisites()->orderByDesc('is_current')->orderByDesc('created_at')->get();

        if ($all->count() === 1) {
            return [
                'requisite' => $all->first(),
                'needs_selection' => false,
            ];
        }

        if ($all->count() === 0) {
            // Should not happen if data migration ran; return empty needs_selection
            return [
                'requisites' => $all,
                'needs_selection' => false,
            ];
        }

        return [
            'requisites' => $all,
            'needs_selection' => true,
        ];
    }

    // ---- Private ----

    /**
     * Write the current requisite's fields back to the Company row (denorm).
     * Only the columns listed in MIRROR_MAP are touched; bank_details are
     * expanded back to the flat legacy columns if present.
     */
    private function mirrorToCompany(CompanyRequisite $requisite): void
    {
        $mirror = [];

        foreach (self::MIRROR_MAP as $requisiteCol => $companyCol) {
            $mirror[$companyCol] = $requisite->getAttribute($requisiteCol);
        }

        // Expand bank_details JSON → flat legacy columns
        $bankDetails = is_array($requisite->bank_details) ? $requisite->bank_details : [];
        $mirror['bank'] = $bankDetails['bank'] ?? null;
        $mirror['bank_code_label'] = $bankDetails['bank_code_label'] ?? null;
        $mirror['bank_code'] = $bankDetails['bank_code'] ?? null;
        $mirror['account'] = $bankDetails['account'] ?? null;

        DB::table('crm_companies')
            ->where('id', $requisite->company_id)
            ->update($mirror);
    }
}
