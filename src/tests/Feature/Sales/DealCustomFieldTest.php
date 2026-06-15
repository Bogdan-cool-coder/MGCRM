<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealCustomFieldTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_custom_field_defs_returned_for_deal_scope(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'extra_fields' => ['budget' => 50000],
        ]);

        CustomFieldDef::create([
            'entity_scope' => 'deal',
            'code' => 'budget',
            'label' => 'Бюджет',
            'field_type' => 'number',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        CustomFieldDef::create([
            'entity_scope' => 'deal',
            'code' => 'source_note',
            'label' => 'Источник',
            'field_type' => 'text',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/deals/{$deal->id}/custom-fields")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'budget')
            ->assertJsonPath('data.0.value', 50000)
            ->assertJsonPath('data.1.code', 'source_note')
            ->assertJsonPath('data.1.value', null);
    }

    public function test_update_deal_writes_extra_fields_via_service(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'extra_fields' => ['budget' => 1000],
        ]);

        CustomFieldDef::create([
            'entity_scope' => 'deal',
            'code' => 'budget',
            'label' => 'Бюджет',
            'field_type' => 'number',
            'is_active' => true,
        ]);
        CustomFieldDef::create([
            'entity_scope' => 'deal',
            'code' => 'source_note',
            'label' => 'Источник',
            'field_type' => 'text',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Writing source_note must MERGE, not replace — budget stays put.
        $this->patchJson("/api/deals/{$deal->id}", [
            'extra_fields' => ['source_note' => 'tg-channel'],
        ])->assertOk();

        $deal->refresh();
        // budget is untouched by this write (merge, not replace) → stays as seeded.
        $this->assertSame(1000, $deal->extra_fields['budget']);
        $this->assertSame('tg-channel', $deal->extra_fields['source_note']);
    }

    public function test_unknown_custom_field_code_rejected(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", [
            'extra_fields' => ['no_such_code' => 'x'],
        ])->assertStatus(422)->assertJsonValidationErrorFor('extra_fields');
    }
}
