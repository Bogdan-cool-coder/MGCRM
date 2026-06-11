<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Crm\Services\CustomFieldService;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CustomFieldServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomFieldService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CustomFieldService;
    }

    private function makeDef(string $scope, string $code, string $type = 'text'): CustomFieldDef
    {
        return CustomFieldDef::create([
            'entity_scope' => $scope,
            'code' => $code,
            'label' => ucfirst($code),
            'field_type' => $type,
            'is_active' => true,
        ]);
    }

    public function test_write_field_stores_in_extra_fields(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['owner_user_id' => $user->id, 'extra_fields' => []]);
        $this->makeDef('company', 'crm_notes');

        $this->service->writeField($company, 'crm_notes', 'test value');

        $company->refresh();
        $this->assertSame('test value', $company->extra_fields['crm_notes']);
    }

    public function test_write_field_coerces_number(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['owner_user_id' => $user->id, 'extra_fields' => []]);
        $this->makeDef('company', 'score', 'number');

        $this->service->writeField($company, 'score', '42.5');

        $company->refresh();
        $this->assertSame(42.5, $company->extra_fields['score']);
    }

    public function test_write_field_coerces_boolean(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['owner_user_id' => $user->id, 'extra_fields' => []]);
        $this->makeDef('company', 'verified', 'boolean');

        $this->service->writeField($company, 'verified', 1);

        $company->refresh();
        $this->assertTrue($company->extra_fields['verified']);
    }

    public function test_write_field_throws_for_unknown_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $user = User::factory()->create();
        $company = Company::factory()->create(['owner_user_id' => $user->id]);

        $this->service->writeField($company, 'nonexistent_field', 'val');
    }

    public function test_read_fields_returns_defs_with_values(): void
    {
        $user = User::factory()->create();
        $this->makeDef('company', 'industry_sub', 'text');
        $company = Company::factory()->create([
            'owner_user_id' => $user->id,
            'extra_fields' => ['industry_sub' => 'Technology'],
        ]);

        $fields = $this->service->readFields($company);

        $this->assertCount(1, $fields);
        $this->assertSame('industry_sub', $fields[0]['code']);
        $this->assertSame('Technology', $fields[0]['value']);
    }

    public function test_scope_mismatch_throws_for_unsupported_entity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $user = User::factory()->create();

        // User is not a valid custom-field entity
        $this->service->writeField($user, 'some_field', 'val');
    }

    public function test_defs_for_scope_returns_only_active(): void
    {
        $this->makeDef('contact', 'active_field');
        CustomFieldDef::create([
            'entity_scope' => 'contact',
            'code' => 'inactive_field',
            'label' => 'Inactive',
            'field_type' => 'text',
            'is_active' => false,
        ]);

        $defs = $this->service->defsForScope(CustomFieldScope::Contact);

        $this->assertCount(1, $defs);
        $this->assertSame('active_field', $defs->first()->code);
    }
}
