<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/automation-runs — runs journal (M7 P4).
 *
 * Read-only audit view, admin/director-gated. Locks the filters
 * (automation_id / status / action_kind / target / date), newest-first order,
 * denormalised automation name + action_kind, and pagination.
 */
class AutomationRunJournalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Admin]), ['*']);
    }

    public function test_journal_lists_runs_newest_first_with_automation_name(): void
    {
        $this->admin();
        $automation = PipelineAutomation::factory()->create([
            'name' => 'My rule',
            'action_kind' => 'tg_notify',
        ]);

        $older = AutomationRun::factory()->create([
            'automation_id' => $automation->id,
            'created_at' => now()->subHour(),
        ]);
        $newer = AutomationRun::factory()->create([
            'automation_id' => $automation->id,
            'created_at' => now(),
        ]);

        $this->getJson('/api/automation-runs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0.automation_name', 'My rule')
            ->assertJsonPath('data.0.action_kind', 'tg_notify');
    }

    public function test_journal_filters_by_automation_id(): void
    {
        $this->admin();
        $a = PipelineAutomation::factory()->create();
        $b = PipelineAutomation::factory()->create();

        AutomationRun::factory()->count(2)->create(['automation_id' => $a->id]);
        AutomationRun::factory()->create(['automation_id' => $b->id]);

        $this->getJson("/api/automation-runs?automation_id={$a->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_journal_filters_by_status(): void
    {
        $this->admin();
        $automation = PipelineAutomation::factory()->create();

        AutomationRun::factory()->status(RunStatus::Failed)->create(['automation_id' => $automation->id]);
        AutomationRun::factory()->status(RunStatus::Success)->count(2)->create(['automation_id' => $automation->id]);

        $this->getJson('/api/automation-runs?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_journal_filters_by_action_kind_via_parent_automation(): void
    {
        $this->admin();
        $tg = PipelineAutomation::factory()->create(['action_kind' => 'tg_notify']);
        $task = PipelineAutomation::factory()->create(['action_kind' => 'create_task']);

        AutomationRun::factory()->create(['automation_id' => $tg->id]);
        AutomationRun::factory()->count(2)->create(['automation_id' => $task->id]);

        $this->getJson('/api/automation-runs?action_kind=create_task')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_journal_filters_by_target(): void
    {
        $this->admin();
        $automation = PipelineAutomation::factory()->create();

        AutomationRun::factory()->forTarget(555)->create(['automation_id' => $automation->id]);
        AutomationRun::factory()->forTarget(999)->create(['automation_id' => $automation->id]);

        $this->getJson('/api/automation-runs?target_type=deal&target_id=555')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_id', 555);
    }

    public function test_journal_is_paginated(): void
    {
        $this->admin();
        $automation = PipelineAutomation::factory()->create();
        AutomationRun::factory()->count(3)->create(['automation_id' => $automation->id]);

        $this->getJson('/api/automation-runs?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_manager_cannot_read_journal(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Manager]), ['*']);

        $this->getJson('/api/automation-runs')->assertForbidden();
    }
}
