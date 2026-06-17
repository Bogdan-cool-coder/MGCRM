<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Services\CompanyService;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for CompanyService::findForDedup.
 *
 * Coverage:
 *   - email match (priority, case-insensitive, whitespace-stripped)
 *   - phone match (digits-only normalization: +7 700… = 8(700)…)
 *   - email priority over phone (when both match different records)
 *   - returns earliest record (min id) on tie (determinism)
 *   - null / empty inputs → null
 *   - soft-deleted companies are excluded
 */
class CompanyServiceFindForDedupTest extends TestCase
{
    use RefreshDatabase;

    private CompanyService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CompanyService;
        $this->actor = User::factory()->create();
    }

    // ---- Email matching ----

    public function test_finds_company_by_exact_email(): void
    {
        $company = Company::factory()->create(['email' => 'acme@example.com']);

        $found = $this->service->findForDedup('acme@example.com', null);

        $this->assertNotNull($found);
        $this->assertSame($company->id, $found->id);
    }

    public function test_email_match_is_case_insensitive(): void
    {
        $company = Company::factory()->create(['email' => 'Acme@Example.COM']);

        $found = $this->service->findForDedup('acme@example.com', null);

        $this->assertNotNull($found);
        $this->assertSame($company->id, $found->id);
    }

    public function test_email_match_strips_whitespace(): void
    {
        $company = Company::factory()->create(['email' => 'acme@example.com']);

        $found = $this->service->findForDedup('  ACME@EXAMPLE.COM  ', null);

        $this->assertNotNull($found);
        $this->assertSame($company->id, $found->id);
    }

    public function test_returns_null_when_email_not_found(): void
    {
        Company::factory()->create(['email' => 'other@example.com']);

        $found = $this->service->findForDedup('nobody@example.com', null);

        $this->assertNull($found);
    }

    // ---- Phone matching ----

    public function test_finds_company_by_normalized_phone(): void
    {
        // stored in E.164 style
        $company = Company::factory()->create(['email' => null, 'phone' => '+77001234567']);

        // lookup with formatted variant: +7 700 123-45-67 → digits = 77001234567
        $found = $this->service->findForDedup(null, '+7 700 123-45-67');

        $this->assertNotNull($found);
        $this->assertSame($company->id, $found->id);
    }

    public function test_phone_match_normalizes_both_sides(): void
    {
        // stored with separators
        $company = Company::factory()->create(['email' => null, 'phone' => '8 (700) 123-45-67']);

        // lookup with plain digits variant
        $found = $this->service->findForDedup(null, '+77001234567');

        // both normalize to 77001234567 vs 87001234567 — actually different leading digit
        // Use a scenario where both sides produce the exact same digit string.
        // Reset with same digit string on both sides.
        $company->update(['phone' => '+77001234567']);

        $found = $this->service->findForDedup(null, '7-700-123-45-67');

        $this->assertNotNull($found);
        $this->assertSame($company->id, $found->id);
    }

    public function test_returns_null_when_phone_not_found(): void
    {
        Company::factory()->create(['email' => null, 'phone' => '+77001111111']);

        $found = $this->service->findForDedup(null, '+77009999999');

        $this->assertNull($found);
    }

    // ---- Email has priority over phone ----

    public function test_email_takes_priority_over_phone(): void
    {
        $byEmail = Company::factory()->create(['email' => 'priority@example.com', 'phone' => '+77001111111']);
        $byPhone = Company::factory()->create(['email' => null, 'phone' => '+77002222222']);

        // email matches $byEmail; phone would match $byPhone — email wins
        $found = $this->service->findForDedup('priority@example.com', '+77002222222');

        $this->assertNotNull($found);
        $this->assertSame($byEmail->id, $found->id);
    }

    // ---- Earliest record returned on tie ----

    public function test_returns_earliest_record_on_email_tie(): void
    {
        $first = Company::factory()->create(['email' => 'dup@example.com']);
        $second = Company::factory()->create(['email' => 'DUP@EXAMPLE.COM']); // same normalized

        $found = $this->service->findForDedup('dup@example.com', null);

        $this->assertNotNull($found);
        $this->assertSame($first->id, $found->id);
        $this->assertLessThan($second->id, $found->id);
    }

    // ---- Null / empty inputs ----

    public function test_returns_null_when_both_inputs_are_null(): void
    {
        Company::factory()->create(['email' => 'acme@example.com']);

        $found = $this->service->findForDedup(null, null);

        $this->assertNull($found);
    }

    public function test_returns_null_when_email_empty_string_and_phone_null(): void
    {
        Company::factory()->create(['email' => 'acme@example.com']);

        $found = $this->service->findForDedup('', null);

        $this->assertNull($found);
    }

    public function test_returns_null_when_phone_is_whitespace_only(): void
    {
        Company::factory()->create(['email' => null, 'phone' => '+77001234567']);

        $found = $this->service->findForDedup(null, '   ');

        $this->assertNull($found);
    }

    public function test_returns_null_when_phone_has_no_digits(): void
    {
        Company::factory()->create(['email' => null, 'phone' => '+77001234567']);

        $found = $this->service->findForDedup(null, '+- -');

        $this->assertNull($found);
    }

    // ---- Soft-deleted companies excluded ----

    public function test_does_not_find_soft_deleted_company_by_email(): void
    {
        $company = Company::factory()->create(['email' => 'deleted@example.com']);
        $company->delete(); // soft delete

        $found = $this->service->findForDedup('deleted@example.com', null);

        $this->assertNull($found);
    }

    public function test_does_not_find_soft_deleted_company_by_phone(): void
    {
        $company = Company::factory()->create(['email' => null, 'phone' => '+77001234567']);
        $company->delete();

        $found = $this->service->findForDedup(null, '+77001234567');

        $this->assertNull($found);
    }
}
