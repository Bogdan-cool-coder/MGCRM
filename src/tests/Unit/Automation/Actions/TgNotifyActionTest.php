<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Automation\Actions\TgNotifyAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Jobs\SendAutomationTelegramJob;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TgNotifyActionTest extends TestCase
{
    use RefreshDatabase;

    private TgNotifyAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new TgNotifyAction;
    }

    public function test_kind(): void
    {
        $this->assertSame(ActionKind::TgNotify, $this->action->kind());
    }

    public function test_execute_queues_send_to_linked_owner(): void
    {
        $owner = User::factory()->create(['telegram_user_id' => '555', 'full_name' => 'Jane']);
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id, 'title' => 'ACME']);
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, [
            'recipient' => 'owner',
            'message' => 'Deal {target_title} owned by {owner_name}',
        ]);

        // Network action: never sent inline — returned as queued with a job factory.
        $this->assertSame(ActionStatus::Queued, $result->status);
        $this->assertSame('555', $result->data['chat_id']);
        $this->assertSame('Deal ACME owned by Jane', $result->data['message']);
        $this->assertNotNull($result->deferredJobFactory);

        $job = ($result->deferredJobFactory)(42);
        $this->assertInstanceOf(SendAutomationTelegramJob::class, $job);
    }

    public function test_execute_resolves_explicit_chat_id(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, [
            'recipient' => 'chat_id:-1009',
            'message' => 'hi',
        ]);

        $this->assertSame(ActionStatus::Queued, $result->status);
        $this->assertSame('-1009', $result->data['chat_id']);
    }

    public function test_execute_skips_when_owner_has_no_telegram(): void
    {
        $owner = User::factory()->create(['telegram_user_id' => null]);
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id]);
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, ['recipient' => 'owner', 'message' => 'hi']);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_execute_skips_empty_message(): void
    {
        $owner = User::factory()->create(['telegram_user_id' => '555']);
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id]);
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, ['recipient' => 'owner', 'message' => '']);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_dry_run_previews_without_side_effect(): void
    {
        $owner = User::factory()->create(['telegram_user_id' => '777', 'full_name' => 'Bob']);
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id, 'title' => 'X']);
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action->dryRun($automation, $deal, ['recipient' => 'owner', 'message' => 'Hello {owner_name}']);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame('777', $preview->data['chat_id']);
        $this->assertSame('Hello Bob', $preview->data['message']);
    }

    public function test_dry_run_wont_for_unlinked_owner(): void
    {
        $owner = User::factory()->create(['telegram_user_id' => null]);
        $deal = Deal::factory()->create(['owner_user_id' => $owner->id]);
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action->dryRun($automation, $deal, ['recipient' => 'owner', 'message' => 'hi']);

        $this->assertFalse($preview->wouldExecute);
    }
}
