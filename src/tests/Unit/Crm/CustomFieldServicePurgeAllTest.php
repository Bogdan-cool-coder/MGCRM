<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Crm\Services\CustomFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for CustomFieldService::purgeAll() — system-reset `directories`
 * category (docs/contracts/system-reset-api-contract.md §1 row 9).
 */
class CustomFieldServicePurgeAllTest extends TestCase
{
    use RefreshDatabase;

    private CustomFieldService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CustomFieldService::class);
    }

    public function test_purges_all_custom_field_definitions(): void
    {
        CustomFieldDef::create([
            'entity_scope' => 'contact',
            'code' => 'shoe_size',
            'label' => 'Shoe size',
            'field_type' => 'number',
            'options' => [],
            'required' => false,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        CustomFieldDef::create([
            'entity_scope' => 'company',
            'code' => 'niche',
            'label' => 'Niche',
            'field_type' => 'text',
            'options' => [],
            'required' => false,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $counts = $this->service->purgeAll();

        $this->assertSame(['custom_field_defs' => 2], $counts);
        $this->assertSame(0, DB::table('custom_field_defs')->count());
    }

    public function test_does_not_touch_extra_fields_on_target_entities(): void
    {
        CustomFieldDef::create([
            'entity_scope' => 'contact',
            'code' => 'shoe_size',
            'label' => 'Shoe size',
            'field_type' => 'number',
            'options' => [],
            'required' => false,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create(['extra_fields' => ['shoe_size' => 42]]);

        $this->service->purgeAll();

        // Deleting the def is out of scope for touching the JSONB value —
        // extra_fields on the contact itself is untouched by this purge.
        $this->assertSame(['shoe_size' => 42], $contact->fresh()->extra_fields);
    }

    public function test_is_safe_to_call_when_no_defs_exist(): void
    {
        $counts = $this->service->purgeAll();

        $this->assertSame(['custom_field_defs' => 0], $counts);
    }
}
