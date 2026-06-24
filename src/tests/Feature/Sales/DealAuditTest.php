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

    public function test_business_fields_audited_on_update(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'discount_percent' => 0,
            'signed_at' => null,
        ]);

        Sanctum::actingAs($user, ['*']);

        // discount_percent (drives the derived amount) and a fact date — both were
        // previously OUTSIDE the audit whitelist (M4) and produced no feed event.
        $this->patchJson("/api/deals/{$deal->id}", [
            'discount_percent' => 20,
            'signed_at' => '2026-01-01',
        ])->assertOk();

        $this->assertDatabaseHas('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'discount_percent',
            'old_value' => '0',
            'new_value' => '20',
        ]);
        $this->assertDatabaseHas('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'signed_at',
            'old_value' => null,
            'new_value' => '2026-01-01',
        ]);

        // The widened audit must surface in the field_change feed.
        $feed = $this->getJson("/api/deals/{$deal->id}/feed?types[]=field_change")->assertOk();
        $fields = collect($feed->json('data'))->pluck('payload.field');
        $this->assertTrue($fields->contains('discount_percent'));
        $this->assertTrue($fields->contains('signed_at'));
    }

    public function test_date_field_unchanged_when_iso_matches_stored_day_is_not_audited(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'expected_sign_date' => '2026-03-01',
        ]);

        Sanctum::actingAs($user, ['*']);

        // Same day expressed as an ISO datetime — the cast boundary must NOT log a
        // phantom change (Carbon old vs string new normalised to Y-m-d).
        $this->patchJson("/api/deals/{$deal->id}", [
            'expected_sign_date' => '2026-03-01T00:00:00.000Z',
        ])->assertOk();

        $this->assertDatabaseMissing('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'expected_sign_date',
        ]);
    }

    public function test_boolean_flag_audited_when_toggled(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'amount_locked' => false,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['amount_locked' => true])->assertOk();

        $this->assertDatabaseHas('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'amount_locked',
        ]);
    }

    public function test_amount_is_not_an_audited_field(): void
    {
        // amount is derived (never accepted via PATCH); the dead whitelist key was
        // removed (M4). A title-only change must produce exactly one audit row.
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'title' => 'A',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['title' => 'B'])->assertOk();

        $this->assertDatabaseMissing('deal_audits', [
            'deal_id' => $deal->id,
            'field' => 'amount',
        ]);
        $this->assertSame(1, DealAudit::query()->where('deal_id', $deal->id)->count());
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
