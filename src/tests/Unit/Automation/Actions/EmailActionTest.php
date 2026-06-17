<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Automation\Actions\EmailAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailActionTest extends TestCase
{
    use RefreshDatabase;

    private EmailAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new EmailAction;
    }

    public function test_kind(): void
    {
        $this->assertSame(ActionKind::Email, $this->action->kind());
    }

    public function test_execute_is_forward_compatible_no_op(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, [
            'recipient' => 'owner',
            'subject' => 'Hi',
            'body' => 'Body',
        ]);

        // Never fails — skipped with an explicit "infra pending" marker.
        $this->assertSame(ActionStatus::Skipped, $result->status);
        $this->assertSame('pending', $result->data['email_infra']);
    }

    public function test_dry_run_reports_pending(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action->dryRun($automation, $deal, []);

        $this->assertFalse($preview->wouldExecute);
        $this->assertSame('pending', $preview->data['email_infra']);
    }
}
