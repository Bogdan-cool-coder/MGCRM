<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Migration\Models\AmoProductMapping;
use App\Domain\Migration\Models\ExternalRef;
use App\Domain\Migration\Models\MigrationMap;
use App\Domain\Sales\Models\Deal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AMO -> MGCRM migration infrastructure (slice N7, Phase 0): the three new
 * tables, the additive created_by_id columns + creator() relations, and the
 * is_service users column.
 */
class MigrationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('external_refs'));
        $this->assertTrue(Schema::hasTable('migration_maps'));
        $this->assertTrue(Schema::hasTable('amo_product_mappings'));
    }

    public function test_external_ref_persists_and_casts_payload(): void
    {
        $ref = ExternalRef::create([
            'source' => 'amo',
            'entity_type' => 'deal',
            'entity_id' => 42,
            'external_id' => 'amo-1001',
            'external_payload' => ['name' => 'Test', 'price' => 1000],
            'imported_at' => now(),
        ]);

        $fresh = ExternalRef::findOrFail($ref->id);

        $this->assertSame(42, $fresh->entity_id);
        $this->assertSame(['name' => 'Test', 'price' => 1000], $fresh->external_payload);
        $this->assertNotNull($fresh->imported_at);
    }

    public function test_external_refs_unique_on_source_type_external(): void
    {
        ExternalRef::create([
            'source' => 'amo',
            'entity_type' => 'deal',
            'entity_id' => 1,
            'external_id' => 'dup-1',
        ]);

        $this->expectException(QueryException::class);

        ExternalRef::create([
            'source' => 'amo',
            'entity_type' => 'deal',
            'entity_id' => 2,
            'external_id' => 'dup-1',
        ]);
    }

    public function test_external_refs_allows_same_external_id_for_different_entity_type(): void
    {
        ExternalRef::create([
            'source' => 'amo',
            'entity_type' => 'deal',
            'entity_id' => 1,
            'external_id' => 'shared-1',
        ]);

        // Same source + external_id but different entity_type is allowed.
        $second = ExternalRef::create([
            'source' => 'amo',
            'entity_type' => 'contact',
            'entity_id' => 1,
            'external_id' => 'shared-1',
        ]);

        $this->assertDatabaseCount('external_refs', 2);
        $this->assertNotNull($second->id);
    }

    public function test_migration_maps_unique_on_type_amo_parent(): void
    {
        MigrationMap::create([
            'map_type' => 'cf_option',
            'amo_id' => '500',
            'amo_parent_id' => '100',
            'target_code' => 'opt_a',
        ]);

        $this->expectException(QueryException::class);

        MigrationMap::create([
            'map_type' => 'cf_option',
            'amo_id' => '500',
            'amo_parent_id' => '100',
            'target_code' => 'opt_b',
        ]);
    }

    public function test_migration_map_casts_target_meta(): void
    {
        $map = MigrationMap::create([
            'map_type' => 'cf_field',
            'amo_id' => '900',
            'target_id' => 7,
            'target_meta' => ['kind' => 'select'],
        ]);

        $fresh = MigrationMap::findOrFail($map->id);

        $this->assertSame(7, $fresh->target_id);
        $this->assertSame(['kind' => 'select'], $fresh->target_meta);
    }

    public function test_amo_product_mapping_unique_on_enum_id(): void
    {
        AmoProductMapping::create([
            'amo_enum_id' => 590196,
            'amo_value' => 'Product A',
            'action' => 'skip',
        ]);

        $this->expectException(QueryException::class);

        AmoProductMapping::create([
            'amo_enum_id' => 590196,
            'amo_value' => 'Product A duplicate',
            'action' => 'skip',
        ]);
    }

    public function test_amo_product_mapping_action_defaults_to_skip(): void
    {
        $mapping = AmoProductMapping::create([
            'amo_enum_id' => 111,
            'amo_value' => 'Loose',
        ]);

        $this->assertSame('skip', $mapping->fresh()->action->value);
    }

    public function test_created_by_id_relation_on_deal(): void
    {
        $creator = User::factory()->create();
        $deal = Deal::factory()->create(['created_by_id' => $creator->id]);

        $this->assertTrue($deal->creator->is($creator));
    }

    public function test_created_by_id_relation_on_contact(): void
    {
        $creator = User::factory()->create();
        $contact = Contact::factory()->create(['created_by_id' => $creator->id]);

        $this->assertTrue($contact->creator->is($creator));
    }

    public function test_created_by_id_relation_on_company(): void
    {
        $creator = User::factory()->create();
        $company = Company::factory()->create(['created_by_id' => $creator->id]);

        $this->assertTrue($company->creator->is($creator));
    }

    public function test_created_by_id_is_nullable(): void
    {
        $deal = Deal::factory()->create(['created_by_id' => null]);

        $this->assertNull($deal->creator);
    }

    public function test_users_have_is_service_flag(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_service'));

        $normal = User::factory()->create();
        $service = User::factory()->create(['is_service' => true]);

        $this->assertFalse($normal->is_service);
        $this->assertTrue($service->is_service);
    }
}
