<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Services\ActivityService;
use App\Domain\Automation\Actions\CreateTaskAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTaskActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateTaskAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CreateTaskAction(app(ActivityService::class));
    }

    public function test_kind(): void
    {
        $this->assertSame(ActionKind::CreateTask, $this->action->kind());
    }

    public function test_execute_creates_task_via_activity_service(): void
    {
        // Admin creator => visibility scope All => deal is visible to the gate.
        $admin = User::factory()->role(Role::Admin)->create();
        $owner = User::factory()->create();
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id, 'title' => 'Big deal']);
        $automation = PipelineAutomation::factory()->create(['created_by_user_id' => $admin->id]);

        $result = $this->action->execute($automation, $deal, [
            'title' => 'Follow up on {target_title}',
            'responsible' => 'owner',
            'due_days' => 3,
        ]);

        $this->assertSame(ActionStatus::Success, $result->status);

        $activity = Activity::findOrFail($result->data['activity_id']);
        $this->assertSame('Follow up on Big deal', $activity->title);
        $this->assertSame($owner->id, (int) $activity->responsible_id);
        $this->assertSame($deal->id, (int) $activity->target_id);
        $this->assertNotNull($activity->due_at);
    }

    public function test_execute_assigns_to_chosen_user_with_body(): void
    {
        // The builder folds its assignee picker into responsible="user_id:N" and
        // its description into "body". The created task must honour BOTH — the
        // FE/BE contract drift previously dropped them (unassigned, no body).
        $admin = User::factory()->role(Role::Admin)->create();
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id, 'title' => 'Big deal']);
        $automation = PipelineAutomation::factory()->create(['created_by_user_id' => $admin->id]);

        $result = $this->action->execute($automation, $deal, [
            'title' => 'Prepare proposal',
            'body' => 'Discuss {target_title} terms',
            'responsible' => "user_id:{$assignee->id}",
            'due_days' => 2,
        ]);

        $this->assertSame(ActionStatus::Success, $result->status);

        $activity = Activity::findOrFail($result->data['activity_id']);
        $this->assertSame('Prepare proposal', $activity->title);
        $this->assertSame('Discuss Big deal terms', $activity->body);
        $this->assertSame($assignee->id, (int) $activity->responsible_id);
        $this->assertNotSame($owner->id, (int) $activity->responsible_id);
    }

    public function test_execute_falls_back_to_deal_owner_as_actor(): void
    {
        // No automation creator => the deal owner is used as the acting user.
        // The owner is an admin so the creator-based visibility gate passes.
        $owner = User::factory()->role(Role::Admin)->create();
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id]);
        $automation = PipelineAutomation::factory()->create(['created_by_user_id' => null]);

        $result = $this->action->execute($automation, $deal, ['title' => 'Ping']);

        $this->assertSame(ActionStatus::Success, $result->status);
        $this->assertDatabaseHas('activities', ['id' => $result->data['activity_id'], 'created_by_id' => $owner->id]);
    }

    public function test_dry_run_does_not_write(): void
    {
        $owner = User::factory()->create();
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id, 'title' => 'Q']);
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action->dryRun($automation, $deal, ['title' => 'Call {target_title}', 'due_days' => 1]);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame('Call Q', $preview->data['task']['title']);
        $this->assertSame($owner->id, $preview->data['task']['responsible_id']);
        $this->assertDatabaseCount('activities', 0);
    }
}
