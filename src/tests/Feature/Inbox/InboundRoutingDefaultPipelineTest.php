<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Services\InboundRoutingService;
use App\Domain\Sales\Models\Pipeline;
use Database\Seeders\AmoPipelineSeeder;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for the pipeline-default fallback in
 * InboundRoutingService::resolvePipelineStage(). When a channel carries no
 * default_pipeline_id, routing must land in the FIRST ACTIVE sales pipeline by
 * sort_order/id (the canon "MACRO Global"), never the reversibly-archived
 * legacy "Продажи" funnel — same rule as DealService::defaultSalesPipelineId().
 */
class InboundRoutingDefaultPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_fallback_routes_to_active_macro_global_not_archived_prodazhi(): void
    {
        // Seed the legacy funnel first, then the AMO canon which archives it.
        $this->seed(PipelineSeeder::class);
        $this->seed(AmoPipelineSeeder::class);

        $prodazhi = Pipeline::where('name', 'Продажи')->firstOrFail();
        $macroGlobal = Pipeline::where('name', 'MACRO Global')->firstOrFail();
        $this->assertFalse($prodazhi->is_active, 'Продажи must be archived by AmoPipelineSeeder');
        $this->assertTrue($macroGlobal->is_active);
        // Sanity: Продажи sorts BEFORE MACRO Global by id, so an is_active-blind
        // fallback would wrongly pick it.
        $this->assertLessThan($macroGlobal->id, $prodazhi->id);

        $owner = User::factory()->create();

        // Channel WITHOUT a default_pipeline_id → must hit the fallback branch.
        $channel = Channel::factory()->create([
            'kind' => ChannelKind::WebForm,
            'default_owner_id' => $owner->id,
            'default_pipeline_id' => null,
            'default_stage_id' => null,
            'is_active' => true,
        ]);

        $message = InboundMessage::create([
            'channel_id' => $channel->id,
            'external_id' => 'ext-fallback-1',
            'from_identifier' => 'lead@example.com',
            'from_name' => 'Fallback Lead',
            'body' => 'Hello',
            'raw_payload' => [],
        ]);

        $deal = app(InboundRoutingService::class)->route($channel, $message);

        $this->assertNotNull($deal);
        $this->assertSame(
            $macroGlobal->id,
            $deal->pipeline_id,
            'Inbound default fallback must route into the active MACRO Global pipeline',
        );
        $this->assertNotSame($prodazhi->id, $deal->pipeline_id);

        $message->refresh();
        $this->assertSame(RoutingStatus::Routed, $message->routing_status);
        $this->assertSame($deal->id, $message->target_deal_id);
    }
}
