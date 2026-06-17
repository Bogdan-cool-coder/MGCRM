<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Automation\Actions\ChangeOwnerAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeOwnerActionTest extends TestCase
{
    use RefreshDatabase;

    private ChangeOwnerAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ChangeOwnerAction;
    }

    public function test_kind(): void
    {
        $this->assertSame(ActionKind::ChangeOwner, $this->action->kind());
    }

    public function test_round_robin_rotates_through_pool_and_advances_cursor(): void
    {
        $a = User::factory()->role(Role::Manager)->create();
        $b = User::factory()->role(Role::Manager)->create();
        $c = User::factory()->role(Role::Manager)->create();
        $pool = [$a->id, $b->id, $c->id]; // ordered by id

        $automation = PipelineAutomation::factory()->create(['round_robin_cursor' => 0]);
        // Deal owner is an admin so it is NOT in the manager candidate pool.
        $deal = Deal::factory()->create(['owner_user_id' => User::factory()->role(Role::Admin)->create()->id]);
        $config = ['rule' => 'round_robin', 'user_pool_filter' => ['role' => Role::Manager->value]];

        $picks = [];
        for ($i = 0; $i < 4; $i++) {
            $result = $this->action->execute($automation->fresh(), $deal->fresh(), $config);
            $this->assertSame(ActionStatus::Success, $result->status);
            $picks[] = $result->data['new'];
        }

        // Cursor rotates 0,1,2,0 → wraps around.
        $this->assertSame([$pool[0], $pool[1], $pool[2], $pool[0]], $picks);
        $this->assertSame(1, $automation->fresh()->round_robin_cursor);
    }

    public function test_round_robin_syncs_department_from_new_owner(): void
    {
        $dept = Department::factory()->create();
        $newOwner = User::factory()->role(Role::Manager)->create(['department_id' => $dept->id]);

        $automation = PipelineAutomation::factory()->create(['round_robin_cursor' => 0]);
        // Single-candidate pool: the deal owner is an admin (excluded), so the
        // only manager is $newOwner.
        $deal = Deal::factory()->create([
            'department_id' => null,
            'owner_user_id' => User::factory()->role(Role::Admin)->create()->id,
        ]);

        $result = $this->action->execute($automation, $deal, [
            'rule' => 'round_robin',
            'user_pool_filter' => ['role' => Role::Manager->value],
        ]);

        $this->assertSame(ActionStatus::Success, $result->status);
        $this->assertSame($newOwner->id, (int) $deal->fresh()->owner_user_id);
        $this->assertSame($dept->id, (int) $deal->fresh()->department_id);
    }

    public function test_skips_when_pool_empty(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $deal = Deal::factory()->create();

        $result = $this->action->execute($automation, $deal, [
            'rule' => 'round_robin',
            'user_pool_filter' => ['role' => Role::Cfo->value], // nobody
        ]);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_skips_unsupported_rule(): void
    {
        $automation = PipelineAutomation::factory()->create();
        $deal = Deal::factory()->create();

        $result = $this->action->execute($automation, $deal, ['rule' => 'by_country']);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_dry_run_does_not_advance_cursor(): void
    {
        $a = User::factory()->role(Role::Manager)->create();
        $automation = PipelineAutomation::factory()->create(['round_robin_cursor' => 0]);
        $deal = Deal::factory()->create(['owner_user_id' => User::factory()->role(Role::Admin)->create()->id]);

        $preview = $this->action->dryRun($automation, $deal, [
            'rule' => 'round_robin',
            'user_pool_filter' => ['role' => Role::Manager->value],
        ]);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame($a->id, $preview->data['change_owner']['next_owner']);
        $this->assertSame(0, $automation->fresh()->round_robin_cursor);
    }
}
