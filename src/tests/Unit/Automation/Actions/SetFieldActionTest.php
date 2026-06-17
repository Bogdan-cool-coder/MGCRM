<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Actions;

use App\Domain\Automation\Actions\SetFieldAction;
use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\ActionStatus;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Crm\Models\CustomFieldDef;
use App\Domain\Crm\Services\CustomFieldService;
use App\Domain\Sales\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetFieldActionTest extends TestCase
{
    use RefreshDatabase;

    private SetFieldAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new SetFieldAction(app(CustomFieldService::class));
    }

    public function test_kind(): void
    {
        $this->assertSame(ActionKind::SetField, $this->action->kind());
    }

    public function test_execute_writes_whitelisted_column(): void
    {
        $deal = Deal::factory()->create(['title' => 'old']);
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, ['field' => 'title', 'value' => 'new title']);

        $this->assertSame(ActionStatus::Success, $result->status);
        $this->assertSame('old', $result->data['old']);
        $this->assertSame('new title', $deal->fresh()->title);
    }

    public function test_execute_skips_non_whitelisted_column(): void
    {
        // owner_user_id is NOT whitelisted and not a custom field — must skip,
        // never patch the column (security boundary).
        $deal = Deal::factory()->create();
        $original = $deal->owner_user_id;
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, ['field' => 'owner_user_id', 'value' => 99999]);

        $this->assertSame(ActionStatus::Skipped, $result->status);
        $this->assertSame($original, $deal->fresh()->owner_user_id);
    }

    public function test_execute_writes_defined_custom_field(): void
    {
        CustomFieldDef::create([
            'entity_scope' => 'deal',
            'code' => 'priority',
            'label' => 'Priority',
            'field_type' => 'text',
            'is_active' => true,
        ]);
        $deal = Deal::factory()->create(['extra_fields' => []]);
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, ['field' => 'priority', 'value' => 'high']);

        $this->assertSame(ActionStatus::Success, $result->status);
        $this->assertTrue($result->data['custom_field']);
        $this->assertSame('high', $deal->fresh()->extra_fields['priority']);
    }

    public function test_execute_skips_unknown_custom_field(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, ['field' => 'ghost', 'value' => 'x']);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_execute_skips_when_field_missing(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $result = $this->action->execute($automation, $deal, ['value' => 'x']);

        $this->assertSame(ActionStatus::Skipped, $result->status);
    }

    public function test_dry_run_previews_whitelisted_field(): void
    {
        $deal = Deal::factory()->create(['title' => 'cur']);
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action->dryRun($automation, $deal, ['field' => 'title', 'value' => 'next']);

        $this->assertTrue($preview->wouldExecute);
        $this->assertSame('cur', $preview->data['set_field']['old']);
        $this->assertSame('next', $preview->data['set_field']['new']);
    }

    public function test_dry_run_wont_for_unknown_field(): void
    {
        $deal = Deal::factory()->create();
        $automation = PipelineAutomation::factory()->create();

        $preview = $this->action->dryRun($automation, $deal, ['field' => 'role', 'value' => 'admin']);

        $this->assertFalse($preview->wouldExecute);
    }
}
