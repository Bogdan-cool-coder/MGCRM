<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Models\LicensorBankAccount;
use App\Domain\Contracts\Models\LicensorEntity;
use Illuminate\Support\Facades\DB;

/**
 * LicensorService — CRUD + resolution logic for LicensorEntity / LicensorBankAccount.
 *
 * Priority for forCountry():
 *   1. If override_id given → find by ID.
 *   2. Otherwise → find by country_code in DB.
 *   3. If none → null (caller falls back to YAML country layer).
 *
 * Primary account resolution:
 *   is_primary=true for the requested currency, or null if not found.
 */
class LicensorService
{
    /**
     * Resolve a licensor entity for the given country, respecting the override.
     */
    public function forCountry(string $countryCode, ?int $overrideId = null): ?LicensorEntity
    {
        if ($overrideId !== null) {
            return LicensorEntity::find($overrideId);
        }

        return LicensorEntity::query()
            ->forCountry($countryCode)
            ->first();
    }

    /**
     * Find the primary bank account for the given currency.
     * Returns null if none is marked primary for that currency.
     */
    public function primaryAccountForCurrency(LicensorEntity $licensor, string $currency): ?LicensorBankAccount
    {
        return $licensor->bankAccounts()
            ->primaryFor($currency)
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): LicensorEntity
    {
        return LicensorEntity::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LicensorEntity $licensor, array $data): LicensorEntity
    {
        $licensor->update($data);
        $licensor->refresh();

        return $licensor;
    }

    /**
     * Add a bank account to the licensor.
     * Enforces: only one primary account per (licensor, currency).
     *
     * @param  array<string, mixed>  $data
     */
    public function createAccount(LicensorEntity $licensor, array $data): LicensorBankAccount
    {
        return DB::transaction(function () use ($licensor, $data): LicensorBankAccount {
            if (! empty($data['is_primary'])) {
                // Unset any existing primary for this currency.
                $licensor->bankAccounts()
                    ->where('currency', $data['currency'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return $licensor->bankAccounts()->create($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(LicensorBankAccount $account, array $data): LicensorBankAccount
    {
        return DB::transaction(function () use ($account, $data): LicensorBankAccount {
            if (! empty($data['is_primary'])) {
                // Unset any existing primary for this currency (excluding current).
                $account->licensor->bankAccounts()
                    ->where('currency', $data['currency'] ?? $account->currency)
                    ->where('is_primary', true)
                    ->where('id', '!=', $account->id)
                    ->update(['is_primary' => false]);
            }

            $account->update($data);
            $account->refresh();

            return $account;
        });
    }

    public function deleteAccount(LicensorBankAccount $account): void
    {
        $account->delete();
    }
}
