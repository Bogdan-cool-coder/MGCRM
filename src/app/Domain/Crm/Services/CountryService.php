<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Country;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CountryService
{
    /**
     * Return all countries for the admin settings page.
     * When $activeOnly = true, only is_active=true rows are returned (for select dropdowns).
     *
     * @return Collection<int, Country>
     */
    public function list(bool $activeOnly = false): Collection
    {
        return Country::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a new country directory entry.
     */
    public function create(array $data): Country
    {
        return Country::create($data);
    }

    /**
     * Update an existing country entry.
     * 'code' is never in $data (immutable — see UpdateCountryRequest).
     */
    public function update(Country $country, array $data): Country
    {
        $country->update($data);

        return $country->fresh();
    }

    /**
     * Delete a country, but guard against deletion when the code is still
     * referenced by companies, company requisites, or cities.
     *
     * Returns true on success. Throws \RuntimeException with an i18n-friendly
     * message when still referenced (caller converts to 422 response).
     *
     * We do NOT check crm_documents because country_code there is a document
     * attribute written at the time of signing and should not block directory
     * cleanup. Companies and cities are the live-reference concern.
     *
     * @throws \RuntimeException
     */
    public function delete(Country $country): true
    {
        $code = $country->code;

        $companyCount = DB::table('crm_companies')
            ->where('country_code', $code)
            ->whereNull('deleted_at')
            ->count();

        if ($companyCount > 0) {
            throw new \RuntimeException(
                "Cannot delete country '{$code}': {$companyCount} active company record(s) reference it. "
                .'Set is_active=false to hide it from new entries instead.'
            );
        }

        $requisiteCount = DB::table('company_requisites')
            ->where('country_code', $code)
            ->count();

        if ($requisiteCount > 0) {
            throw new \RuntimeException(
                "Cannot delete country '{$code}': {$requisiteCount} company requisite record(s) reference it."
            );
        }

        $cityCount = DB::table('crm_cities')
            ->where('country_code', $code)
            ->count();

        if ($cityCount > 0) {
            throw new \RuntimeException(
                "Cannot delete country '{$code}': {$cityCount} city record(s) are linked to it. "
                .'Delete or reassign the cities first.'
            );
        }

        $country->delete();

        return true;
    }
}
