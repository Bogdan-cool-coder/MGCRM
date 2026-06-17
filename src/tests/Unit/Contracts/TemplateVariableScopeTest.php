<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Models\TemplateVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateVariableScopeTest extends TestCase
{
    use RefreshDatabase;

    // ---- wildcard: empty product_codes ----

    public function test_wildcard_empty_product_codes_matches_any(): void
    {
        TemplateVariable::factory()->create([
            'product_codes' => [],
            'country_codes' => [],
            'is_active' => true,
        ]);

        $result = TemplateVariable::query()
            ->active()
            ->forContext('macrocrm', 'kz')
            ->get();

        $this->assertCount(1, $result);
    }

    public function test_specific_product_codes_matches_exact_only(): void
    {
        TemplateVariable::factory()->create([
            'product_codes' => ['macrosales'],
            'country_codes' => [],
            'is_active' => true,
        ]);

        $forCrm = TemplateVariable::query()->active()->forContext('macrocrm', 'kz')->get();
        $forSales = TemplateVariable::query()->active()->forContext('macrosales', 'kz')->get();

        $this->assertCount(0, $forCrm);
        $this->assertCount(1, $forSales);
    }

    // ---- wildcard: empty country_codes ----

    public function test_wildcard_country_codes_matches_any(): void
    {
        TemplateVariable::factory()->create([
            'product_codes' => ['macrocrm'],
            'country_codes' => [],
            'is_active' => true,
        ]);

        $forKz = TemplateVariable::query()->active()->forContext('macrocrm', 'kz')->get();
        $forUz = TemplateVariable::query()->active()->forContext('macrocrm', 'uz')->get();

        $this->assertCount(1, $forKz);
        $this->assertCount(1, $forUz);
    }

    // ---- combined filter ----

    public function test_combined_product_and_country_filter(): void
    {
        // Wildcard.
        TemplateVariable::factory()->create([
            'product_codes' => [],
            'country_codes' => [],
            'is_active' => true,
        ]);
        // Specific product+country.
        TemplateVariable::factory()->create([
            'product_codes' => ['macroerp'],
            'country_codes' => ['uz'],
            'is_active' => true,
        ]);
        // Different product.
        TemplateVariable::factory()->create([
            'product_codes' => ['macrocrm'],
            'country_codes' => ['kz'],
            'is_active' => true,
        ]);

        $result = TemplateVariable::query()->active()->forContext('macroerp', 'uz')->get();

        // Wildcard + specific macroerp/uz → 2 results.
        $this->assertCount(2, $result);
    }

    public function test_inactive_variables_excluded(): void
    {
        TemplateVariable::factory()->create([
            'product_codes' => [],
            'country_codes' => [],
            'is_active' => false,
        ]);

        $result = TemplateVariable::query()->active()->forContext('macrocrm', 'kz')->get();

        $this->assertCount(0, $result);
    }
}
