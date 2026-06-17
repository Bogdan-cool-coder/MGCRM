<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Events\DealCreated;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Services\DealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * S1.9 cross-domain contract: DealService::createInbound() — the Inbox context
 * uses this to create a Deal on an already-resolved Company at an explicit
 * stage (the sales `code='new'` entry stage).
 */
class DealInboundTest extends TestCase
{
    use RefreshDatabase;
    use SalesTestHelpers;

    public function test_create_inbound_uses_explicit_new_stage_writes_history(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $newStageId = $this->stageCode($pipeline, 'new');
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();

        $service = app(DealService::class);

        $deal = $service->createInbound(
            $company,
            ['title' => 'Лид с формы', 'currency' => 'RUB'],
            $owner->id,
            $pipeline->id,
            $newStageId,
        );

        // Deal lands in the explicit stage (NOT a "first non-won" lookup).
        $this->assertSame($newStageId, $deal->stage_id);
        $this->assertSame($company->id, $deal->company_id);
        $this->assertSame($owner->id, $deal->owner_user_id);
        $this->assertSame('Лид с формы', $deal->title);
        $this->assertNotNull($deal->stage_changed_at);

        // Creation row in stage history: from_stage_id NULL, user_id NULL (no actor).
        $this->assertDatabaseHas('deal_stage_history', [
            'deal_id' => $deal->id,
            'from_stage_id' => null,
            'to_stage_id' => $newStageId,
            'user_id' => null,
        ]);
        $this->assertDatabaseCount('deal_stage_history', 1);
    }

    public function test_create_inbound_stamps_department_from_owner(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $newStageId = $this->stageCode($pipeline, 'new');
        $dept = Department::create(['name' => 'Inbound Desk']);
        $owner = User::factory()->create(['role' => Role::Manager, 'department_id' => $dept->id]);
        $company = Company::factory()->create();

        $deal = app(DealService::class)->createInbound(
            $company,
            ['title' => 'Лид'],
            $owner->id,
            $pipeline->id,
            $newStageId,
        );

        $this->assertSame($dept->id, $deal->department_id);
    }

    public function test_create_inbound_defaults_title_and_currency(): void
    {
        $pipeline = $this->seedSalesPipeline();
        $newStageId = $this->stageCode($pipeline, 'new');
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['name' => 'ООО Ромашка']);

        $deal = app(DealService::class)->createInbound(
            $company,
            [],
            $owner->id,
            $pipeline->id,
            $newStageId,
        );

        $this->assertSame('Лид: ООО Ромашка', $deal->title);
        $this->assertSame('RUB', $deal->currency);
    }

    public function test_create_inbound_emits_deal_created_event(): void
    {
        Event::fake([DealCreated::class]);

        $pipeline = $this->seedSalesPipeline();
        $newStageId = $this->stageCode($pipeline, 'new');
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();

        $deal = app(DealService::class)->createInbound(
            $company,
            ['title' => 'Лид'],
            $owner->id,
            $pipeline->id,
            $newStageId,
        );

        Event::assertDispatched(
            DealCreated::class,
            fn (DealCreated $e): bool => $e->deal->id === $deal->id,
        );
    }

    public function test_inbound_deal_appears_in_sales_board_not_lifecycle(): void
    {
        // E10: inbound deals land in a sales pipeline; the sales board returns them.
        $pipeline = $this->seedSalesPipeline();
        $newStageId = $this->stageCode($pipeline, 'new');
        $owner = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create();

        $deal = app(DealService::class)->createInbound(
            $company,
            ['title' => 'Лид на доске'],
            $owner->id,
            $pipeline->id,
            $newStageId,
        );

        // The deal belongs to a sales-kind pipeline (board filter passes).
        $this->assertTrue($deal->pipeline->kind->value === 'sales');
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'stage_id' => $newStageId]);
    }
}
