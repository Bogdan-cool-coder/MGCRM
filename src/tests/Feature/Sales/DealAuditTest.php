<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealAuditTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_audit_row_created_on_deal_update(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Original',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['title' => 'Renamed'])->assertOk();

        $this->assertDatabaseHas('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'title',
            'old_value' => 'Original',
            'new_value' => 'Renamed',
            'user_id' => $user->id,
        ]);
    }

    public function test_audit_records_old_and_new_values(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'Before',
            'tags' => ['a'],
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", [
            'title' => 'After',
            'tags' => ['a', 'b'],
        ])->assertOk();

        $audits = DealAudit::query()->where('deal_id', $deal->id)->get();

        // Exactly two rows: title + tags. Unchanged fields are never logged.
        $this->assertCount(2, $audits);

        $title = $audits->firstWhere('field', 'title');
        $this->assertSame('Before', $title->old_value);
        $this->assertSame('After', $title->new_value);

        $tags = $audits->firstWhere('field', 'tags');
        $this->assertSame('["a"]', $tags->old_value);
        $this->assertSame('["a","b"]', $tags->new_value);
    }

    public function test_extra_fields_change_audited_per_key(): void
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

        // Change budget AND add source_note → two per-key audit rows.
        $this->patchJson("/api/deals/{$deal->id}", [
            'extra_fields' => ['budget' => 2000, 'source_note' => 'tg'],
        ])->assertOk();

        $this->assertDatabaseHas('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'extra_fields.budget',
            'old_value' => '1000',
            'new_value' => '2000',
        ]);
        $this->assertDatabaseHas('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'extra_fields.source_note',
            'old_value' => null,
            'new_value' => 'tg',
        ]);

        // No collapsed "extra_fields" row — only per-key granularity.
        $this->assertDatabaseMissing('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'extra_fields',
        ]);
    }

    public function test_stage_change_not_duplicated_in_audit(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        Sanctum::actingAs($user, ['*']);

        // stage_id is prohibited in PATCH; the request fails validation and no
        // audit row is written. Stage changes are logged in DealStageHistory only.
        $this->patchJson("/api/deals/{$deal->id}", [
            'title' => 'Renamed',
            'stage_id' => $this->stageCode($pipeline, 'won'),
        ])->assertStatus(422);

        $this->assertDatabaseMissing('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'stage_id',
        ]);
    }
}
