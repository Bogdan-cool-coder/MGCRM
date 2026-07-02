<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Services\AutomationService;
use App\Domain\Inbox\Models\Form;
use App\Domain\Sales\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AutomationService::purgeAll() — the `automations` category seam of the
 * selective system reset (docs/contracts/system-reset-api-contract.md §1 cat 8,
 * §7 boundary, §10.10).
 *
 * Locks the three things a reviewer must verify for this destructive seam:
 *   - FK delete order: automation_runs (child) go before pipeline_automations
 *     (parent) with no FK violation, deterministic across drivers.
 *   - Returned counter == parent-table (pipeline_automations) rows removed.
 *   - forms (Inbox, product decision P4) survive untouched — the allow-list
 *     never sweeps a foreign table into this category.
 */
class AutomationPurgeAllTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AutomationService
    {
        return app(AutomationService::class);
    }

    public function test_purge_all_deletes_automations_and_their_runs(): void
    {
        $pipeline = Pipeline::factory()->create();

        $a1 = PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id]);
        $a2 = PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id]);

        AutomationRun::factory()->count(3)->create(['automation_id' => $a1->id]);
        AutomationRun::factory()->count(2)->create(['automation_id' => $a2->id]);

        $this->assertSame(2, PipelineAutomation::query()->count());
        $this->assertSame(5, AutomationRun::query()->count());

        $this->service()->purgeAll();

        $this->assertSame(0, PipelineAutomation::query()->count());
        $this->assertSame(0, AutomationRun::query()->count());
    }

    public function test_purge_all_respects_fk_order_children_before_parents(): void
    {
        // FK enforcement ON: if the parent were deleted before its child runs,
        // this would raise a constraint violation. Passing = child-first order.
        DB::statement('PRAGMA foreign_keys = ON');

        $pipeline = Pipeline::factory()->create();
        $automation = PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id]);

        AutomationRun::factory()->count(4)->create(['automation_id' => $automation->id]);

        $this->service()->purgeAll();

        $this->assertSame(0, AutomationRun::query()->count());
        $this->assertSame(0, PipelineAutomation::query()->count());
    }

    public function test_purge_all_returns_parent_table_row_count(): void
    {
        $pipeline = Pipeline::factory()->create();

        // 3 rules, 7 runs — the returned counter is the representative parent
        // count (rules), not runs, matching the reset report semantics (§4/§6.1).
        PipelineAutomation::factory()->count(3)->create(['pipeline_id' => $pipeline->id])
            ->each(fn (PipelineAutomation $a) => AutomationRun::factory()->count(2)->create([
                'automation_id' => $a->id,
            ]));
        AutomationRun::factory()->create([
            'automation_id' => PipelineAutomation::query()->first()->id,
        ]);

        $this->assertSame(3, PipelineAutomation::query()->count());
        $this->assertSame(7, AutomationRun::query()->count());

        $removed = $this->service()->purgeAll();

        $this->assertSame(3, $removed);
    }

    public function test_purge_all_returns_zero_when_nothing_to_delete(): void
    {
        $this->assertSame(0, $this->service()->purgeAll());
    }

    public function test_purge_all_deletes_slot_holding_and_failed_runs_alike(): void
    {
        // The reset is a category wipe, not the retention prune: it removes ALL
        // runs regardless of idempotency-slot status (success/failed/queued).
        $pipeline = Pipeline::factory()->create();
        $automation = PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id]);

        AutomationRun::factory()->status(RunStatus::Success)->create([
            'automation_id' => $automation->id,
            'trigger_event_ts' => now(),
        ]);
        AutomationRun::factory()->status(RunStatus::Failed)->create([
            'automation_id' => $automation->id,
            'trigger_event_ts' => null,
        ]);

        $this->service()->purgeAll();

        $this->assertSame(0, AutomationRun::query()->count());
    }

    public function test_purge_all_leaves_forms_untouched(): void
    {
        // forms belong to Domain\Inbox and are excluded from this category (P4).
        // The Automation seam must never sweep them.
        $pipeline = Pipeline::factory()->create();
        $automation = PipelineAutomation::factory()->create(['pipeline_id' => $pipeline->id]);
        AutomationRun::factory()->create(['automation_id' => $automation->id]);

        Form::factory()->count(2)->create();

        $this->service()->purgeAll();

        $this->assertSame(0, PipelineAutomation::query()->count());
        $this->assertSame(0, AutomationRun::query()->count());
        $this->assertSame(2, Form::query()->count());
    }
}
