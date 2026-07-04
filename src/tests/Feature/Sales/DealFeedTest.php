<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealAudit;
use App\Domain\Sales\Models\DealStageHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealFeedTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    private function makeDeal(User $owner): Deal
    {
        $pipeline = $this->seedSalesPipeline();

        return Deal::factory()->forOwner($owner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
    }

    private function seedThreeSources(Deal $deal): void
    {
        // A REAL transition (from_stage_id non-null): the feed surfaces genuine
        // stage moves but excludes the genesis row (from_stage_id = null) so the
        // deal's creation isn't rendered twice (#4).
        DealStageHistory::query()->create([
            'deal_id' => $deal->id,
            'from_stage_id' => $deal->stage_id,
            'to_stage_id' => $deal->stage_id,
            'user_id' => null,
            'created_at' => now()->subDays(2),
        ]);

        Activity::factory()->forDeal($deal)->create([
            'title' => 'Discovery call',
            'created_at' => now()->subDay(),
        ]);

        DealAudit::query()->insert([
            'deal_id' => $deal->id,
            'user_id' => null,
            'field' => 'title',
            'old_value' => 'Old',
            'new_value' => 'New',
            'created_at' => now(),
        ]);
    }

    public function test_feed_returns_merged_events_in_chronological_order(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($user);
        $this->seedThreeSources($deal);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/deals/{$deal->id}/feed")->assertOk();

        $response->assertJsonPath('meta.total', 3);

        $types = collect($response->json('data'))->pluck('type')->all();
        // Newest first: field_change (now) → activity (-1d) → stage_change (-2d).
        $this->assertSame(['field_change', 'activity', 'stage_change'], $types);
    }

    public function test_feed_filter_by_type(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($user);
        $this->seedThreeSources($deal);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/deals/{$deal->id}/feed?types[]=activity")->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.type', 'activity');
    }

    public function test_feed_paginated(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($user);

        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'deal_id' => $deal->id,
                'user_id' => null,
                'field' => 'title',
                'old_value' => 'A',
                'new_value' => 'B',
                'created_at' => now()->subMinutes($i),
            ];
        }
        DealAudit::query()->insert($rows);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson("/api/deals/{$deal->id}/feed?page=1&per_page=2")->assertOk();

        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonCount(2, 'data');
    }

    public function test_feed_requires_deal_view_permission(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $other = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($owner);
        $this->seedThreeSources($deal);

        Sanctum::actingAs($other, ['*']);

        $this->getJson("/api/deals/{$deal->id}/feed")->assertForbidden();
    }

    // ---- #4: a freshly created deal does not duplicate its creation ----

    public function test_newly_created_deal_feed_excludes_genesis_stage_row(): void
    {
        // create() writes a genesis stage row (from_stage_id = null). It must NOT
        // surface in the feed, so the deal's creation isn't rendered twice (#4):
        // a brand-new deal has zero stage_change events in its timeline.
        $pipeline = $this->seedSalesPipeline();
        $company = Company::factory()->create();
        $user = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($user, ['*']);

        $dealId = $this->postJson('/api/deals', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'title' => 'Fresh deal',
            'currency' => 'RUB',
        ])->assertCreated()->json('data.id');

        $data = collect($this->getJson("/api/deals/{$dealId}/feed")->assertOk()->json('data'));

        $this->assertCount(
            0,
            $data->where('type', 'stage_change'),
            'the genesis (creation) stage row must not appear as a feed stage_change',
        );
    }

    // ---- QA-2026-07-04: FK field changes surface human-readable names ----

    public function test_owner_and_company_change_surface_names_not_raw_ids_in_feed(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $oldOwner = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Ivan Petrov']);
        $newOwner = User::factory()->create(['role' => Role::Manager, 'full_name' => 'Anna Sidorova']);
        $oldCompany = Company::factory()->create(['name' => 'ООО Ромашка']);
        $newCompany = Company::factory()->create(['name' => 'ЗАО Лютик']);

        $deal = Deal::factory()->forOwner($oldOwner)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $oldCompany->id,
        ]);

        Sanctum::actingAs($oldOwner, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", [
            'owner_user_id' => $newOwner->id,
            'company_id' => $newCompany->id,
        ])->assertOk();

        // The new owner must be able to view their own deal's feed.
        Sanctum::actingAs($newOwner, ['*']);

        $feed = $this->getJson("/api/deals/{$deal->id}/feed?types[]=field_change")->assertOk();
        $rows = collect($feed->json('data'))->keyBy('payload.field');

        $ownerRow = $rows->get('owner_user_id');
        $this->assertNotNull($ownerRow, 'owner_user_id field_change must be present');
        $this->assertSame((string) $oldOwner->id, $ownerRow['payload']['old_value']);
        $this->assertSame((string) $newOwner->id, $ownerRow['payload']['new_value']);
        $this->assertSame('Ivan Petrov', $ownerRow['payload']['old_display']);
        $this->assertSame('Anna Sidorova', $ownerRow['payload']['new_display']);

        $companyRow = $rows->get('company_id');
        $this->assertNotNull($companyRow, 'company_id field_change must be present');
        $this->assertSame('ООО Ромашка', $companyRow['payload']['old_display']);
        $this->assertSame('ЗАО Лютик', $companyRow['payload']['new_display']);
    }

    public function test_deleted_company_falls_back_to_hash_id_in_feed(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $user = User::factory()->create(['role' => Role::Manager]);
        $oldCompany = Company::factory()->create();
        $newCompany = Company::factory()->create();

        $deal = Deal::factory()->forOwner($user)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
            'company_id' => $oldCompany->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/deals/{$deal->id}", ['company_id' => $newCompany->id])->assertOk();

        $oldCompanyId = $oldCompany->id;
        $oldCompany->delete();

        $feed = $this->getJson("/api/deals/{$deal->id}/feed?types[]=field_change")->assertOk();
        $companyRow = collect($feed->json('data'))->firstWhere('payload.field', 'company_id');

        $this->assertNotNull($companyRow);
        $this->assertSame("#{$oldCompanyId}", $companyRow['payload']['old_display']);
    }

    // ---- C9: the feed exposes the activity's REAL status ----

    public function test_feed_activity_item_exposes_real_status(): void
    {
        $user = User::factory()->create(['role' => Role::Manager]);
        $deal = $this->makeDeal($user);

        // A rejected task is closed but NOT done — the FE must render it as
        // "rejected", not as a green "done" reconstructed from is_closed alone.
        Activity::factory()->forDeal($deal)->create([
            'title' => 'Rejected follow-up',
            'status' => ActivityStatus::Rejected->value,
            'is_closed' => true,
        ]);
        // An in_progress task is open — it must read "in_progress", not "new".
        Activity::factory()->forDeal($deal)->create([
            'title' => 'Working call',
            'status' => ActivityStatus::InProgress->value,
            'is_closed' => false,
            'created_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $data = collect($this->getJson("/api/deals/{$deal->id}/feed")->assertOk()->json('data'));

        $rejected = $data->firstWhere('payload.title', 'Rejected follow-up');
        $working = $data->firstWhere('payload.title', 'Working call');

        $this->assertSame('rejected', $rejected['payload']['status']);
        $this->assertTrue($rejected['payload']['is_closed']);

        $this->assertSame('in_progress', $working['payload']['status']);
        $this->assertFalse($working['payload']['is_closed']);
    }
}
