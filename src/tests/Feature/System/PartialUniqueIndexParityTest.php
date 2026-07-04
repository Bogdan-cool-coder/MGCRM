<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Contracts\Models\LicensorBankAccount;
use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit §3.6 MED — four migrations guarded their partial-unique indexes behind a
 * pg-only branch, even though SQLite supports partial indexes too. That let the
 * :memory: test DB enforce LESS than prod (a logical-duplicate insert that fails
 * on Postgres silently succeeded under tests). The guards are removed, so the same
 * partial-unique invariants now hold on the test driver. Each test forces a raw
 * duplicate insert and asserts the DB rejects it — this only passes if the partial
 * index actually exists on SQLite.
 */
class PartialUniqueIndexParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_product_prices_rejects_duplicate_base_price(): void
    {
        // Two base prices (plan_id NULL, no window) for the same product+currency.
        $product = Product::factory()->create();

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'valid_from' => null,
            'valid_to' => null,
        ]);

        $this->expectException(QueryException::class);

        DB::table('catalog_product_prices')->insert([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'amount' => 999_00,
            'valid_from' => null,
            'valid_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_company_requisites_rejects_two_current_sets(): void
    {
        $company = Company::factory()->create();

        CompanyRequisite::factory()->create([
            'company_id' => $company->id,
            'is_current' => true,
        ]);

        $this->expectException(QueryException::class);

        DB::table('company_requisites')->insert([
            'company_id' => $company->id,
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_licensor_bank_accounts_rejects_two_primary_per_currency(): void
    {
        $licensor = LicensorEntity::factory()->create();

        LicensorBankAccount::factory()->create([
            'licensor_id' => $licensor->id,
            'currency' => 'KZT',
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        DB::table('licensor_bank_accounts')->insert([
            'licensor_id' => $licensor->id,
            'currency' => 'KZT',
            'bank' => 'Second Bank',
            'bank_code_label' => 'БИК',
            'bank_code' => 'SECOND01',
            'account' => 'KZ00000000000000000002',
            'is_primary' => true,
        ]);
    }

    public function test_quiz_attempts_rejects_two_open_attempts_per_user(): void
    {
        $quiz = Quiz::factory()->create();
        $user = User::factory()->create();

        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'finished_at' => null,
        ]);

        $this->expectException(QueryException::class);

        DB::table('quiz_attempts')->insert([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'attempt_number' => 2,
            'answers' => '[]',
            'started_at' => now(),
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Negative control: the base-price index is PARTIAL — two rows that differ only
     * by a valid_from window are OUTSIDE the predicate and MUST coexist. If the guard
     * had been replaced with an unconditional unique this would wrongly throw.
     */
    public function test_catalog_product_prices_allows_time_bounded_variant(): void
    {
        $product = Product::factory()->create();

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'valid_from' => null,
            'valid_to' => null,
        ]);

        // A time-bounded row (valid_from set) is outside the WHERE predicate.
        $priced = ProductPrice::factory()->create([
            'product_id' => $product->id,
            'plan_id' => null,
            'currency_code' => 'RUB',
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
        ]);

        $this->assertModelExists($priced);
    }
}
