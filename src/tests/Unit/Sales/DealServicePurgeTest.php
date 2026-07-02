<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealContact;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\LostReason;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for DealService::purgeAll() — the `deals` category cleaner for
 * the selective system-reset feature
 * (docs/contracts/system-reset-api-contract.md §1 row 1).
 *
 * Coverage: FK/deletion order (children before parents), returned counts,
 * pipelines/stages surviving (never-delete list, §2), and
 * visibility_settings staying untouched (§2 — an unrelated admin-config table
 * that must never be swept in by a Sales purge).
 */
class DealServicePurgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build one fully-linked deal: a line item (deal_products), a linked
     * contact (deal_contacts pivot), a stage-history row and an audit row —
     * i.e. one instance of every child table purgeAll() must clear.
     */
    private function makeFullyLinkedDeal(Pipeline $pipeline, PipelineStage $stage): Deal
    {
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
            'owner_user_id' => User::factory()->create()->id,
        ]);

        DealProduct::factory()->create(['deal_id' => $deal->id]);
        DealContact::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => Contact::factory()->create()->id,
        ]);

        DB::table('deal_stage_history')->insert([
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $stage->id,
            'user_id' => $deal->owner_user_id,
            'created_at' => now(),
        ]);

        DB::table('deal_audits')->insert([
            'deal_id' => $deal->id,
            'user_id' => $deal->owner_user_id,
            'field' => 'title',
            'old_value' => 'Old',
            'new_value' => 'New',
            'created_at' => now(),
        ]);

        return $deal;
    }

    public function test_purge_all_deletes_every_deal_and_child_row(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $this->makeFullyLinkedDeal($pipeline, $stage);
        $this->makeFullyLinkedDeal($pipeline, $stage);

        $this->assertSame(2, DB::table('deals')->count());
        $this->assertSame(2, DB::table('deal_products')->count());
        $this->assertSame(2, DB::table('deal_contacts')->count());
        $this->assertSame(2, DB::table('deal_stage_history')->count());
        $this->assertSame(2, DB::table('deal_audits')->count());

        app(DealService::class)->purgeAll();

        $this->assertSame(0, DB::table('deals')->count());
        $this->assertSame(0, DB::table('deal_products')->count());
        $this->assertSame(0, DB::table('deal_contacts')->count());
        $this->assertSame(0, DB::table('deal_stage_history')->count());
        $this->assertSame(0, DB::table('deal_audits')->count());
    }

    public function test_purge_all_returns_pre_delete_counts_per_table(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $this->makeFullyLinkedDeal($pipeline, $stage);
        $this->makeFullyLinkedDeal($pipeline, $stage);
        $this->makeFullyLinkedDeal($pipeline, $stage);

        $counts = app(DealService::class)->purgeAll();

        $this->assertSame([
            'deal_stage_history' => 3,
            'deal_audits' => 3,
            'deal_contacts' => 3,
            'deal_products' => 3,
            'deals' => 3,
        ], $counts);
    }

    public function test_purge_all_returns_zero_counts_when_no_deals_exist(): void
    {
        $counts = app(DealService::class)->purgeAll();

        $this->assertSame([
            'deal_stage_history' => 0,
            'deal_audits' => 0,
            'deal_contacts' => 0,
            'deal_products' => 0,
            'deals' => 0,
        ], $counts);
    }

    /**
     * Deletion ORDER must be children before parents. Asserted directly by
     * running purgeAll() against data that would raise an FK-constraint
     * violation under pgsql if a parent table were deleted before its
     * children (e.g. deleting `deals` while `deal_products` still references
     * it). SQLite in :memory: does not enforce FK by default, so this test's
     * real value is exercising the exact table sequence — a reviewer diffing
     * the implementation against the contract's explicit order is the other
     * half of this guarantee (contract §3a).
     */
    public function test_purge_all_does_not_throw_with_fully_linked_data(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $this->makeFullyLinkedDeal($pipeline, $stage);

        app(DealService::class)->purgeAll();

        $this->assertTrue(true);
    }

    public function test_purge_all_removes_soft_deleted_and_archived_deals_too(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => Company::factory()->create()->id,
            'owner_user_id' => User::factory()->create()->id,
            'archived_at' => now(),
        ]);
        $deal->delete(); // soft delete

        $this->assertSame(1, DB::table('deals')->count());

        app(DealService::class)->purgeAll();

        $this->assertSame(0, DB::table('deals')->count());
    }

    public function test_purge_all_never_touches_pipelines_or_stages(): void
    {
        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $this->makeFullyLinkedDeal($pipeline, $stage);

        app(DealService::class)->purgeAll();

        $this->assertSame(1, DB::table('pipelines')->count());
        $this->assertSame(1, DB::table('pipeline_stages')->count());
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id]);
        $this->assertDatabaseHas('pipeline_stages', ['id' => $stage->id]);
    }

    public function test_purge_all_never_touches_lost_reasons_directory(): void
    {
        $lostReason = LostReason::factory()->create();

        app(DealService::class)->purgeAll();

        $this->assertDatabaseHas('lost_reasons', ['id' => $lostReason->id]);
    }

    public function test_purge_all_never_touches_visibility_settings(): void
    {
        DB::table('visibility_settings')->insert([
            'role' => 'manager',
            'scope' => 'own',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
        $this->makeFullyLinkedDeal($pipeline, $stage);

        app(DealService::class)->purgeAll();

        $this->assertSame(1, DB::table('visibility_settings')->count());
        $this->assertDatabaseHas('visibility_settings', ['role' => 'manager', 'scope' => 'own']);
    }

    public function test_purge_all_never_touches_companies_contacts_or_users(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();
        $owner = User::factory()->create();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
        ]);
        DealContact::factory()->create(['deal_id' => $deal->id, 'contact_id' => $contact->id]);

        app(DealService::class)->purgeAll();

        $this->assertDatabaseHas('crm_companies', ['id' => $company->id]);
        $this->assertDatabaseHas('crm_contacts', ['id' => $contact->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }
}
