<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit §3.6 MED — M9 department-visibility was activated without a backfill, so
 * pre-existing deals kept a NULL department_id and stayed invisible to the new
 * department scope. This covers both halves of the fix:
 *
 *   1. The one-off backfill migration stamps department_id from the deal owner's
 *      department for every NULL-department deal (idempotent, driver-agnostic).
 *   2. The write paths keep department_id in sync so the hole never re-accumulates:
 *      create() stamps from the owner, and update() re-stamps when the owner changes
 *      WITHOUT a company change (the previously-uncovered leak).
 */
class DealDepartmentBackfillTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    /** Require the backfill migration file and run its up(). */
    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_07_04_110000_backfill_deal_department_from_owner.php'
        );
        $migration->up();
    }

    private function managerIn(Department $dept): User
    {
        return User::factory()->create([
            'role' => Role::Manager,
            'department_id' => $dept->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // 1. Backfill migration
    // ---------------------------------------------------------------------

    public function test_backfill_stamps_department_from_owner_for_null_deals(): void
    {
        $sales = Department::create(['name' => 'Sales']);
        $owner = $this->managerIn($sales);
        $pipeline = $this->seedSalesPipeline();

        // A pre-M9 deal: owner has a department, but the deal's department is NULL.
        $deal = Deal::factory()->create([
            'owner_user_id' => $owner->id,
            'department_id' => null,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        $this->runBackfill();

        $this->assertSame($sales->id, $deal->fresh()->department_id);
    }

    public function test_backfill_leaves_deals_whose_owner_has_no_department_null(): void
    {
        // Owner without a department → nothing to inherit → stays NULL.
        $owner = User::factory()->create(['role' => Role::Manager, 'department_id' => null]);
        $pipeline = $this->seedSalesPipeline();

        $deal = Deal::factory()->create([
            'owner_user_id' => $owner->id,
            'department_id' => null,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        $this->runBackfill();

        $this->assertNull($deal->fresh()->department_id);
    }

    public function test_backfill_does_not_touch_already_stamped_deals(): void
    {
        $sales = Department::create(['name' => 'Sales']);
        $other = Department::create(['name' => 'Other']);
        // Owner is in Sales, but the deal was deliberately stamped to Other.
        $owner = $this->managerIn($sales);
        $pipeline = $this->seedSalesPipeline();

        $deal = Deal::factory()->create([
            'owner_user_id' => $owner->id,
            'department_id' => $other->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        $this->runBackfill();

        // Untouched — only NULL-department rows are backfilled.
        $this->assertSame($other->id, $deal->fresh()->department_id);
    }

    public function test_backfill_is_idempotent_on_rerun(): void
    {
        $sales = Department::create(['name' => 'Sales']);
        $owner = $this->managerIn($sales);
        $pipeline = $this->seedSalesPipeline();

        $deal = Deal::factory()->create([
            'owner_user_id' => $owner->id,
            'department_id' => null,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        $this->runBackfill();
        $this->runBackfill(); // second run is a harmless no-op

        $this->assertSame($sales->id, $deal->fresh()->department_id);
    }

    // ---------------------------------------------------------------------
    // 2. Write-path invariants (the leak that lets the hole re-accumulate)
    // ---------------------------------------------------------------------

    public function test_create_stamps_department_from_owner(): void
    {
        $sales = Department::create(['name' => 'Sales']);
        $owner = $this->managerIn($sales);
        $pipeline = $this->seedSalesPipeline();

        $deal = app(DealService::class)->create([
            'title' => 'New deal',
            'owner_user_id' => $owner->id,
            'company_id' => Company::factory()->create()->id,
            'currency' => 'RUB',
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ], $owner);

        $this->assertSame($sales->id, $deal->department_id);
    }

    public function test_update_restamps_department_when_owner_changes_without_company_change(): void
    {
        $sales = Department::create(['name' => 'Sales']);
        $finance = Department::create(['name' => 'Finance']);
        $ownerA = $this->managerIn($sales);
        $ownerB = $this->managerIn($finance);
        $pipeline = $this->seedSalesPipeline();

        $deal = Deal::factory()->forOwner($ownerA)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);
        $this->assertSame($sales->id, $deal->department_id);

        // Reassign to a manager in a DIFFERENT department, no company change.
        app(DealService::class)->update($deal, ['owner_user_id' => $ownerB->id]);

        // department_id must follow the new owner — otherwise the deal stays pinned
        // to Sales and Finance managers still can't see their own deal.
        $this->assertSame($finance->id, $deal->fresh()->department_id);
    }

    public function test_update_respects_explicit_department_override_on_owner_change(): void
    {
        $sales = Department::create(['name' => 'Sales']);
        $finance = Department::create(['name' => 'Finance']);
        $legal = Department::create(['name' => 'Legal']);
        $ownerA = $this->managerIn($sales);
        $ownerB = $this->managerIn($finance);
        $pipeline = $this->seedSalesPipeline();

        $deal = Deal::factory()->forOwner($ownerA)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $this->stageCode($pipeline, 'new'),
        ]);

        // Caller sets BOTH owner and an explicit department — the explicit value wins.
        app(DealService::class)->update($deal, [
            'owner_user_id' => $ownerB->id,
            'department_id' => $legal->id,
        ]);

        $this->assertSame($legal->id, $deal->fresh()->department_id);
    }
}
